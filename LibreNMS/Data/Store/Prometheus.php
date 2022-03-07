<?php
/**
 * Prometheus.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2020 Tony Murray
 * @copyright  2014 Neil Lathwood <https://github.com/laf/ http://www.lathwood.co.uk/fa>
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Data\Store;

use App\Polling\Measure\Measurement;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use LibreNMS\Config;
use LibreNMS\Util\Proxy;
use Log;

class Prometheus extends BaseDatastore
{
    private $client;
    private $job;
    private $base_uri;
    private $default_opts;
    private $enabled;
    private $prefix;
    private $method;
    private $prune_threshold_seconds;
    private $metrics_dir;

    public function __construct(\GuzzleHttp\Client $client)
    {
        parent::__construct();
        $this->client = $client;

        // shared config
        $this->job = Config::get('prometheus.job', 'librenms');
        $this->prefix = Config::get('prometheus.prefix', '');
        if ($this->prefix) {
            $this->prefix = "$this->prefix" . '_';
        }
        $this->method = Config::get('prometheus.method', 'pushgateway');

        // pushgateway config
        $url = Config::get('prometheus.url');
        $this->base_uri = "$url/metrics/job/$job/instance/";
        $this->default_opts = [
            'headers' => ['Content-Type' => 'text/plain'],
        ];
        if ($proxy = Proxy::get($url)) {
            $this->default_opts['proxy'] = $proxy;
        }

        // local config
        $this->prune_threshold_seconds = Config::get('prometheus.prune_threshold_seconds', 300);
        $this->metrics_dir = Config::get('prometheus.metrics_dir', "/opt/librenms/prometheus_metrics/");

        $this->enabled = self::isEnabled();
    }

    public function getName()
    {
        return 'Prometheus';
    }

    public static function isEnabled()
    {
        return Config::get('prometheus.enable', false);
    }

    public function put($device, $measurement, $tags, $fields)
    {
        $stat = Measurement::start('put');
        // skip if needed
        if (! $this->enabled) {
            return;
        }

        if ($this->method == 'local'){
            $this->runLocal($device, $measurement, $tags, $fields);
        }
        else {
            $this->runPushGateway($device, $measurement, $tags, $fields);
        }


        $this->recordStatistic($stat->end());
    }

    private function runPushGateway($device, $measurement, $tags, $fields)
    {
        try {
            $vals = '';
            $promtags = '/measurement/' . $measurement;

            foreach ($fields as $k => $v) {
                if ($v !== null) {
                    $vals .= $this->prefix . "$k $v\n";
                }
            }

            foreach ($tags as $t => $v) {
                if ($v !== null) {
                    $promtags .= (Str::contains($v, '/') ? "/$t@base64/" . base64_encode($v) : "/$t/$v");
                }
            }
            $options = $this->getDefaultOptions();
            $options['body'] = $vals;

            $promurl = $this->base_uri . $device['hostname'] . $promtags;
            if (Config::get('prometheus.attach_sysname', false)) {
                $promurl .= '/sysName/' . $device['sysName'];
            }
            $promurl = str_replace(' ', '-', $promurl); // Prometheus doesn't handle tags with spaces in url

            Log::debug("Prometheus put $promurl: ", [
                'measurement' => $measurement,
                'tags' => $tags,
                'fields' => $fields,
                'vals' => $vals,
            ]);

            $result = $this->client->request('POST', $promurl, $options);

            if ($result->getStatusCode() !== 200) {
                Log::error('Prometheus Error: ' . $result->getReasonPhrase());
            }
        } catch (GuzzleException $e) {
            Log::error('Prometheus Exception: ' . $e->getMessage());
        }
    }

    private function runLocal($device, $measurement, $tags, $fields)
    {
        if ($this->metrics_dir == null){
           die("metrics_dir not set in config.php for prometheus");
        }
        if (!file_exists($this->metrics_dir)){
           die("metrics_dir $this->metrics_dir doesn't exist. You need to create one manually");
        }
        // Build up labels
        $labels = array();
        array_push($labels, "job=\"" . $this->job . "\"");
        array_push($labels, "instance=\"" . $device['hostname'] . "\"");
        array_push($labels, "measurement=\"" . addcslashes($measurement,'\\') . "\"");
        if (Config::get('prometheus.attach_sysname', false)) {
                array_push($labels, "sysName=\"".$device['sysName']."\"");
        }
        foreach ($tags as $tag => $value) {
            if ($value !== null) {
                array_push($labels, "$tag=\"" . addcslashes($value, '\\') . "\"");
            }
        }

        $target_file = $this->metrics_dir . $device['hostname'] . "_" . $measurement . ".prom";
        $group_values = array();
        $current_time = time();


        // Check existing file for metrics and prune old ones
        if (file_exists($target_file)){
            $existing_lines = explode("\n", file_get_contents($target_file));
            foreach ($existing_lines as $line){
                if ($line !== null) {
                    $items = explode(" ", $line);
                    if ($items !== null && count($items) == 3){
                        $group = $items[0];
                        $value = $items[1];
                        $timestamp = $items[2] / 1000;
                        if ($current_time - $timestamp < $this->prune_threshold_seconds){
                            $group_values[$group] = "$value $timestamp";
                       }
                   }
                }
            }
        }

        // Add or replace with the new metrics from this polling session
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                    $group = $this->prefix . $key . "{" . implode(',',$labels) ."}";
                    $group_values[$group] = "$value " . $current_time . "000";
            }
        }

        $lines = '';
        foreach ($group_values as $group => $value){
            $lines .= $group . " $value\n";
        }

        $res = file_put_contents($target_file, $lines, LOCK_EX);

        if ($res === false){
           die("Unable to write to $target_file " . implode(",",error_get_last()));
        }
    }

    private function getDefaultOptions()
    {
        return $this->default_opts;
    }

    /**
     * Checks if the datastore wants rrdtags to be sent when issuing put()
     *
     * @return bool
     */
    public function wantsRrdTags()
    {
        return false;
    }
}
