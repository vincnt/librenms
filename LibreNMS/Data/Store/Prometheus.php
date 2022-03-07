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
    private $base_uri;
    private $default_opts;
    private $enabled;
    private $prefix;
    private $group_values; #new

    public function __construct(\GuzzleHttp\Client $client)
    {
        parent::__construct();
        $this->client = $client;

        $job = Config::get('prometheus.job', 'librenms');
        $this->prefix = Config::get('prometheus.prefix', '');
        if ($this->prefix) {
            $this->prefix = "$this->prefix" . '_';
        }
        $prune_threshold_seconds = Config::get('prometheus.prune_threshold_seconds', 300);

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

        $labels = array();
        foreach ($tags as $t => $v) {
            if ($v !== null) {
                array_push($labels, "$t=\"" . addcslashes($v, '\\') . "\"");
            }
        }

        array_push($labels, "job=\"" . $this->job . "\"");
        array_push($labels, "instance=\"" . $device['hostname'] . "\"");
        array_push($labels, "measurement=\"" . addcslashes($measurement,'\\') . "\"");
        if (Config::get('prometheus.attach_sysname', false)) {
                array_push($labels, "sysName=\"".$device['sysName']."\"");
        }

        $target_file = "/opt/librenms/prometheus_metrics/" . $device['hostname'] . "_" . $measurement . ".prom";
        $group_values = array();
        // Check existing file for metrics and prune old ones
        if (file_exists($target_file)){
            $existing_lines = explode("\n", file_get_contents($target_file));
            $prune_threshold_seconds = 300300300;
            foreach ($existing_lines as $v){
                if ($v !== null) {
                    $items = explode(" ", $v);
                    if (count($items)==3 && time() - $items[2]/1000 < $prune_threshold_seconds){
                        $group_values[$items[0]] = $items[1] . " " . $items[2];
                    }
                }
            }
        }
        // Add or replace with the new metrics from this polling session
        foreach ($fields as $k => $v) {
            if ($v !== null) {
                    $group = $this->prefix . $k . "{" . implode(',',$labels) ."}";
                    $group_values[$group] = "$v " . time() . "000";
            }
        }

        $lines = '';
        foreach ($group_values as $g => $v){
            $lines .= $g . " $v\n";
        }

        $res = file_put_contents($target_file, $lines);
        Log::info("Res: $res for writing metrics to $target_file");

        $this->recordStatistic($stat->end());
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
