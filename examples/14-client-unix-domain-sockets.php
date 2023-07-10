<?php

use Psr\Http\Message\ResponseInterface;
use EdgeTelemetrics\React\Http\Browser;
use RingCentral\Psr7;

require __DIR__ . '/../vendor/autoload.php';

$browser = new Browser([CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']);

// demo fetching HTTP headers (or bail out otherwise)
$browser->get('http://localhost/info')->then(function (ResponseInterface $response) {
    echo Psr7\str($response);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
