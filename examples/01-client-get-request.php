<?php

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$client = new Browser();

$url = isset($argv[1]) ? $argv[1] : 'http://google.com';

$client->get($url)->then(function (ResponseInterface $response) {
    var_dump($response->getHeaders(), (string)$response->getBody());
}, function (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});