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
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

class Browser implements ClientInterface {
    /** Largest response body prefix kept in memory before spilling to a temporary file */
    private const MAX_BUFFERED_MEMORY = 16777216; //16 MiB

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
        CURLOPT_UPLOAD_BUFFERSIZE => 10485764*2, //Increase the upload buffer
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

    protected function withOptions(array $options = []) : self {
        $browser = clone $this;
        foreach ($options as $name => $value) {
            if (property_exists($this, $name)) {
                // restore default value if null is given
                if ($value === null) {
                    $default = new self();
                    $value = $default->$name;
                }

                $browser->$name = $value;
            }
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

        try {
            //CR/LF and other control characters in a name/value would be injected as extra wire headers
            $this->assertValidHeaders($headers + array_change_key_case($this->defaultHeaders));
        } catch (\InvalidArgumentException $e) {
            return Promise\reject($e);
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
                $upload->on('continue', static function () use ($curl) {
                    curl_pause($curl, CURLPAUSE_CONT);
                });

                curl_setopt($curl, CURLOPT_PUT, true);
                curl_setopt($curl, CURLOPT_READFUNCTION, static function ($curl, $fd, $length) use ($upload) {
                    return $upload->read($length);
                });
                if (array_key_exists('content-length', $headers)) {
                    curl_setopt($curl, CURLOPT_INFILESIZE, is_array($headers['content-length']) ? $headers['content-length'][0] : $headers['content-length']);
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

        $headers = $headers + array_change_key_case($this->defaultHeaders);

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

        $binary = (string)@inet_pton($ip);
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

        //@TODO remove any options that will conflict with out internal working. Eg CURLOPT_FILE, CURLOPT_WRITEHEADER, etc.
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
        $multi = curl_multi_init();
        $return = curl_multi_add_handle($multi, $curl);

        if ($return !== 0) {
            curl_multi_close($multi);
            throw new \RuntimeException('Unable to add curl to multi handle, Error:' . $return . ", Msg: " . curl_multi_strerror($return));
        }

        return $multi;
    }

    protected function execRequest($curl, ?UploadBodyStream $upload = null) : PromiseInterface {
        $headerHandle = fopen('php://memory', 'w+');
        if ($headerHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response headers');
        }
        curl_setopt($curl, CURLOPT_WRITEHEADER, $headerHandle);

        $multi = $this->initMulti($curl);

        if ($this->streaming) {
            $responseBody = new ThroughStream();
            $pauseRequested = false;
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody, $multi, &$pauseRequested) {
                static $first = true;
                if ($first) {
                    try {
                        $transaction = $this->state->inProgress[$multi];
                        $res = $this->resolveResponse($multi, $curl);
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
            $responseBody->on('drain', static function () use ($curl, &$pauseRequested) {
                $pauseRequested = false;
                curl_pause($curl, CURLPAUSE_CONT);
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
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody, $multi) {
                static $xfer = 0;
                $len = strlen($data);
                $xfer += $len;

                if ($xfer > $this->maximumSize) {
                    $transaction = $this->state->inProgress[$multi];
                    unset($this->state->inProgress[$multi]);
                    $transaction->deferred->reject(new \OverflowException(
                        'Response body size of ' . $xfer . ' bytes exceeds maximum of ' . $this->maximumSize . ' bytes',
                        \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
                    ));
                    return 0;
                }

                $written = fwrite($responseBody, $data);
                return $written === false ? 0 : $written;
            });
        }

        /** Monitor if we are in upload or download state, used in calculating suggested multi timeouts */
        curl_setopt($curl, CURLOPT_NOPROGRESS, 0);
        curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, function($curl, $dl_total, $dl_xfer, $ul_total, $ul_xfer) use ($multi) {
            if (!$this->state->inProgress->offsetExists($multi)) {
                return 0;
            }

            $status = Transaction::STATUS_CONNECTING;
            $oldStatus = $this->state->inProgress[$multi]->status;
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
                $this->state->inProgress[$multi]->status = $status;
                if ($oldStatus === Transaction::STATUS_UPLOADING) {
                    curl_pause($curl, CURLPAUSE_CONT); //Ensure we are not paused
                }
            }
            return 0; //Keep going
        });

        $deferred = new Deferred(function() use ($multi, &$deferred) {
            if ($this->state->inProgress->offsetExists($multi)) {
                $transaction = $this->state->inProgress[$multi];
                unset($this->state->inProgress[$multi]);
            }
            $deferred->reject(new \RuntimeException('Request cancelled'));
            if (isset($transaction)) {
                $transaction->close();
            }
        });

        $upload?->on('error', static function (Throwable $error) use ($deferred) {
            $deferred->reject($error);
        });

        $this->state->inProgress[$multi] = new Transaction($multi, $curl, $deferred, $responseBody, $headerHandle);

        curl_multi_exec($multi, $_); //GEt it started

        //Kickstart the tick handler; it keeps running until all shared requests are done
        $this->scheduleTick(0.0);

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

    private function curlTick(): void
    {
        if ($this->state->tickTimer !== null) {
            //cancel a wake-up that was superseded by this tick
            $this->loop?->cancelTimer($this->state->tickTimer);
            $this->state->tickTimer = null;
        }

        $nextIterationTimeout = 0.001; //poll at most every 1ms while a transfer is in flight
        $drainBudget = 256; //yield to the event loop after at most 4 MiB so timers/other requests stay responsive
        try {
            foreach($this->state->inProgress as $mh) {
                $transaction = $this->state->inProgress[$mh];
                if ($transaction->isClosed()) {
                    unset($this->state->inProgress[$mh]);
                    continue;
                }
                curl_multi_exec($mh, $still_running);

                //Drain all immediately available data before returning to the event loop:
                //each write callback gets at most 16 KiB, and a full loop iteration costs
                //far more than the read it delivers.
                while ($still_running && $drainBudget-- > 0 && curl_multi_select($mh, 0) > 0) {
                    $nextIterationTimeout = 0;
                    curl_multi_exec($mh, $still_running);
                }

                if ($still_running) {
                    if ($nextIterationTimeout !== 0 && $this->state->inProgress[$mh]->status === Transaction::STATUS_UPLOADING) {
                        $nextIterationTimeout = 0.0001;
                    }
                    continue;
                }

                $deferred = $transaction->deferred;
                $info = curl_multi_info_read($mh);
                if ($info === false) {
                    unset($this->state->inProgress[$mh]);
                    $deferred->reject(new \RuntimeException("curl_multi_info_read returned error on completion"));
                    $transaction->close();
                    continue;
                }
                $curl = $transaction->curl;

                if ($transaction->file instanceof ThroughStream) {
                    $transaction->file->end();
                }

                if ($info['result'] === CURLE_OK) {
                    try {
                        $res = $this->resolveResponse($mh, $curl);
                        unset($this->state->inProgress[$mh]);
                        $deferred->resolve($res);
                    } catch (Throwable $ex) {
                        $deferred->reject($ex);
                    }
                } else {
                    unset($this->state->inProgress[$mh]);
                    if ($info['result'] === CURLE_OPERATION_TIMEDOUT) {
                        $deferred->reject(new \RuntimeException('Request timed out after ' . round((hrtime(true) - $transaction->start)/1e+9, 1) . ' seconds'), CURLE_OPERATION_TIMEDOUT);
                    } elseif ($info['result'] === CURLE_ABORTED_BY_CALLBACK && $this->ssrfProtection) {
                        //the pre-request callback refused a non-public destination before sending the request
                        $deferred->reject(new \RuntimeException('Request to blocked address ' . (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP) . ' refused', CURLE_ABORTED_BY_CALLBACK));
                    } else {
                        $deferred->reject(new \RuntimeException(curl_strerror($info['result']) ?? ('cURL error ' . $info['result']), $info['result']));
                    }
                }
                $transaction->close();
            }
        } finally {
            $this->state->tickScheduled = false;
            if (count($this->state->inProgress) > 0) {
                $this->scheduleTick($nextIterationTimeout);
            }
        }
    }

    /**
     * @param CurlMultiHandle $mh
     * @param CurlHandle $curl
     * @return ResponseInterface
     */
    private function resolveResponse(CurlMultiHandle $mh, CurlHandle $curl): ResponseInterface
    {
        $responseBody = $this->state->inProgress[$mh]->file;
        if (is_resource($responseBody)) {
            stream_set_blocking($responseBody, false);
            rewind($responseBody);
            $responseBody = Utils::streamFor($responseBody);
        }

        /** @var resource $responseHeaderHandle */
        $responseHeaderHandle = $this->state->inProgress[$mh]->headers;
        rewind($responseHeaderHandle);
        $headers = stream_get_contents($responseHeaderHandle);
        //@TODO implement ReactPHP Browser withRejectErrorResponse support
        return $this->constructResponseFromCurl($curl, $headers, $responseBody);
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
     * @param CurlHandle $curl
     * @param string $rawHeaders
     * @param ThroughStream|StreamInterface $body
     * @return ResponseInterface
     */
    private function constructResponseFromCurl(CurlHandle $curl, string $rawHeaders, ThroughStream|StreamInterface $body) : ResponseInterface {
        $redirectCount = curl_getinfo($curl, CURLINFO_REDIRECT_COUNT);
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

        $info = curl_getinfo($curl);
        $info['appconnect_time'] = curl_getinfo($curl,CURLINFO_APPCONNECT_TIME); //appconnect_time is not in the curl_getinfo default result list
        $timing = [];
        foreach(["namelookup_time", "connect_time", "appconnect_time", "pretransfer_time", "redirect_time", "starttransfer_time", "total_time",] as $timingKey) {
            $timing[] = "$timingKey;dur=". $info[$timingKey];
        }
        $headers['ServerTiming'] = $timing;

        $certs = $info['certinfo'] ?? [];
        if (count($certs) > 0) {
            $headers['X-Certificate'] = $certs[0]['Cert'];
        }

        $uploadSize = $info['request_size'] + $info['size_upload'];
        $downloadSize = $info['header_size'] + $info['size_download'];

        $headers['X-Connection'] = [
            "effective_url=" . $info['url'],
            "connection;count=" . curl_getinfo($curl, CURLINFO_NUM_CONNECTS),
            "redirect;count=" . $redirectCount,
            "upload;size=$uploadSize;speed=" . curl_getinfo($curl, CURLINFO_SPEED_UPLOAD_T),
            "download;size=$downloadSize;speed=" . curl_getinfo($curl, CURLINFO_SPEED_DOWNLOAD_T),
        ];

        // determine length of response body
        $length = null;
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (curl_getinfo($curl, CURLINFO_EFFECTIVE_METHOD) === 'HEAD' || ($code >= 100 && $code < 200) || $code == StatusCodeInterface::STATUS_NO_CONTENT || $code == StatusCodeInterface::STATUS_NOT_MODIFIED) {
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

        $curlHttpVersion = curl_getinfo($curl, CURLINFO_HTTP_VERSION);
        $httpVersion = self::httpVersionName((int)$curlHttpVersion);

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

        foreach($this->state->inProgress as $mh) {
            $transaction = $this->state->inProgress[$mh];
            unset($this->state->inProgress[$mh]);
            $transaction->close();
        }
    }
}