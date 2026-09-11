# reactphp-http-browser-curl
Implementation of an Async HTTP client using CURL.

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
    CURLOPT_DOH_URL => 'https://1.1.1.1/dns-query',
    CURLOPT_DNS_SERVERS => '1.1.1.1',
]);
```

### Request methods

`get()`, `head()`, `post()`, `put()`, `patch()`, `delete()` and `options()` are provided as convenience methods. `request($method, $url, $headers, $body)` and `requestStreaming()` accept any valid HTTP method: `TRACE`, `QUERY`, WebDAV methods such as `PROPFIND`/`MKCOL`, and any other RFC 7230 token, all sent verbatim. `HEAD` and `TRACE` never send a request body (RFC 9110). `CONNECT` is rejected with an `InvalidArgumentException`, as it creates a tunnel rather than a request/response exchange.

The Browser also implements PSR-18 `Psr\Http\Client\ClientInterface`, so it can be used where a synchronous PSR-18 client is expected:

```php
$request = new GuzzleHttp\Psr7\Request('QUERY', $url, [], $body);
$response = $browser->sendRequest($request); // blocks by driving the event loop
```

Transport failures throw `EdgeTelemetrics\React\Http\Io\NetworkException` and invalid requests throw `EdgeTelemetrics\React\Http\Io\RequestException`; 4xx/5xx responses are returned rather than thrown.

### Security
Only `http://` and `https://` request URLs are accepted; any other scheme is rejected with an `InvalidArgumentException`. As a defence in depth, `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` default to `CURLPROTO_HTTP | CURLPROTO_HTTPS`, so redirects to schemes such as `file://` are blocked as well. Passing your own `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` to the constructor overrides the cURL-level restriction (the request URL itself must still be HTTP(S)).

`withBase()` is a convenience for resolving relative URLs, not a host allow-list: absolute and protocol-relative URLs replace the base entirely (matching react/http). Allow-list hosts yourself if request URLs can come from untrusted input.

### Timeouts
Requests use PHP's `default_socket_timeout` (60 seconds by default) when no explicit timeout is set. Use `$browser->withTimeout(5)` for a custom value, `$browser->withTimeout(true)` to re-enable the default, or `$browser->withTimeout(false)` to disable timeouts entirely. A `CURLOPT_TIMEOUT` / `CURLOPT_TIMEOUT_MS` passed to the constructor takes precedence over the default.

### Response compression
Response bodies are returned exactly as sent; no `Accept-Encoding` header is sent by default. To let cURL negotiate and transparently decode compressed responses, pass `CURLOPT_ACCEPT_ENCODING` in the constructor:

```php
$browser = new Browser([CURLOPT_ACCEPT_ENCODING => '']);
```

Note that cURL decodes the body but the response headers still describe the compressed wire format (`Content-Encoding`, `Content-Length`), so the `Content-Length` header and the streaming body's `getSize()` may not match the decoded body length.

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
