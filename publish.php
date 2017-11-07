#!/usr/bin/php
<?php
use Mosquitto\Client;
use Mosquitto\Message;
require 'database.php';

$client = new Client();
$client->connect('localhost');

$file=popen("/usr/local/bin/rtl_433 -p 52 -R 12 -F json -T 180","r");

if (!$file)
{
	echo "failed to open\n";
	exit;
}

$buffer=fgets($file);
$json=json_decode($buffer);

$file_db=openDB("/var/www/html/weatherstation/weather.db3");

$insert = "INSERT INTO weather (station, metric, value, time) 
		                VALUES (:station, :metric, :value, :time)";
$stmt = $file_db->prepare($insert);

$stmt->bindParam(':station', $station);
$stmt->bindParam(':metric', $metric);
$stmt->bindParam(':value', $mvalue);
$stmt->bindParam(':time', $time);

$station="UV800";
$metric="uv";
$mvalue=$json->uv;
$batt=$json->battery;
$time=time();

$stmt->execute();

$client->publish("sensors/ir", $mvalue);
$client->disconnect();

$insert = "INSERT INTO weather (station, metric, value, time) 
                        VALUES (:station, :metric, :value, :time)";
$stmt = $file_db->prepare($insert);

$stmt->bindParam(':station', $station);
$stmt->bindParam(':metric', $metric);
$stmt->bindParam(':value', $mvalue);
$stmt->bindParam(':time', $time);

$station="UV800";
$metric="battery";
$mvalue=$json->battery;
$time=time();

$stmt->execute();


stream_set_blocking ( $file , false);
pclose ($file);

?>

