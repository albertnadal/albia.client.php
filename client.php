<?php
/*
require 'third_parties/elephant.io/vendor/autoload.php';

require 'third_parties/protobuf/php/vendor/autoload.php';
require 'third_parties/protobuf_generated/DeviceRecord.php';
require 'third_parties/protobuf_generated/DeviceRecord_RecordType.php';
require 'third_parties/protobuf_generated/GPBMetadata/Proto3/Albia.php';
require 'third_parties/protobuf_generated/GPBMetadata/Proto3/Timestamp.php';
*/
require 'src/Client.php';

/*
use ElephantIO\Client;
use ElephantIO\Engine\SocketIO\Version2X;
*/

//use AlbiaSoft\Client;
$client = new Client('app1234', 'key1234');

$client->onConnect(function() use ($client) {
  print "Connected\n";
/*
  while($client->isConnected) {
    if($handle = opendir('./images/')) {
      while((false !== ($entry = readdir($handle))) && ($client->isConnected)) {
          if($entry != "." && $entry != ".." && $entry != "") {
              print "Reading file ./images/$entry...\n";
              $client->writeData("picture", file_get_contents("./images/$entry"));
              unlink("./images/$entry");
              usleep(3000000); // 3s
          }
      }
      closedir($handle);
      usleep(500000); // 500ms
    }
    usleep(500000); // 500ms
  }
*/
});

$client->onConnectError(function(Exception $e) use ($client) {
  print "Connection failed: ".$e->getMessage()."\n";
  print "Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "Reconnectant\n";
  $client->reconnect();
});

$client->onDisconnect(function() use ($client){
  print "Disconnected\n";
  print "Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "Reconnectant\n";
  $client->reconnect();
});

$client->connect('localhost');

?>
