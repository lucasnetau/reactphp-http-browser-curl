<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use CurlMultiHandle;
use EdgeTelemetrics\React\Http\Io\NetworkException;
use EdgeTelemetrics\React\Http\Io\RequestException;
use EdgeTelemetrics\React\Http\Io\UploadBodyStream;
use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use React\Async;
use React\EventLoop;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\ResponseException;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

use React\Stream\ThroughStream;
use React\Http\Message\Uri;
use Throwable;
use ValueError;
use function array_change_key_case;
use function array_key_exists;
use function count;
use function curl_error;
use function curl_getinfo;
use function curl_init;
use function curl_multi_add_handle;
use function curl_multi_close;
use function curl_multi_exec;
use function curl_multi_info_read;
use function curl_multi_init;
use function curl_multi_select;
use function curl_multi_strerror;
use function curl_pause;
use function curl_setopt;
use function curl_setopt_array;
use function curl_share_init;
use function curl_share_setopt;
use function curl_strerror;
use function defined;
use function explode;
use function filter_var;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_int;
use function is_resource;
use function min;
use function ord;
use function preg_match;
use function preg_split;
use function property_exists;
use function rewind;
use function round;
use function str_contains;
use function stream_set_blocking;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

class Browser implements ClientInterface {
    /** Largest response body prefix kept in memory before spilling to a temporary file */
    private const MAX_BUFFERED_MEMORY = 16777216; //16 MiB

    /** Poll interval while transfers are in flight and socket data is available/expected */
    private const ACTIVE_TICK_INTERVAL = 0.001; //1 ms

    /** Consecutive idle ticks (≈ milliseconds) before the idle poll interval starts doubling */
    private const IDLE_TICKS_BEFORE_BACKOFF = 50;

    /** Upper bound for the idle poll interval: 20 ms ≈ 50 wake-ups/s while waiting on the network */
    private const MAX_IDLE_TICK_INTERVAL = 0.02;

    /**
     * cURL options whose behaviour the transport itself relies on. Setting them from user
     * options would corrupt response routing (bodies/headers going to unexpected places),
     * so they are rejected in the constructor instead of being silently ignored/overridden.
     */
    private const RESERVED_CURL_OPTIONS = [
        CURLOPT_FILE => 'CURLOPT_FILE',
        CURLOPT_WRITEHEADER => 'CURLOPT_WRITEHEADER',
        CURLOPT_HEADERFUNCTION => 'CURLOPT_HEADERFUNCTION',
        CURLOPT_WRITEFUNCTION => 'CURLOPT_WRITEFUNCTION',
        CURLOPT_READFUNCTION => 'CURLOPT_READFUNCTION',
        CURLOPT_INFILE => 'CURLOPT_INFILE',
        CURLOPT_XFERINFOFUNCTION => 'CURLOPT_XFERINFOFUNCTION',
        CURLOPT_PROGRESSFUNCTION => 'CURLOPT_PROGRESSFUNCTION',
    ];

    /**
     * libcurl ABI values for protocol-version constants missing from older PHP/libcurl
     * builds (CURL_HTTP_VERSION_3/_3ONLY are only defined with PHP 8.3+).
     */
    private const HTTP2_TLS = 4;
    private const HTTP2_PRIOR_KNOWLEDGE = 5;
    private const HTTP3 = 30;
    private const HTTP3_ONLY = 31;

    /**
     * libcurl ABI values for CURLOPT_PREREQFUNCTION / CURL_PREREQFUNC_* (libcurl 7.80+).
     * PHP only exposes the constants as of 8.4, so they are duplicated here.
     */
    private const PREREQFUNCTION = 20312;
    private const PREREQFUNC_OK = 0;
    private const PREREQFUNC_ABORT = 1;

    protected bool $disableCurlCache = false;

    /** @var array Options to disable as much of cURL cache as possible */
    const NO_CACHE_OPTIONS = [
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_DNS_CACHE_TIMEOUT => 1,
    ];

    const DEFAULT_CURL_OPTIONS = [
        CURLOPT_HEADER => false, //We will write headers out to a separate file
        CURLOPT_CERTINFO => true,
        CURLOPT_TCP_NODELAY => true,
        //Larger download buffer: fewer curl_multi_exec() iterations per byte. Since libcurl 8.7.0
        //the receive buffer is shared by all handles in a shared multi handle; on older versions it
        //is allocated per active handle, so this costs up to 256 KiB per concurrent download.
        CURLOPT_BUFFERSIZE => 262144, //256 KiB
        //libcurl caps the upload buffer at CURL_MAX_UPLOAD_SIZE (2 MiB) and treats the option
        //as a request, not an order; set the maximum explicitly instead of relying on clamping
        CURLOPT_UPLOAD_BUFFERSIZE => 2097152, //2 MiB
        //secure default: never speak non-HTTP protocols, including on redirects. Pass your own
        //CURLOPT_PROTOCOLS / CURLOPT_REDIR_PROTOCOLS to opt out (request() still only allows HTTP(S))
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        //CURLOPT_VERBOSE => true,
        //CURLINFO_HEADER_OUT => true,
        //CURLOPT_HSTS_ENABLE => true, //PHP8.2
    ];

    protected array $defaultHeaders = [
        'User-Agent' => 'EdgeTelemetricsBrowser/1',
    ];

    /**
     * Request state shared with all clones derived via with*()/requestStreaming()
     */
    private BrowserState $state;

    private bool $streaming = false;

    /**
     * Use one shared CurlMultiHandle for all in-flight requests instead of one per request.
     * Enabled by default; see withSharedMultiHandle() to opt out.
     */
    private bool $sharedMulti = true;

    private bool $followRedirects = true;

    private null|float|int $timeout = null;

    private int|null $maxRedirects = 20;

    private $baseUrl;

    private int $maximumSize = 16777216; // 16 MiB = 2^24 bytes;

    private bool $obeySuccessCode = true;

    private bool $ssrfProtection = false;

    private int $httpVersion = CURL_HTTP_VERSION_NONE;
    private \CurlShareHandle $curlShare;

