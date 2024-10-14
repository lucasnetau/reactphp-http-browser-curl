<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use CurlMultiHandle;
use EdgeTelemetrics\React\Http\Io\UploadBodyStream;
use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop;
use React\Http\Io\ReadableBodyStream;
use React\Http\Message\ResponseException;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

use React\Stream\ThroughStream;
use React\Http\Message\Uri;
use Throwable;
use function array_change_key_case;
use function array_key_exists;
use function count;
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
use function explode;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function is_array;
use function is_resource;
use function preg_split;
use function property_exists;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

class Browser {
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
        //CURLOPT_VERBOSE => true,
        //CURLINFO_HEADER_OUT => true,
        //CURLOPT_HSTS_ENABLE => true, //PHP8.2
    ];


    protected array $defaultHeaders = [
        'User-Agent' => 'EdgeTelemetricsBrowser/1',
    ];

    private \SplObjectStorage $inProgress;

    private bool $streaming = false;

    private bool $followRedirects = true;

    private null|float|int $timeout = null;

    private int|null $maxRedirects = 20;

    private $baseUrl;

    private int $maximumSize = 16777216; // 16 MiB = 2^24 bytes;

    private bool $obeySuccessCode = true;

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

        $this->inProgress = new \SplObjectStorage();
        
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        $this->curlShare = $share;
    }

    public function isIdle() : bool {
        return count($this->inProgress) === 0;
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
        $browser->inProgress = new \SplObjectStorage();
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

    public function withProtocolVersion(string $protocolVersion)
    {
        $version = match($protocolVersion) {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2' => CURL_HTTP_VERSION_2,
            default => CURL_HTTP_VERSION_NONE
        };
        return $this->withOptions(array(
            'httpVersion' => $version,
        ));
    }

    public function withResponseBuffer($maximumSize)
    {
        return $this->withOptions(array(
            'maximumSize' => $maximumSize
        ));
    }

    /**
     * @param $method
     * @param $url
     * @param array $headers
     * @param string|ReadableStreamInterface $body
     * @return PromiseInterface
     */
    public function request($method, $url, array $headers = [], string|ReadableStreamInterface $body = ''): PromiseInterface
    {
        if ($this->baseUrl !== null) {
            // ensure we're actually below the base URL
            $url = Uri::resolve(new Uri($this->baseUrl), new Uri($url));
        } else {
            $url = new Uri($url);
            if ($url->getHost() === '') {
                return Promise\reject(
                    new \InvalidArgumentException(
                        'Invalid request URL given'
                    )
                );
            }
        }

        $curl = $this->initCurl();

        $headers = array_change_key_case($headers, CASE_LOWER);

        if ($body instanceof ReadableStreamInterface ) {
            $upload = new UploadBodyStream($body);
            $upload->on('pause', static function () use ($curl) {
                curl_pause($curl, CURLPAUSE_SEND);
            });
            $upload->on('continue', static function () use ($curl) {
                curl_pause($curl, CURLPAUSE_CONT);
            });

            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_INFILE, $upload->getReadableStream());
            if (array_key_exists('content-length', $headers)) {
                curl_setopt($curl, CURLOPT_INFILESIZE, is_array($headers['content-length']) ? $headers['content-length'][0] : $headers['content-length']);
                $headers['transfer-encoding'] = '';
            }
        } elseif (!in_array($method, ['HEAD','OPTIONS'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $method = strtoupper($method);
        $curl_opts = match($method) {
            'HEAD' => [ CURLOPT_NOBODY => true, ],
            'GET' => [ CURLOPT_HTTPGET => true, ],
            'POST','PUT','DELETE','PATCH' => [ CURLOPT_CUSTOMREQUEST => $method, ],
            'OPTIONS' => [ CURLOPT_NOBODY => true, CURLOPT_CUSTOMREQUEST => 'OPTIONS', ]
        };

        $curl_opts[CURLOPT_HTTP_VERSION] = $this->httpVersion;

        if ($this->timeout !== null && $this->timeout >=1)
        {
            $curl_opts[CURLOPT_TIMEOUT_MS] = $this->timeout*1000;
        }

        $curl_opts[CURLOPT_FOLLOWLOCATION] = $this->followRedirects;
        $curl_opts[CURLOPT_MAXREDIRS] = $this->maxRedirects;

        $curl_opts[CURLOPT_URL] = $url;

        $headers = $headers + array_change_key_case($this->defaultHeaders);

        if (($headers['connection'] ?? '') === 'close') {
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

        return $this->execRequest($curl);
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

        if ($curl === false || $curl === null) {
            throw new \RuntimeException('Unable to init curl');
        }

        //@TODO remove any options that will conflict with out internal working. Eg CURLOPT_FILE, CURLOPT_WRITEHEADER, etc.
        $options = $this->options + self::DEFAULT_CURL_OPTIONS;

        if ($this->disableCurlCache) {
            $options = $options + static::NO_CACHE_OPTIONS;
        } else {
            curl_setopt($curl, CURLOPT_SHARE, $this->curlShare);
        }

        curl_setopt_array($curl, $options);

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

    protected function execRequest($curl) : PromiseInterface {
        $headerHandle = fopen('php://memory', 'w+');
        if ($headerHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response headers');
        }
        curl_setopt($curl, CURLOPT_WRITEHEADER, $headerHandle);

        $multi = $this->initMulti($curl);

        if ($this->streaming) {
            $responseBody = new ThroughStream();
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody, $multi) {
                static $first = true;
                if ($first) {
                    $this->resolveResponse($multi, $curl);
                    $first = false;
                }

                $responseBody->write($data);
                return strlen($data);
            });
        } else {
            $responseBody = fopen('php://temp', 'w+');
            if ($responseBody === false) {
                throw new \RuntimeException('Unable to create temporary file for response body');
            }
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($responseBody, $multi) {
                static $xfer = 0;
                $len = strlen($data);
                $xfer += $len;

                if ($xfer > $this->maximumSize) {
                    $transaction = $this->inProgress[$multi];
                    unset($this->inProgress[$multi]);
                    $transaction->deferred->reject(new \OverflowException(
                        'Response body size of ' . $xfer . ' bytes exceeds maximum of ' . $this->maximumSize . ' bytes',
                        \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
                    ));
                    $transaction->close();
                    return 0;
                }

                fwrite($responseBody, $data);
                return $len;
            });
        }

        /** Monitor if we are in upload or download state, used in calculating suggested multi timeouts */
        curl_setopt($curl, CURLOPT_NOPROGRESS, 0);
        curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, function($curl, $dl_total, $dl_xfer, $ul_total, $ul_xfer) use ($multi) {
            if (!$this->inProgress->contains($multi)) {
                return 0;
            }

            $status = Transaction::STATUS_CONNECTING;
            $oldStatus = $this->inProgress[$multi]->status;
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
                $this->inProgress[$multi]->status = $status;
                if ($oldStatus === Transaction::STATUS_UPLOADING) {
                    curl_pause($curl, CURLPAUSE_CONT); //Ensure we are not paused
                }
            }
            return 0; //Keep going
        });

        $deferred = new Deferred(function() use ($multi, &$deferred) {
            $deferred->reject(new \RuntimeException('Request cancelled'));
            if ($this->inProgress->contains($multi)) {
                $transaction = $this->inProgress[$multi];
                unset($this->inProgress[$multi]);
                $transaction->close();
            }
        });

        $this->inProgress[$multi] = new Transaction($multi, $curl, $deferred, $responseBody, $headerHandle);

        curl_multi_exec($multi, $_); //GEt it started

        //Kickstart the handler any time we initiate a new request and no requests are currently in the queue
        if (count($this->inProgress) === 1) {
            $this->loop->futureTick($this->curlTick(...));
        }

        return $deferred->promise();
    }

    private function curlTick(): void
    {
        $nextIterationTimeout = 0.1; //100ms suggested by Curl
        foreach($this->inProgress as $mh) {
            $transaction = $this->inProgress[$mh];
            curl_multi_exec($mh, $still_running);
            if ($still_running) {
                if ($nextIterationTimeout !== 0) {
                    if (curl_multi_select($mh, 0) > 0) {
                        $nextIterationTimeout = 0;
                    } elseif ($this->inProgress[$mh]->status === Transaction::STATUS_UPLOADING) {
                        $nextIterationTimeout = 0.0001;
                    }
                }
                continue;
            }

            $deferred = $transaction->deferred;
            $info = curl_multi_info_read($mh);
            if ($info === false) {
                unset($this->inProgress[$mh]);
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
                    unset($this->inProgress[$mh]);
                    $deferred->resolve($res);
                } catch (Throwable $ex) {
                    $deferred->reject($ex);
                }
            } else {
                unset($this->inProgress[$mh]);
                if ($info['result'] === CURLE_OPERATION_TIMEDOUT) {
                    $deferred->reject(new \RuntimeException('Request timed out after ' . $this->timeout . ' seconds'), CURLE_OPERATION_TIMEDOUT);
                } else {
                    $deferred->reject(new \RuntimeException(curl_strerror($info['result']), $info['result']));
                }
            }
            $transaction->close();
        }

        if (count($this->inProgress)) {
            if ($nextIterationTimeout > 0) {
                $this->loop->addTimer($nextIterationTimeout,
                    $this->curlTick(...)); //use a timer instead of futureTick so that we don't lock the CPU at 100%
            } else {
                $this->loop->futureTick($this->curlTick(...));
            }
        }
    }

    private function resolveResponse($mh, $curl): ResponseInterface
    {
        $responseBody = $this->inProgress[$mh]->file;
        if (is_resource($responseBody)) {
            stream_set_blocking($responseBody, false);
            rewind($responseBody);
            $responseBody = Utils::streamFor($responseBody);
        }

        /** @var resource $responseHeaderHandle */
        $responseHeaderHandle = $this->inProgress[$mh]->headers;
        rewind($responseHeaderHandle);
        $headers = stream_get_contents($responseHeaderHandle);
        //@TODO implement ReactPHP Browser withRejectErrorResponse support
        return $this->constructResponseFromCurl($curl, $headers, $responseBody);
    }

    private function constructResponseFromCurl(CurlHandle $curl, string $rawHeaders, $body) : ResponseInterface {
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
        } elseif (array_key_exists('Content-Length', $headers)) {
            $length = (int)$headers['Content-Length'][0];
        }

        if ($body instanceof ThroughStream) {
            $body = new ReadableBodyStream($body, $length);
        }

        $httpVersion = match(curl_getinfo($curl, CURLINFO_HTTP_VERSION)) {
            //@TODO check if we need to parse all the CURL_HTTP_VERSION_* values
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2 => '2',
            CURL_HTTP_VERSION_NONE, CURL_HTTP_VERSION_1_0 => '1.0',
        };

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

    public function cancelAll() : void {
        foreach($this->inProgress as $mh) {
            $transaction = $this->inProgress[$mh];
            unset($this->inProgress[$mh]);
            $transaction->close();
        }
    }

    public function __destruct() {
        $this->cancelAll();
    }
}