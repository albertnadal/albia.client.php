<?php

require_once 'src/DeviceClient.php';

$client = new DeviceClient('app1234', 'key1234');

$client->onConnect(function () use ($client) {
    print "Connected\n";
});

$client->onConnectError(function (Exception $e) use ($client) {
    print "onConnectError::Connection failed: ".$e->getMessage()."\n";
    print "onConnectError::Reconnecting in 10 seconds...\n";
    usleep(10000000); // 10s
    print "onConnectError::Reconnecting\n";
    $client->reconnect();
});

$client->onDisconnect(function () use ($client) {
    print "onDisconnect::Disconnected\n";
    print "onDisconnect::Reconnecting in 10 seconds...\n";
    usleep(10000000); // 10s
    print "onDisconnect::Reconnecting\n";
    $client->reconnect();
});

$client->connect('maduixa.lafruitera.com');

while (true) {
    if ($handle = opendir('/Users/albert/albiasoft/images')) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && $entry != "") {
                $filename = "/Users/albert/albiasoft/images/$entry";
                print "Reading file $filename...\n";
                $file_timestamp = filemtime($filename);
                $record_timestamp = new DeviceTimestamp($file_timestamp);

                if(!$success = $client->writeData("picture", file_get_contents($filename), $record_timestamp)) {
                    print "Error: Invalid file size.\n";
                }
                unlink($filename);
            }
        }
        closedir($handle);
        usleep(500000); // 500ms
    }

    usleep(500000); // 500ms
}
