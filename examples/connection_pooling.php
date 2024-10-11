<?php declare(strict_types=1);

use EdgeTelemetrics\React\Http\Browser;
use function React\Async\await;

include __DIR__ . '/../vendor/autoload.php';

$browser = new Browser([
    CURLOPT_TIMEOUT => 20,

]);

React\Async\parallel([
    function () use ($browser) {
        return $browser->get("https://www.google.com");
    },
    function () use ($browser) {
        return $browser->get("https://www.google.com");
    },
    ]
)->then(function (array $results) {
    foreach ($results as $index => $response) {
        echo "parallel $index: " . json_encode($response->getHeader('X-Connection'), JSON_PRETTY_PRINT) . "\n";
    }
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

React\Async\series([
        function () use ($browser) {
            return $browser->get("https://www.google.com");
        },
        function () use ($browser) {
            return $browser->get("https://www.google.com");
        },
    ]
)->then(function (array $results) {
    foreach ($results as $index => $response) {
        echo "series $index: " . json_encode($response->getHeader('X-Connection'), JSON_PRETTY_PRINT) . "\n";
    }
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});


//await($browser->request('/'));