    /**
     * @param EventLoop\LoopInterface|null $loop
     * @param array $options
     */
    public function __construct(protected array $options = [], protected ?EventLoop\LoopInterface $loop = null) {
        $this->assertValidOptions($options);

        if (!isset($this->loop)) {
            $this->loop = EventLoop\Loop::get();
        }

        $this->state = new BrowserState();

        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        $this->curlShare = $share;
    }

    /**
     * Reject constructor options that would fight with the transport's own cURL handling.
     *
     * @param array<int, mixed> $options
     */
    private function assertValidOptions(array $options) : void {
        foreach ($options as $option => $value) {
            if ($value !== null && isset(self::RESERVED_CURL_OPTIONS[$option])) {
                throw new \InvalidArgumentException(
                    self::RESERVED_CURL_OPTIONS[$option] . ' is managed by the transport and cannot be configured on the Browser'
                );
            }
        }

        $headerOption = $options[CURLOPT_HEADER] ?? false;
        if ($headerOption !== false && $headerOption !== 0) {
            throw new \InvalidArgumentException('CURLOPT_HEADER must be false: response headers are parsed by the transport');
        }

        if (array_key_exists(CURLOPT_POSTFIELDS, $options)) {
            throw new \InvalidArgumentException('CURLOPT_POSTFIELDS is managed by the transport; pass the body to request() instead');
        }
    }

    public function isIdle() : bool {
        return count($this->state->inProgress) === 0;
    }

    public function head(string $url, array $headers = []) : PromiseInterface {
        return $this->request('HEAD',$url,$headers);
    }

    public function get(string $url, array $headers = []) : PromiseInterface {
        return $this->request('GET',$url,$headers);
    }

    public function post(string $url, array $headers = [], $body = '') : PromiseInterface {
        return $this->request('POST',$url,$headers, $body);
    }

    public function put(string $url, array $headers = [], $body = '') : PromiseInterface {
        return $this->request('PUT',$url,$headers, $body);
    }

    public function options(string $url, array $headers = []) : PromiseInterface {
        return $this->request('OPTIONS',$url,$headers);
    }

    public function patch(string $url, array $headers = [], $body = '') : PromiseInterface {
        return $this->request('PATCH',$url,$headers, $body);
    }

    public function delete(string $url, array $headers = [], $body = '') : PromiseInterface {
        return $this->request('DELETE',$url,$headers, $body);
    }

    /**
     * Cache of declared property defaults, keyed by "<class>::<property>".
     *
     * @var array<string, mixed>
     */
    private static array $optionDefaults = [];

    protected function withOptions(array $options = []) : self {
        $browser = clone $this;
        foreach ($options as $name => $value) {
            if (!property_exists($this, $name)) {
                continue;
            }

            // restore the property's declared default when null is given
            if ($value === null) {
                $key = $this::class . '::' . $name;
                if (!array_key_exists($key, self::$optionDefaults)) {
                    $property = new \ReflectionProperty($this, $name);
                    self::$optionDefaults[$key] = $property->hasDefaultValue() ? $property->getDefaultValue() : null;
                }
                $value = self::$optionDefaults[$key];
            }

            $browser->$name = $value;
        }
        return $browser;
    }

    public function requestStreaming($method, $url, array $headers = [], $body = ''): PromiseInterface {
        return $this->withOptions(['streaming' => true])->request($method, $url, $headers, $body);
    }

    /**
     * PSR-18 synchronous request.
     *
     * Blocks until the response is received by driving the event loop. 4xx/5xx
     * status codes resolve with the response; transport failures throw a
     * NetworkException and invalid requests throw a RequestException.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $browser = $this->withRejectErrorResponse(false);

        try {
            /** @var PromiseInterface<ResponseInterface> $promise */
            $promise = $browser->request(
                $request->getMethod(),
                (string)$request->getUri(),
                $request->getHeaders(),
                (string)$request->getBody()
            );

