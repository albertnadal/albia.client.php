<?php

require __DIR__ . '/elephant.io/vendor/autoload.php';

use ElephantIO\Client;
use ElephantIO\Engine\SocketIO\Version2X;

$client = new Client(new Version2X('http://localhost:3000', [
    'headers' => [
        'Authorization: MTt0b2tlbjEyMzQ='
    ],
    'transport' => 'websocket'
]));

$client->initialize();
$client->of('/v1/namespace1234');
$client->emit('write', ['foo' => 'bar']);
$client->close();

?>
