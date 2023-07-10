<?php

// a) simple 1 MB upload benchmark against public HTTP endpoint
// $ php examples/92-client-benchmark-upload.php http://httpbin.org/post 1
//
// b) local 10 GB upload benchmark against localhost address to avoid network overhead
//
// b1) first run example HTTP server:
// $ php examples/63-server-streaming-request.php 8080
//
// b2) run HTTP client sending a 10 GB upload
// $ php examples/92-client-benchmark-upload.php http://localhost:8080/ 10000

use Psr\Http\Message\ResponseInterface;
use EdgeTelemetrics\React\Http\Browser;

ini_set('memory_limit',-1);

require __DIR__ . '/../vendor/autoload.php';

if (extension_loaded('xdebug')) {
    echo 'NOTICE: The "xdebug" extension is loaded, this has a major impact on performance.' . PHP_EOL;
}

$client = new Browser();

$url = isset($argv[1]) ? $argv[1] : 'http://httpbin.org/post';
$n = isset($argv[2]) ? $argv[2] : 10;

echo 'POSTing ' . $n . ' MB to ' . $url . PHP_EOL;

$string = str_repeat('x', 1000000 * $n);
$start = microtime(true);

$client->post($url, array('Content-Length' => $n * 1000000), $string)->then(function (ResponseInterface $response) use ($start,$n) {
    $now = microtime(true);

    printf("\r%d bytes in %0.3fs => %.1f MB/s\n", $n * 1000000, $now - $start, ($n * 1000000) / ($now - $start) / 1000000);
    echo rtrim(preg_replace('/x{5,}/','x…', (string) $response->getBody()), PHP_EOL) . PHP_EOL;
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
