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

        $url = Config::get('prometheus.url');
        $job = Config::get('prometheus.job', 'librenms');
	$push_frequency = Config::get('prometheus.push_frequency',30);
        $this->prefix = Config::get('prometheus.prefix', '');
        if ($this->prefix) {
            $this->prefix = "$this->prefix" . '_';
        }

        $this->default_opts = [
            'headers' => ['Content-Type' => 'text/plain'],
        ];
        if ($proxy = Proxy::get($url)) {
            $this->default_opts['proxy'] = $proxy;
        }

        $this->enabled = self::isEnabled();
  
  	$this->group_values = array(); #new
        $this->base_uri = "$url/metrics/job/$job";
        $this->last_pushed_time = time();
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

        try {
            $labels = array(); #new
            foreach ($tags as $t => $v) {
                if ($v !== null) {
                    array_push($labels, "$t=\"" . addcslashes($v, '\\') . "\""); # new
                }
            }

            $options = $this->getDefaultOptions();

            array_push($labels, "instance=\"" . $device['hostname'] . "\""); # new
            array_push($labels, "measurement=\"" . addcslashes($measurement,'\\') . "\""); # new
            if (Config::get('prometheus.attach_sysname', false)) {
                    array_push($labels, "sysName=\"".$device['sysName']."\""); #new
            }

           
            # Update the in memory values for each grouping 
            foreach ($fields as $k => $v) {
                if ($v !== null) {
                        $group = $this->prefix . $k . "{" . implode(',',$labels) ."}";
                        $this->group_values[$group] = $v;
                }
            }

            # Push out all existing groupings based on time interval
            if (time() - $this->last_pushed_time > $this->push_frequency){
                $lines = '';
                foreach ($this->group_values as $g => $v){
                      $lines .= $g . " $v\n";
                }
                $options['body'] = $lines;
                  
	        $temp_time_start = time();
                $result= $this->client->request('PUT', $this->base_uri, $options);
	        $time_taken = time() - $temp_time_start;
	        Log::info("Took $time_taken seconds to do push request for group size " . sizeof($this->group_values));

                if ($result->getStatusCode() !== 200) {
                    Log::error('Prometheus Error: ' . $result->getReasonPhrase());
                }
                $this->last_pushed_time = time();
	        Log::info("Batch of metrics of length " . sizeof($this->group_values) . " pushed to $this->base_uri_new at time $this->last_pushed_time");
                $this->group_values = array();
            }

            $this->recordStatistic($stat->end());


        } catch (GuzzleException $e) {
            Log::error('Prometheus Exception: ' . $e->getMessage());
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
