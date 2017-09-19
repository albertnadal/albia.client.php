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
  $img = file_get_contents('http://albertnadal.cat/wp-content/uploads/2011/08/default_header3.jpg');
  $client->writeData("picture", $img);
});

$client->onConnectError(function(Exception $e){
  print "Connection failed: ".$e->getMessage()."\n";
});

$client->onDisconnect(function(){
  print "Disconnected\n";
});

$client->connect('localhost');

/*
$test = new DeviceRecord();

Requests::register_autoloader();
$request = Requests::get('http://localhost:3001/v1/request-device-token', array('Accept' => 'application/json', 'X-albia-device-key' => 'key1234', 'X-albia-api-key' => 'app1234'));
$jsonObj = json_decode($request->body);
$deviceToken = $jsonObj->token;
print "Device token: ".$deviceToken."\n";

$request = Requests::get('http://localhost:3001/v1/request-namespace', array('Accept' => 'application/json', 'Authorization' => $deviceToken));
$jsonObj = json_decode($request->body);
$namespace = $jsonObj->namespace;
print "Namespace: ".$namespace."\n\n";

$client = new Client(new Version2X('http://localhost:3000', [
    'headers' => [ "Authorization: $deviceToken" ],
    'transport' => 'websocket'
]));

$client->initialize();
$client->of("/v1/$namespace");
$client->emit('write', ['foo' => 'bar']);
$client->close();
*/
?>
