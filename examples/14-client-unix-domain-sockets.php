<?php

use Psr\Http\Message\ResponseInterface;
use EdgeTelemetrics\React\Http\Browser;

require __DIR__ . '/../vendor/autoload.php';

$browser = new Browser([CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']);

// demo fetching HTTP headers (or bail out otherwise)
$browser->get('http://localhost/info')->then(function (ResponseInterface $response) {
    echo (string)$response->getBody();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
