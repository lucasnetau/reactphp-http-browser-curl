<?php

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$client = new Browser();

$file = fopen('php://temp', 'w+');
fwrite($file, json_encode(array(
    'name' => array(
        'first' => 'Alice',
        'name' => 'Smith'
    ),
    'email' => 'alice@example.com'
)));
fseek($file, 0);
$stream = new \React\Stream\ReadableResourceStream($file);

$client->put(
    'https://httpbin.org/put',
    array(
        'Content-Type' => 'application/json'
    ),
    $stream
)->then(function (ResponseInterface $response) {
    var_dump($response->getHeaders(), (string)$response->getBody());
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
