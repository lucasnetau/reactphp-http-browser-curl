# reactphp-http-browser-curl
Implementation of an Async HTTP client using CURL.

*** NOTE *** This is a work in progress, Not 100% compatible replacement for ReactPHP Browser

## Why not use package react/http Browser?
Using cURL allows for HTTP/2+3, connection pooling (with keep-alive), and the extraction of timing data for the requests. This functionality is not available though the ReactPHP Browser implementation

## Requirements

The package is compatible with PHP 8.2+ and requires the cURL extension and [react/event-loop](https://github.com/reactphp/http) library.

## Installation

You can add the library as project dependency using [Composer](https://getcomposer.org/):

```sh
composer require edgetelemetrics/reactphp-http-browser-curl
```

## Examples
See [/examples](/examples) directory. Examples based on examples from reactphp/http under MIT License

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

### Connection Reuse
Each instance of Browser shares a Connection pool, DNS cache, SSL cache, and Cookie Jar. An example of this can be seen in [/examples/connection_pooling.php](/examples/connection_pooling.php) script.

## Connection Metadata
### Connection Timing
The request/response timing is provided though the header '`ServerTiming`' in the Response object.

Each timing point is defined as `<timing point>;dur=<duration in second>`

* namelookup_time
* connect_time
* appconnect_time
* pretransfer_time
* redirect_time
* starttransfer_time
* total_time

### Connection Details
Additional request/response metadata is provided though the header '`X-Connection`' in the Response object.

Key/Value pairs are as follows:

* effective_url=`<final url after any redirects>`
* connection;count=`<number of connections opened during the request, 0 if existing connection reused>`
* redirect;count=`<number of redirects followed>`
* upload;size=`<bytes sent in request(headers+body) including redirects>`;speed=`<overall bytes per second upload>`
* download;size=`<bytes received in response(headers+body) including redirects>`;speed=`<overall bytes per second download>`

## License

MIT, see [LICENSE file](LICENSE).

### Contributing

Bug reports (and small patches) can be submitted via the [issue tracker](https://github.com/lucasnetau/reactphp-http-browser-curl/issues). Forking the repository and submitting a Pull Request is preferred for substantial patches.
