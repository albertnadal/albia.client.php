<?php

require 'third_parties/elephant.io/vendor/autoload.php';
require 'third_parties/Requests/library/Requests.php';
require 'third_parties/protobuf/php/vendor/autoload.php';
require 'third_parties/protobuf_generated/DeviceRecord.php';
require 'third_parties/protobuf_generated/DeviceRecord_RecordType.php';
require 'third_parties/protobuf_generated/GPBMetadata/Proto3/Albia.php';
require 'third_parties/protobuf_generated/GPBMetadata/Proto3/Timestamp.php';

use ElephantIO\Client;
use ElephantIO\Engine\SocketIO\Version2X;

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

?>
