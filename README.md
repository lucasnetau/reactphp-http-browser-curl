# reactphp-http-browser-curl
Implementation of an Async HTTP client using CURL.

*** NOTE *** This is a work in progress, Not 100% compatiable replacement for ReactPHP Browser

## Why not use package react/http Browser?
Using cURL allows for HTTP/2+3, and the extraction of timing data for the requests. This functionality is not available though the ReactPHP Browser implementation

## Requirements

The package is compatible with PHP 8.0+ and requires the cURL extension and [react/event-loop](https://github.com/reactphp/http) library.

## Installation

You can add the library as project dependency using [Composer](https://getcomposer.org/):

```sh
composer require edgetelemetrics/reactphp-http-browser-curl
```

## Examples
See [/examples](/examples) directory

## Timing
Request timing values are returned in the PSR7 Response object headers under the key [Server-Timing](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Server-Timing)

## Configuration
The Browser can be configured with standard CURLOPT_* parameters given via the constructor.

```php
$browser = new Browser([
    CURLOPT_TIMEOUT => 20,
    CURLOPT_DOH_URL, 'https://1.1.1.1/dns-query',
    CURLOPT_DNS_SERVERS => '1.1.1.1',
]);
```

## License

MIT, see [LICENSE file](LICENSE).

### Contributing

Bug reports (and small patches) can be submitted via the [issue tracker](https://github.com/lucasnetau/reactphp-http-browser-curl/issues). Forking the repository and submitting a Pull Request is preferred for substantial patches.