            return Async\await($promise);
        } catch (\InvalidArgumentException $e) {
            throw new RequestException($e->getMessage(), $request, $e);
        } catch (Throwable $e) {
            throw new NetworkException($e->getMessage(), $request, $e);
        }
    }

    /**
     * Set the maximum time in seconds for the whole request/response.
     *
     * A positive number sets an explicit timeout. `true` re-enables the default
     * (PHP's `default_socket_timeout`, 60s by default). `false` or a negative
     * value disables timeouts, allowing requests to stay pending forever.
     */
    public function withTimeout(float|int|bool $timeout)
    {
        if ($timeout === true) {
            $timeout = null;
        } elseif ($timeout === false) {
            $timeout = 0;
        } elseif ($timeout < 0) {
            $timeout = 0;
        }

        return $this->withOptions(['timeout' => $timeout]);
    }

    public function withHeader($header, $value)
    {
        $browser = $this->withoutHeader($header);
        $browser->defaultHeaders[$header] = $value;

        return $browser;
    }

    public function withoutHeader($header)
    {
        $browser = $this->withOptions();

        /** @var string|int $key */
        foreach (\array_keys($browser->defaultHeaders) as $key) {
            if (\strcasecmp($key, $header) === 0) {
                unset($browser->defaultHeaders[$key]);
                break;
            }
        }

        return $browser;
    }

    public function withFollowRedirects($followRedirects)
    {
        return $this->withOptions(array(
            'followRedirects' => $followRedirects !== false,
            'maxRedirects' => \is_bool($followRedirects) ? null : $followRedirects
        ));
    }

    /**
     * Resolve relative request URLs against this base URL.
     *
     * This is a convenience for building API clients, NOT a security boundary: an
     * absolute or protocol-relative URL replaces the base entirely (same as react/http).
     * Validate/allow-list hosts yourself if request URLs can come from untrusted input.
     */
    public function withBase($baseUrl)
    {
        if ($baseUrl !== null) {
            $baseUrl = new Uri($baseUrl);
            if (!\in_array($baseUrl->getScheme(), ['http', 'https']) || $baseUrl->getHost() === '') {
                throw new \InvalidArgumentException('Base URL must be absolute');
            }
        }
        return $this->withOptions(['baseUrl' => $baseUrl]);
    }

    public function withRejectErrorResponse($obeySuccessCode)
    {
        return $this->withOptions(array(
            'obeySuccessCode' => $obeySuccessCode,
        ));
    }

    /**
     * Reject requests that connect to a non-public address (loopback, private,
     * link-local, CGNAT, multicast, ...). The check runs after the connection is
     * established but before any request is sent, for every new or reused
     * connection and every redirect hop, so DNS rebinding and obfuscated
     * IP-literal hosts cannot bypass it.
     *
     * Requires PHP 8.4+ (CURLOPT_PREREQFUNCTION). Enabling it on older versions
     * throws instead of silently doing nothing. Disabled by default; proxies are
     * not inspected (the proxy's address is what gets checked).
     */
    public function withSsrfProtection(bool $enabled = true)
    {
        if ($enabled && !defined('CURLOPT_PREREQFUNCTION')) {
            throw new \RuntimeException('SSRF protection requires PHP 8.4+ (CURLOPT_PREREQFUNCTION)');
        }

        return $this->withOptions(array(
            'ssrfProtection' => $enabled,
        ));
    }

    public function withProtocolVersion(string $protocolVersion)
    {
        $version = match($protocolVersion) {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2', '2.0' => CURL_HTTP_VERSION_2,
            '3', '3.0' => self::httpVersionConstant('CURL_HTTP_VERSION_3', self::HTTP3),
            //unknown values let cURL negotiate the protocol itself
            default => CURL_HTTP_VERSION_NONE
        };
        return $this->withOptions(array(
            'httpVersion' => $version,
        ));
    }

    public function withResponseBuffer($maximumSize)
    {
        if (!is_int($maximumSize) || $maximumSize < 1) {
            throw new \InvalidArgumentException('Response buffer size must be a positive integer');
        }

        return $this->withOptions(array(
            'maximumSize' => $maximumSize
        ));
    }

    /**
     * Toggle the shared CurlMultiHandle used for all in-flight requests (enabled by default).
     *
     * With N concurrent requests one multi handle performs one curl_multi_exec() +
     * curl_multi_select() poll per event-loop tick instead of N of each. Responses are still
     * settled individually as soon as libcurl reports each transfer complete via
     * curl_multi_info_read(), so slow transfers never delay fast ones. Clones share the
     * multi handle with the Browser family they derive from.
     *
     * Pass false to fall back to one CurlMultiHandle per request.
     */
    public function withSharedMultiHandle(bool $enabled = true): self
    {
        return $this->withOptions(array(
            'sharedMulti' => $enabled,
        ));
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string|ReadableStreamInterface $body
     * @return PromiseInterface
     */
    public function request(string $method, string $url, array $headers = [], string|ReadableStreamInterface $body = ''): PromiseInterface
    {
        if ($this->baseUrl !== null) {
            //base URL is a convenience for resolving relative URLs only, not a host allow-list:
            //absolute (or protocol-relative) URLs replace it entirely, matching react/http
            $url = Uri::resolve(new Uri($this->baseUrl), new Uri($url));
        } else {
            $url = new Uri($url);
        }

        //only HTTP(S) may be requested: cURL supports many other schemes (file, gopher, dict, ...)
        //which an application passing through an untrusted URL could use for SSRF/local file reads
        if ($url->getHost() === '' || !in_array(strtolower($url->getScheme()), ['http', 'https'], true)) {
            return Promise\reject(
                new \InvalidArgumentException(
                    'Invalid request URL given'
                )
            );
        }

        $method = strtoupper($method);

        //CONNECT creates a tunnel instead of exchanging a request/response body, which this client doesn't model
        if ($method === 'CONNECT') {
            return Promise\reject(new \InvalidArgumentException('Unsupported HTTP method CONNECT'));
        }

        //the method is interpolated into the request line by libcurl: restrict it to RFC 7230 tokens
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $method) !== 1) {
            return Promise\reject(new \InvalidArgumentException('Invalid HTTP method given'));
        }

        $headers = array_change_key_case($headers, CASE_LOWER);

        //If we have been passed an array of headers in "Content-Type: application/json" convert to our Header => Value format
        foreach($headers as $key => $value) {
            if (is_int($key) && \is_string($value) && str_contains($value, ":")) {
                [$header, $value] = explode(":", $value, 2);
                $headers[strtolower(trim($header))] = trim($value);
                unset($headers[$key]);
            }
        }

        //user headers take precedence over Browser defaults; merge them once here and reuse
        //the merged map for validation, the Connection check and the CURLOPT_HTTPHEADER build
        $headers = $headers + array_change_key_case($this->defaultHeaders);

        try {
            //CR/LF and other control characters in a name/value would be injected as extra wire headers
            $this->assertValidHeaders($headers);
        } catch (\InvalidArgumentException $e) {
            return Promise\reject($e);
        }

        //a non-numeric Content-Length would be silently coerced to 0 by cURL while the
        //malformed header still goes out on the wire: validate and normalise it instead
        if (array_key_exists('content-length', $headers)) {
            $contentLengths = is_array($headers['content-length']) ? $headers['content-length'] : [$headers['content-length']];
            $contentLength = null;
            foreach ($contentLengths as $value) {
                if (is_int($value)) {
                    $value = (string)$value;
                }
                if (!\is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
                    return Promise\reject(new \InvalidArgumentException('Invalid Content-Length header given'));
                }
                $contentLength ??= $value;
            }
            //send exactly one numeric value, matching the upload size set below
            $headers['content-length'] = $contentLength ?? '0';
        }

        $curl = $this->initCurl();

        //HEAD and TRACE must not include content (RFC 9110); POST/PUT/PATCH send Content-Length: 0 when empty
        $bodyAllowed = !in_array($method, ['HEAD', 'TRACE'], true);
        $hasBody = $body instanceof ReadableStreamInterface || $body !== '';
        $expectsBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        $upload = null;
        if ($bodyAllowed && ($hasBody || $expectsBody)) {
            if ($body instanceof ReadableStreamInterface) {
                $upload = new UploadBodyStream($body);
                $upload->on('continue', function () use ($curl) {
                    curl_pause($curl, CURLPAUSE_CONT);
                    //source data arrived: wake the tick handler instead of waiting for the
                    //next idle poll
                    $this->wakeTick();
                });

                curl_setopt($curl, CURLOPT_PUT, true);
                curl_setopt($curl, CURLOPT_READFUNCTION, static function ($curl, $fd, $length) use ($upload) {
                    return $upload->read($length);
                });
                if (array_key_exists('content-length', $headers)) {
                    //validated and normalised to a numeric string before the cURL handle was created
                    curl_setopt($curl, CURLOPT_INFILESIZE, (int)$headers['content-length']);
                    $headers['transfer-encoding'] = '';
                }
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
        }

        $curl_opts = match($method) {
            'HEAD' => [ CURLOPT_NOBODY => true, ],
            //GET with a body needs CUSTOMREQUEST, as CURLOPT_HTTPGET would drop the body
            'GET' => $hasBody ? [ CURLOPT_CUSTOMREQUEST => 'GET' ] : [ CURLOPT_HTTPGET => true, ],
            //everything else (POST/PUT/DELETE/PATCH/OPTIONS/TRACE/QUERY/WebDAV/custom) is sent verbatim
            default => [ CURLOPT_CUSTOMREQUEST => $method ],
        };

        $curl_opts[CURLOPT_HTTP_VERSION] = $this->httpVersion;

        $curl_opts[CURLOPT_FOLLOWLOCATION] = $this->followRedirects;
        $curl_opts[CURLOPT_MAXREDIRS] = $this->maxRedirects;

        $curl_opts[CURLOPT_URL] = (string)$url;

        if ($this->ssrfProtection) {
            //runs after connect/TLS but before the request is sent; aborting rejects
            //the promise with CURLE_ABORTED_BY_CALLBACK in curlTick()
            $curl_opts[self::PREREQFUNCTION] = static function (CurlHandle $curl, string $primaryIp, string $localIp, int $primaryPort, int $localPort): int {
                return self::isBlockedAddress($primaryIp) ? self::PREREQFUNC_ABORT : self::PREREQFUNC_OK;
            };
        }

        /**
         * Explicit timeout, otherwise PHP's default_socket_timeout like react/http.
         *  withTimeout(false)/0/negative disables it; constructor CURLOPT_TIMEOUT(_MS) takes precedence.
         **/
        $timeout = $this->timeout ?? (float)ini_get('default_socket_timeout');
        if ($timeout > 0 && !array_key_exists(CURLOPT_TIMEOUT, $this->options) && !array_key_exists(CURLOPT_TIMEOUT_MS, $this->options)) {
            $curl_opts[CURLOPT_TIMEOUT_MS] = (int) round($timeout * 1000);
        }

        $connection = $headers['connection'] ?? '';
        if (is_array($connection)) {
            $connection = implode(",", $connection);
        }
        if (\strcasecmp($connection, 'close') === 0) {
            $curl_opts[CURLOPT_FORBID_REUSE] = true; //Curl keeps the connection open if the server doesn't close even if we said we are closing
        }

        if (!empty($headers)) {
            $builtHeaders = [];
            foreach($headers as $key => $value) {
                $builtHeaders[] = strtolower($key) . ": " . (is_array($value) ? implode(",", $value) : $value);
            }
            $curl_opts[CURLOPT_HTTPHEADER] = $builtHeaders;
        }
        curl_setopt_array($curl, $curl_opts);

        return $this->execRequest($curl, $upload);
    }

    /**
     * Reject names/values that would inject additional headers (or smuggle a request) into the wire format
     *
     * @param array<string|int, mixed> $headers
     */
    private function assertValidHeaders(array $headers) : void {
        foreach($headers as $key => $value) {
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', (string)$key) !== 1) {
                throw new \InvalidArgumentException('Invalid request header name given');
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (preg_match('/[\x00-\x1F\x7F]/', (string)$item) === 1) {
                    throw new \InvalidArgumentException('Invalid request header value given');
                }
            }
        }
    }

    /**
     * True for loopback, private, link-local, CGNAT, multicast and other
     * non-public destinations. Unparsable input fails closed.
     */
    private static function isBlockedAddress(string $ip) : bool {
        //FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+) rejects loopback, private, link-local, CGNAT,
        //benchmarking, documentation and reserved ranges, and all IPv4-mapped IPv6 addresses
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return true;
        }

        $binary = @inet_pton($ip);
        if (!\is_string($binary) || $binary === '') {
            return true; //unparsable input fails closed
        }

        if (strlen($binary) === 4) {
            return (ord($binary[0]) & 0xF0) === 0xE0; //multicast 224/4 is not filtered by GLOBAL_RANGE
        }

        return $binary[0] === "\xff"; //IPv6 multicast ff00::/8 is not filtered by GLOBAL_RANGE
    }

    /**
     * Don't apply any additional configuration changes which remove or limit cURL connection caches / reuse
     * @return void
     */
    public function enableConnectionCaches() : void {
        $this->disableCurlCache = false;
    }

    /**
     * Disable as much of cURLs internal caches (DNS resolution) and connection reuse. Useful when performing a health check
     * @return void
     */
    public function disableConnectionCaches() : void {
        $this->disableCurlCache = true;
    }

    private function initCurl() : CurlHandle {
        $curl = curl_init();

        if (!$curl) {
            throw new \RuntimeException('Unable to init curl');
        }

        //conflicting options (CURLOPT_FILE/WRITEHEADER/...FUNCTION, CURLOPT_HEADER,
        //CURLOPT_POSTFIELDS) are rejected by assertValidOptions() in the constructor
        $options = $this->options + self::DEFAULT_CURL_OPTIONS;

        if ($this->disableCurlCache) {
            $options = $options + static::NO_CACHE_OPTIONS;
        } else {
            curl_setopt($curl, CURLOPT_SHARE, $this->curlShare);
        }

        try {
            error_clear_last();
            $result = @curl_setopt_array($curl, $options);
            $warning = error_get_last();
            if ($result === false || $warning !== null) {
                //Capture malformed options that result in conversion errors like "Array to string conversion",
                //or options this libcurl build cannot set (e.g. CURLOPT_DNS_SERVERS without c-ares)
                $detail = $warning['message'] ?? curl_error($curl);
                throw new \RuntimeException('One or more cURL options values were invalid' . ($detail !== '' ? ': ' . $detail : ''));
            }
        } catch (ValueError $e) {
            throw new \RuntimeException('Invalid cURL options keys provided');
        } catch (\TypeError $e) {
            //e.g. CURLOPT_RESOLVE/CURLOPT_HTTPHEADER with a non-array value
            throw new \RuntimeException('One or more cURL options values were invalid', 0, $e);
        }

        return $curl;
    }

    private function initMulti(CurlHandle $curl) : CurlMultiHandle
    {
        if ($this->sharedMulti) {
            $multi = $this->state->sharedMulti();
        } else {
            $multi = curl_multi_init();
        }

        $return = curl_multi_add_handle($multi, $curl);

        if ($return !== 0) {
            if (!$this->sharedMulti) {
                curl_multi_close($multi);
            }
            throw new \RuntimeException('Unable to add curl to multi handle, Error:' . $return . ", Msg: " . curl_multi_strerror($return));
        }

        return $multi;
    }

    protected function execRequest($curl, ?UploadBodyStream $upload = null) : PromiseInterface {
        $multi = $this->initMulti($curl);

        if ($this->streaming) {
            $responseBody = new ThroughStream();
            $pauseRequested = false;
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody, &$pauseRequested) {
                static $first = true;

                //a promise handler running inside this callback may have cancelled the
                //request (cancelAll()): the transaction is gone or closing and the
                //transfer must be aborted instead of writing more data
                if (!$this->state->inProgress->offsetExists($curl) || $this->state->inProgress[$curl]->isClosed()) {
                    return 0;
                }
                $transaction = $this->state->inProgress[$curl];

                if ($first) {
                    try {
                        $res = $this->resolveResponse($transaction);
                        $transaction->deferred->resolve($res);
                    } catch (Throwable $ex) {
                        if (isset($transaction->deferred)) {
                            $transaction->deferred->reject($ex);
                        } else {
                            // transaction already cancelled/closed: nothing will end the stream
                            $responseBody->close();
                        }
                    }
                    $first = false;

                    if ($transaction->isClosed()) {
                        return 0; //handler cancelled the request: abort the transfer
                    }
                }

                if (!$responseBody->isWritable()) {
                    return 0; //consumer closed the stream, abort the transfer
                }
                if ($pauseRequested) {
                    //cURL re-delivers this chunk after curl_pause(CONT), so don't consume it yet
                    return CURL_WRITEFUNC_PAUSE;
                }
                if ($responseBody->write($data) === false) {
                    $pauseRequested = true; //consumer applied backpressure: halt before the next chunk
                }
                return strlen($data);
            });
            $responseBody->on('drain', function () use ($curl, &$pauseRequested) {
                $pauseRequested = false;
                curl_pause($curl, CURLPAUSE_CONT);
                //cURL may hold buffered data after resuming: wake the tick handler now
                //instead of waiting for the next idle poll
                $this->wakeTick();
            });
            $responseBody->on('close', static function () use ($curl) {
                //if paused, let the write callback run once more to observe the closed stream and abort
                curl_pause($curl, CURLPAUSE_CONT);
            });
        } else {
            $responseBody = fopen('php://temp/maxmemory:' . min($this->maximumSize, self::MAX_BUFFERED_MEMORY), 'w+');
            if ($responseBody === false) {
                throw new \RuntimeException('Unable to create temporary file for response body');
            }
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody) {
                static $xfer = 0;
                $len = strlen($data);
                $xfer += $len;

                if ($xfer > $this->maximumSize) {
                    // Record the failure and abort the transfer, but do not remove the transaction
                    // from inProgress or settle the promise here: this runs inside a libcurl
                    // callback, where removing/closing cURL handles is not allowed. curlTick()
                    // rejects the promise and closes the handles once curl_multi_exec() returns.
                    if ($this->state->inProgress->offsetExists($curl) && $this->state->inProgress[$curl]->error === null) {
                        $this->state->inProgress[$curl]->error = new \OverflowException(
                            'Response body size of ' . $xfer . ' bytes exceeds maximum of ' . $this->maximumSize . ' bytes',
                            \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
                        );
                    }
                    return 0;
                }

                $written = fwrite($responseBody, $data);
                return $written === false ? 0 : $written;
            });
        }

        /** Monitor if we are in upload or download state, used in calculating suggested multi timeouts */
        curl_setopt($curl, CURLOPT_NOPROGRESS, 0);
        curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, function($curl, $dl_total, $dl_xfer, $ul_total, $ul_xfer) {
            if (!$this->state->inProgress->offsetExists($curl)) {
                return 0;
            }

            $status = Transaction::STATUS_CONNECTING;
            $oldStatus = $this->state->inProgress[$curl]->status;
            if ($dl_xfer > 0 || $dl_total > 0) {
                if ($dl_xfer >= $dl_total) {
                    $status = Transaction::STATUS_DONE;
                } else {
                    $status = Transaction::STATUS_DOWNLOADING;
                }
            } elseif ($ul_xfer > 0) {
                $status = Transaction::STATUS_UPLOADING;
            }
            if ($status !== $oldStatus) {
                $this->state->inProgress[$curl]->status = $status;
                if ($oldStatus === Transaction::STATUS_UPLOADING) {
                    curl_pause($curl, CURLPAUSE_CONT); //Ensure we are not paused
                }
            }
            return 0; //Keep going
        });

        $deferred = new Deferred(function() use ($curl, &$deferred) {
            if ($this->state->inProgress->offsetExists($curl)) {
                $transaction = $this->state->inProgress[$curl];
                unset($this->state->inProgress[$curl]);
            }
            $deferred->reject(new \RuntimeException('Request cancelled'));
            if (isset($transaction)) {
                $transaction->close();
            }
        });

        $upload?->on('error', static function (Throwable $error) use ($deferred) {
            $deferred->reject($error);
        });

        $transaction = new Transaction($multi, $curl, $deferred, $responseBody, $this->state, !$this->sharedMulti);
        $this->state->inProgress[$curl] = $transaction;

        $this->execCurlMulti($multi); //Get it started

        //a new request may produce data soon: reset idle back-off and replace any pending
        //back-off timer with an immediate tick, then keep the tick handler running until all
        //shared requests are done
        $this->state->idleTicks = 0;
        $this->state->idleTickInterval = self::ACTIVE_TICK_INTERVAL;
        $this->wakeTick();

        return $deferred->promise();
    }

    /**
     * Queue the next curlTick unless one is already queued or running.
     *
     * A tick can be suspended via React\Async midway through (e.g. when a response promise
     * resumes user code), so the scheduled flag stays set until the tick's tail reschedules.
     */
    private function scheduleTick(float $nextIterationTimeout): void
    {
        if ($this->state->tickScheduled) {
            return;
        }

        $this->state->tickScheduled = true;
        if ($nextIterationTimeout > 0) {
            //use a timer instead of futureTick so that we don't lock the CPU at 100%
            $this->state->tickTimer = $this->loop->addTimer($nextIterationTimeout, $this->curlTick(...));
        } else {
            $this->loop->futureTick($this->curlTick(...));
        }
    }

    /**
     * Wake the tick handler immediately, cancelling any pending idle back-off timer.
     */
    private function wakeTick(): void
    {
        if ($this->state->tickTimer !== null) {
            $this->loop?->cancelTimer($this->state->tickTimer);
            $this->state->tickTimer = null;
            $this->state->tickScheduled = false;
        }
        $this->scheduleTick(0.0);
    }

    /**
     * Run curl_multi_exec() while tracking that a libcurl callback may be on the stack.
     *
     * libcurl forbids removing an easy handle from a multi handle from within any of its
     * callbacks, so transactions closed re-entrantly (e.g. cancelAll() from a streaming
     * response handler) are queued by Transaction::close() and released here as soon as
     * the outermost curl_multi_exec() call has returned.
     */
    private function execCurlMulti(CurlMultiHandle $multi): int {
        $state = $this->state;
        $stillRunning = 0;

        ++$state->curlCallbackDepth;
        try {
            curl_multi_exec($multi, $stillRunning);
        } finally {
            if (--$state->curlCallbackDepth === 0) {
                $this->flushPendingTeardown();
            }
        }

        return $stillRunning;
    }

    private function flushPendingTeardown(): void {
        if ($this->state->pendingTeardown === []) {
            return;
        }

        $pending = $this->state->pendingTeardown;
        $this->state->pendingTeardown = [];
        foreach ($pending as $transaction) {
            $transaction->closeNow();
        }
    }

    private function curlTick(): void
    {
        if ($this->state->tickTimer !== null) {
            //cancel a wake-up that was superseded by this tick
            $this->loop?->cancelTimer($this->state->tickTimer);
            $this->state->tickTimer = null;
        }

        $nextIterationTimeout = self::ACTIVE_TICK_INTERVAL;
        $drainBudget = 256; //yield to the event loop after at most 4 MiB so timers/other requests stay responsive
        $stillRunning = false;
        $uploading = false;
        $dataFlowing = false;
        $progressed = false;
        try {
            //Collect every distinct multi handle currently in use: one per request in the
            //default mode, or a single shared handle with withSharedMultiHandle().
            $multis = [];
            foreach ($this->state->inProgress as $handle) {
                $transaction = $this->state->inProgress[$handle];
                if ($transaction->isClosed()) {
                    unset($this->state->inProgress[$handle]);
                    continue;
                }
                $multis[\spl_object_id($transaction->multi)] = $transaction->multi;
                if ($transaction->status === Transaction::STATUS_UPLOADING) {
                    $uploading = true;
                }
            }

            foreach ($multis as $mh) {
                //Snapshot the transactions attached to this multi before executing: promise
                //handlers may start new requests on the same (shared) handle during the tick,
                //and those must not be mistaken for stuck transfers by the safety net below.
                $attached = [];
                foreach ($this->state->inProgress as $handle) {
                    $attachedTransaction = $this->state->inProgress[$handle];
                    if (!$attachedTransaction->isClosed() && $attachedTransaction->multi === $mh) {
                        $attached[\spl_object_id($handle)] = $handle;
                    }
                }

                $running = $this->execCurlMulti($mh);

                //Drain all immediately available data before returning to the event loop:
                //each write callback gets at most 16 KiB, and a full loop iteration costs
                //far more than the read it delivers.
                while ($running && $drainBudget-- > 0 && curl_multi_select($mh, 0) > 0) {
                    $dataFlowing = true;
                    $running = $this->execCurlMulti($mh);
                }
                if ($running) {
                    $stillRunning = true;
                }

                //Settle every completed transfer as soon as cURL reports it. This runs even
                //while other transfers on the same multi handle are still in flight, so a
                //slow download never delays a fast one (no head-of-line blocking).
                while (($info = curl_multi_info_read($mh)) !== false) {
                    if (!isset($info['handle']) || !$info['handle'] instanceof CurlHandle) {
                        continue;
                    }
                    $completed = $info['handle'];
                    if (!$this->state->inProgress->offsetExists($completed)) {
                        continue; //already cancelled/closed
                    }
                    $transaction = $this->state->inProgress[$completed];
                    if ($transaction->isClosed()) {
                        unset($this->state->inProgress[$completed]);
                        continue;
                    }
                    $result = $info['result'] ?? CURLE_OK;
                    $progressed = true;
                    $this->completeTransaction($transaction, is_int($result) ? $result : CURLE_OK);
                }

                if (!$running) {
                    //safety net: a transfer that was attached before this exec stopped running
                    //without a completion message
                    foreach ($attached as $handle) {
                        if (!$this->state->inProgress->offsetExists($handle)) {
                            continue;
                        }
                        $stuck = $this->state->inProgress[$handle];
                        if ($stuck->isClosed()) {
                            unset($this->state->inProgress[$handle]);
                            continue;
                        }
                        unset($this->state->inProgress[$handle]);
                        $stuck->deferred->reject($stuck->error ?? new \RuntimeException("curl_multi_info_read returned error on completion"));
                        $stuck->close();
                    }
                }
            }

            if ($stillRunning) {
                if ($uploading) {
                    //an upload source may produce data at any time: stay responsive
                    $this->state->idleTicks = 0;
                    $this->state->idleTickInterval = self::ACTIVE_TICK_INTERVAL;
                    $nextIterationTimeout = 0.0001;
                } elseif ($dataFlowing) {
                    //socket data is queued: drain it on the next loop iteration
                    $this->state->idleTicks = 0;
                    $this->state->idleTickInterval = self::ACTIVE_TICK_INTERVAL;
                    $nextIterationTimeout = 0;
                } elseif ($progressed) {
                    //a transfer completed (and may have started new ones): poll promptly
                    $this->state->idleTicks = 0;
                    $this->state->idleTickInterval = self::ACTIVE_TICK_INTERVAL;
                    $nextIterationTimeout = self::ACTIVE_TICK_INTERVAL;
                } else {
                    //waiting on the network: poll at 1 ms briefly, then back off so long
                    //transfers do not cost a wake-up every millisecond
                    if (++$this->state->idleTicks >= self::IDLE_TICKS_BEFORE_BACKOFF) {
                        $this->state->idleTickInterval = min(
                            $this->state->idleTickInterval * 2,
                            self::MAX_IDLE_TICK_INTERVAL
                        );
                    }
                    $nextIterationTimeout = $this->state->idleTickInterval;
                }
            }
        } finally {
            $this->state->tickScheduled = false;
            if (count($this->state->inProgress) > 0) {
                $this->scheduleTick($nextIterationTimeout);
            }
        }
    }

    /**
     * Settle a transaction whose transfer has completed, then release its resources.
     *
     * @param Transaction $transaction
     * @param int $result CURLcode reported by curl_multi_info_read()
     */
    private function completeTransaction(Transaction $transaction, int $result): void
    {
        $curl = $transaction->curl;
        $deferred = $transaction->deferred;

        if ($transaction->file instanceof ThroughStream) {
            $transaction->file->end();
        }

        if ($transaction->error !== null) {
            //a cURL callback aborted the transfer; reject only now, outside the callback
            unset($this->state->inProgress[$curl]);
            $deferred->reject($transaction->error);
        } elseif ($result === CURLE_OK) {
            try {
                $res = $this->resolveResponse($transaction);
                unset($this->state->inProgress[$curl]);
                $deferred->resolve($res);
            } catch (Throwable $ex) {
                unset($this->state->inProgress[$curl]);
                $deferred->reject($ex);
            }
        } else {
            unset($this->state->inProgress[$curl]);
            if ($result === CURLE_OPERATION_TIMEDOUT) {
                $deferred->reject(new \RuntimeException('Request timed out after ' . round((hrtime(true) - $transaction->start)/1e+9, 1) . ' seconds'), CURLE_OPERATION_TIMEDOUT);
            } elseif ($result === CURLE_ABORTED_BY_CALLBACK && $this->ssrfProtection) {
                //the pre-request callback refused a non-public destination before sending the request
                $deferred->reject(new \RuntimeException('Request to blocked address ' . (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP) . ' refused', CURLE_ABORTED_BY_CALLBACK));
            } else {
                $deferred->reject(new \RuntimeException(curl_strerror($result) ?? ('cURL error ' . $result), $result));
            }
        }
        $transaction->close();
    }

    /**
     * @param Transaction $transaction
     * @return ResponseInterface
     */
    private function resolveResponse(Transaction $transaction): ResponseInterface
    {
        $curl = $transaction->curl;
        $responseBody = $transaction->file;
        if (is_resource($responseBody)) {
            stream_set_blocking($responseBody, false);
            rewind($responseBody);
            $stream = Utils::streamFor($responseBody);
            //Hand ownership of the php://temp stream to the PSR-7 body: Guzzle closes it
            //when the response is released. Transaction teardown must not fclose it here,
            //otherwise responses read after the resolving tick (e.g. from a Promise\all()
            //handler) would operate on a closed stream.
            $transaction->file = null;
            $body = $stream;
        } elseif ($responseBody === null) {
            throw new \RuntimeException('Unable to build response: body stream already released');
        } else {
            $body = $responseBody;
        }

        //@TODO implement ReactPHP Browser withRejectErrorResponse support
        return $this->constructResponseFromCurl($curl, $transaction->headers, $body);
    }

    /**
     * Map a CURLINFO_HTTP_VERSION result to a PSR-7 protocol version string.
     *
     * The CURL_HTTP_VERSION_3* constants are PHP 8.3+/libcurl 7.66+, but the numeric values
     * are fixed by libcurl's ABI, so fall back to them on older builds instead of throwing.
     */
    private static function httpVersionName(int $curlHttpVersion) : string {
        $http2Tls = self::httpVersionConstant('CURL_HTTP_VERSION_2TLS', self::HTTP2_TLS);
        $http2PriorKnowledge = self::httpVersionConstant('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE', self::HTTP2_PRIOR_KNOWLEDGE);
        $http3 = self::httpVersionConstant('CURL_HTTP_VERSION_3', self::HTTP3);
        $http3Only = self::httpVersionConstant('CURL_HTTP_VERSION_3ONLY', self::HTTP3_ONLY);

        return match ($curlHttpVersion) {
            CURL_HTTP_VERSION_NONE, CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2, $http2Tls, $http2PriorKnowledge => '2',
            $http3, $http3Only => '3',
            default => '1.1',
        };
    }

    /**
     * Return an ext-curl constant if this PHP build defines it, otherwise libcurl's
     * fixed ABI value, so HTTP/2TLS and HTTP/3 work on PHP 8.2 builds too if linked libcurl supports.
     */
    private static function httpVersionConstant(string $constant, int $fallback): int {
        return \defined($constant) ? (int)\constant($constant) : $fallback;
    }

    /**
     * Read only the CURLINFO_* values consumed when building the response.
     *
     * A full curl_getinfo() array materialises every field (measured ~30% slower) and
     * several values used below are not part of it (num_connects) or only available in
     * another form (appconnect_time_us). The casts mirror the type libcurl documents for
     * each option.
     *
     * @return array{
     *   redirect_count: int,
     *   appconnect_time: float,
     *   certinfo: array,
     *   request_size: int,
     *   size_upload: float,
     *   header_size: int,
     *   size_download: float,
     *   url: string,
     *   namelookup_time: float,
     *   connect_time: float,
     *   pretransfer_time: float,
     *   redirect_time: float,
     *   starttransfer_time: float,
     *   total_time: float,
     *   num_connects: int,
     *   speed_upload: int,
     *   speed_download: int,
     *   response_code: int,
     *   effective_method: string,
     *   http_version: int,
     * }
     */
    private static function curlInfo(CurlHandle $curl): array {
        return [
            'redirect_count' => (int)curl_getinfo($curl, CURLINFO_REDIRECT_COUNT),
            'appconnect_time' => (float)curl_getinfo($curl, CURLINFO_APPCONNECT_TIME),
            'certinfo' => (array)curl_getinfo($curl, CURLINFO_CERTINFO),
            'request_size' => (int)curl_getinfo($curl, CURLINFO_REQUEST_SIZE),
            'size_upload' => (float)curl_getinfo($curl, CURLINFO_SIZE_UPLOAD),
            'header_size' => (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE),
            'size_download' => (float)curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD),
            'url' => (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'namelookup_time' => (float)curl_getinfo($curl, CURLINFO_NAMELOOKUP_TIME),
            'connect_time' => (float)curl_getinfo($curl, CURLINFO_CONNECT_TIME),
            'pretransfer_time' => (float)curl_getinfo($curl, CURLINFO_PRETRANSFER_TIME),
            'redirect_time' => (float)curl_getinfo($curl, CURLINFO_REDIRECT_TIME),
            'starttransfer_time' => (float)curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME),
            'total_time' => (float)curl_getinfo($curl, CURLINFO_TOTAL_TIME),
            'num_connects' => (int)curl_getinfo($curl, CURLINFO_NUM_CONNECTS),
            'speed_upload' => (int)curl_getinfo($curl, CURLINFO_SPEED_UPLOAD_T),
            'speed_download' => (int)curl_getinfo($curl, CURLINFO_SPEED_DOWNLOAD_T),
            'response_code' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'effective_method' => (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_METHOD),
            'http_version' => (int)curl_getinfo($curl, CURLINFO_HTTP_VERSION),
        ];
    }

    /**
     * @param CurlHandle $curl
     * @param string $rawHeaders
     * @param ThroughStream|StreamInterface $body
     * @return ResponseInterface
     */
    private function constructResponseFromCurl(CurlHandle $curl, string $rawHeaders, ThroughStream|StreamInterface $body) : ResponseInterface {
        $info = self::curlInfo($curl);
        $redirectCount = $info['redirect_count'];
        $headers = [];
        $lines = preg_split('/(\\r?\\n)/', trim($rawHeaders), -1);
        foreach($lines as $headerLine) {
            //If we have followed redirects we need to drop headers from previous requests
            if ($headerLine === '' && $redirectCount > 0) {
                $headers = [];
                continue;
            }
            if (!str_contains($headerLine, ':')) { //This will strip the HTTP/code header line
                continue;
            }
            $parts = explode(':', $headerLine, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';
            $headers[$key][] = $value;
        }

        $timing = [
            'namelookup_time;dur=' . $info['namelookup_time'],
            'connect_time;dur=' . $info['connect_time'],
            'appconnect_time;dur=' . $info['appconnect_time'],
            'pretransfer_time;dur=' . $info['pretransfer_time'],
            'redirect_time;dur=' . $info['redirect_time'],
            'starttransfer_time;dur=' . $info['starttransfer_time'],
            'total_time;dur=' . $info['total_time'],
        ];
        $headers['ServerTiming'] = $timing;

        $certs = $info['certinfo'];
        if (count($certs) > 0) {
            $headers['X-Certificate'] = $certs[0]['Cert'];
        }

        $uploadSize = $info['request_size'] + $info['size_upload'];
        $downloadSize = $info['header_size'] + $info['size_download'];

        $headers['X-Connection'] = [
            "effective_url=" . $info['url'],
            "connection;count=" . $info['num_connects'],
            "redirect;count=" . $redirectCount,
            "upload;size=$uploadSize;speed=" . $info['speed_upload'],
            "download;size=$downloadSize;speed=" . $info['speed_download'],
        ];

        // determine length of response body
        $length = null;
        $code = $info['response_code'];
        if ($info['effective_method'] === 'HEAD' || ($code >= 100 && $code < 200) || $code == StatusCodeInterface::STATUS_NO_CONTENT || $code == StatusCodeInterface::STATUS_NOT_MODIFIED) {
            $length = 0;
        } else {
            foreach ($headers as $key => $values) {
                if (\strcasecmp($key, 'Content-Length') === 0) {
                    $length = (int)$values[0];
                    break;
                }
            }
        }

        if ($body instanceof ThroughStream) {
            $body = new HttpBodyStream($body, $length);
        }

        $httpVersion = self::httpVersionName($info['http_version']);

        $response = new \React\Http\Message\Response(
            $code,
            $headers,
            $body,
            $httpVersion,
        );

        if ($this->obeySuccessCode && ($code < 200 || $code >= 400)) {
            throw new ResponseException($response);
        }

        return $response;
    }

    /**
     * Cancel all requests in flight on this Browser and any clone derived from it.
     *
     * There is deliberately no automatic cancellation when a Browser instance is released:
     * clones share request state, so any clone being garbage collected must not kill the
     * family's requests. Unobserved requests are cleaned up by Transaction's destructor.
     */
    public function cancelAll() : void {
        if ($this->state->tickTimer !== null) {
            $this->loop?->cancelTimer($this->state->tickTimer);
            $this->state->tickTimer = null;
        }
        $this->state->tickScheduled = false;

        foreach($this->state->inProgress as $handle) {
            $transaction = $this->state->inProgress[$handle];
            unset($this->state->inProgress[$handle]);
            $transaction->close();
        }
    }
}