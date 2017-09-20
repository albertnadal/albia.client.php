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

  while(1) {
    if($handle = opendir('./images/')) {
      while(false !== ($entry = readdir($handle))) {
          if($entry != "." && $entry != ".." && $entry != "") {
              print "Reading file ./images/$entry...\n";
              $client->writeData("picture", file_get_contents("./images/$entry"));
              unlink("./images/$entry");
          }
      }
      closedir($handle);
      sleep(1);
    }
    sleep(1);
  }

});

$client->onConnectError(function(Exception $e){
  print "Connection failed: ".$e->getMessage()."\n";
});

$client->onDisconnect(function() use ($client){
  print "Disconnected\n";
  print "Reconnectant\n";
  $client->connect('localhost');
});

$client->connect('localhost');

?>
