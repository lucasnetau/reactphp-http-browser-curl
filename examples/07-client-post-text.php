<?php

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$client = new Browser();

$data = 'hello world';

$client->post(
    'https://httpbin.org/post',
    [],
    json_encode($data)
)->then(function (ResponseInterface $response) {
    echo (string)$response->getBody();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
