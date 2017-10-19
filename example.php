<?php

require_once 'src/DeviceClient.php';

$client = new DeviceClient('app1234', 'key1234');

$client->onConnect(function() use ($client) {
  print "Connected\n";
});

$client->onConnectError(function(Exception $e) use ($client) {
  print "onConnectError::Connection failed: ".$e->getMessage()."\n";
  print "onConnectError::Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "onConnectError::Reconnecting\n";
  $client->reconnect();
});

$client->onDisconnect(function() use ($client){
  print "onDisconnect::Disconnected\n";
  print "onDisconnect::Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "onDisconnect::Reconnecting\n";
  $client->reconnect();
});

$client->connect('maduixa.lafruitera.com');

while(true) {
  if($handle = opendir('/Users/albert/albiasoft/images')) {
    while(false !== ($entry = readdir($handle))) {
        if($entry != "." && $entry != ".." && $entry != "") {
            print "Reading file /Users/albert/albiasoft/images/$entry...\n";
            if(!$success = $client->writeData("picture", file_get_contents("/Users/albert/albiasoft/images/$entry"))) {
              print "Error: Invalid file size.\n";
            }
            unlink("/home/pi/camera/$entry");
        }
    }
    closedir($handle);
    usleep(500000); // 500ms
  }

  usleep(500000); // 500ms
}

?>
