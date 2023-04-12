<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use CurlMultiHandle;
use Fiber;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream;
use RingCentral\Psr7\Response as Psr7Response;

use function count;
use function curl_close;
use function curl_getinfo;
use function curl_init;
use function curl_multi_add_handle;
use function curl_multi_close;
use function curl_multi_exec;
use function curl_multi_info_read;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_multi_strerror;
use function curl_setopt;
use function curl_setopt_array;
use function fopen;
use function is_array;
use function preg_split;
use function rewind;
use function stream_get_contents;
use function stream_set_blocking;
use function strtolower;
use function strtoupper;

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
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CERTINFO => true,
        CURLOPT_TCP_NODELAY => true,
        //CURLOPT_HSTS_ENABLE => true, //PHP8.2
    ];

    protected array $defaultHeaders = [
        'User-Agent' => 'EdgeTelemetricsBrowser/1',
    ];

    private \SplObjectStorage $inProgress;

    /**
     * @param EventLoop\LoopInterface|null $loop
     * @param array $options
     */
    public function __construct(protected array $options = [], protected ?EventLoop\LoopInterface $loop = null) {
        if (!isset($this->loop)) {
            $this->loop = EventLoop\Loop::get();
        }

        $this->inProgress = new \SplObjectStorage();
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

    public function requestStreaming($method, $url, array $headers = [], $body = ''): PromiseInterface {
        return $this->request($method, $url, $headers, $body);
    }

    /**
     * @param $method
     * @param $url
     * @param array $headers
     * @param $body
     * @return PromiseInterface
     */
    public function request($method, $url, array $headers = [], $body = ''): PromiseInterface
    {
        $curl = $this->initCurl();

        if ($body instanceof ReadableResourceStream ) {
            throw new \RuntimeException('Support not implemented');
        }

        $method = strtoupper($method);
        $curl_opts = match($method) {
            'HEAD' => [ CURLOPT_NOBODY => true ],
            'GET' => [],
            'POST','PUT','DELETE','PATCH' => [ CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body, ],
            'OPTIONS' => [ CURLOPT_NOBODY => true, CURLOPT_CUSTOMREQUEST => 'OPTIONS', ]
        };

        $curl_opts[CURLOPT_URL] = $url;

        $headers = $headers + $this->defaultHeaders;

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

        if ($curl === false) {
            throw new \RuntimeException('Unable to init curl');
        }

        //@TODO remove any options that will conflict with out internal working. Eg CURLOPT_FILE, CURLOPT_WRITEHEADER, etc.
        $options = $this->options + self::DEFAULT_CURL_OPTIONS;

        if ($this->disableCurlCache) {
            $options = $options + static::NO_CACHE_OPTIONS;
        }

        curl_setopt_array($curl, $options);

        return $curl;
    }

    private function initFiber(CurlHandle $curl) : Fiber {
        $multi = curl_multi_init();
        $return = curl_multi_add_handle($multi, $curl);

        if ($return !== 0) {
            curl_multi_close($multi);
            throw new \RuntimeException('Unable to add curl to multi handle, Error:' . $return . ", Msg: " . curl_multi_strerror($return));
        }

        $fiber = new Fiber(function (CurlMultiHandle $mh) use(&$fiber) {
            Fiber::suspend();
            $cancel = false;
            do {
                curl_multi_exec($mh, $still_running);
                if ($still_running) {
                    $cancel = Fiber::suspend();
                    if ($cancel) { echo 'cancelling'; }
                }
            } while ($still_running && !$cancel);

            if ($cancel) {
                return;
            }

            $info = curl_multi_info_read($mh);
            $curl = $info["handle"];
            curl_multi_remove_handle($mh, $curl);
            curl_multi_close($mh);

            $deferred = $this->inProgress[$fiber]['deferred'];
            if ($info['result'] === CURLE_OK) {
                /** @var resource $responseBodyHandle */
                $responseBodyHandle = $this->inProgress[$fiber]['file'];
                stream_set_blocking($responseBodyHandle, false);
                rewind($responseBodyHandle);
                /** @var resource $responseHeaderHandle */
                $responseHeaderHandle = $this->inProgress[$fiber]['headers'];
                rewind($responseHeaderHandle);
                $headers = stream_get_contents($responseHeaderHandle);
                //@TODO implement ReactPHP Browser withRejectErrorResponse support
                $deferred->resolve($this->constructResponseFromCurl($curl, $headers, $responseBodyHandle));
            } else {
                $deferred->reject(new ConnectionException($curl));
            }
            curl_close($curl);
        });

        $fiber->start($multi);
        return $fiber;
    }

    protected function execRequest($curl) : PromiseInterface {
        $fileHandle = fopen('php://temp', 'w+');
        if ($fileHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response body');
        }
        $headerHandle = fopen('php://temp', 'w+');
        if ($headerHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response headers');
        }
        curl_setopt($curl, CURLOPT_FILE, $fileHandle);
        curl_setopt($curl, CURLOPT_WRITEHEADER, $headerHandle);

        $fiber = $this->initFiber($curl);

        $deferred = new Deferred(function() use ($fiber) {
            if (!$fiber->isTerminated()) {
                $fiber->resume(true);
            }
        });

        $this->inProgress[$fiber] = [
            'deferred' => $deferred,
            'file' => $fileHandle,
            'headers' => $headerHandle,
        ];

        //Kickstart the handler any time we initiate a new request and no requests are currently in the queue
        if (count($this->inProgress) === 1) {
            $this->loop->futureTick($this->curlTick(...));
        }

        return $deferred->promise();
    }

    private function curlTick(): void
    {
        foreach($this->inProgress as $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->inProgress[$fiber]);
            } else {
                $fiber->resume(false);
            }
        }

        if (count($this->inProgress)) {
            $this->loop->addTimer(0.01, $this->curlTick(...)); //use a timer instead of futureTick so that we don't lock the CPU at 100%
        }
    }

    private function constructResponseFromCurl(CurlHandle $curl, string $rawHeaders, $body) : ResponseInterface {
        $headers = [];
        $lines = preg_split('/(\\r?\\n)/', trim($rawHeaders), -1);
        array_shift($lines);
        foreach($lines as $headerLine) {
            $parts = explode(':', $headerLine, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';
            $headers[$key][] = $value;
        }

        $info = curl_getinfo($curl);
        $info['appconnect_time'] = curl_getinfo($curl,CURLINFO_APPCONNECT_TIME);
        $timing = [];
        foreach(["namelookup_time", "connect_time", "appconnect_time", "pretransfer_time", "redirect_time", "starttransfer_time", "total_time",] as $timingKey) {
            $timing[] = "$timingKey;dur=". $info[$timingKey];
        }
        $headers['ServerTiming'] = $timing;

        $certs = $info['certinfo'] ?? [];
        if (count($certs) > 0) {
            $headers['X-Certificate'] = $certs[0]['Cert'];
        }

        return new Psr7Response(
            curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            $headers,
            \RingCentral\Psr7\stream_for($body),
            curl_getinfo($curl, CURLINFO_HTTP_VERSION),
        );
    }
}
