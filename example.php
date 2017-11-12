<?php

require_once 'src/DeviceClient.php';

$client = new DeviceClient('app1234', 'key1234', 'localhost'/*'maduixa.lafruitera.com'*/);

$client->onConnect(function () use ($client) {
    print "Connected\n";
    emitEvents($client);
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

$client->onReceiveEvent(function (DeviceEvent $event) use ($client) {
    print "Event received with data: (".$event->data.")\n";
});

$client->connect();

function emitEvents($client)
{
  sleep(30);
  $deviceId = $client->getDeviceIdWithDeviceKeySync("key1234"); //"clau1234");

  if ($deviceId == false) {
    print "Error when retrieving device Id\n";
  } else {
    print "Sending event to device with id $deviceId\n";
    $client->emitEvent("testAction", $deviceId, 1);
    $client->emitEvent("testAction", $deviceId, 2);
    $client->emitEvent("testAction", $deviceId, 3);
    $client->emitEvent("testAction", $deviceId, 4);
    $client->emitEvent("testAction", $deviceId, 5);
  }

}

while (true) {
    sleep(1);
    print ".";
}

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
