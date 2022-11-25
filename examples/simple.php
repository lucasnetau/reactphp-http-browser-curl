<?php declare(strict_types=1);

use EdgeTelemetrics\React\Http\Browser;

include __DIR__ . '/../vendor/autoload.php';

$browser = new Browser([
    CURLOPT_TIMEOUT => 20,
    //CURLOPT_DOH_URL, 'https://1.1.1.1/dns-query',
    //CURLOPT_DNS_SERVERS => '1.1.1.1',
]);

$browser->get("https://raw.githubusercontent.com/lucasnetau/reactphp-http-browser-curl/main/LICENSE")->then(function($response) use($browser) {
    /** @var \Psr\Http\Message\ResponseInterface $response */
    echo $response->getStatusCode() . " " . $response->getReasonPhrase() . PHP_EOL;
    print_r($response->getHeaders());
    print_r((string)$response->getBody());
}, function($ex) {
    echo 'Download failed: ' . $ex->getMessage() . PHP_EOL;
});