<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use CurlMultiHandle;
use Fiber;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
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
use function fopen;
use function preg_split;
use function rewind;
use function stream_get_contents;
use function stream_set_blocking;

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
    ];

    private \SplObjectStorage $inprogress;

    /**
     * @param EventLoop\LoopInterface|null $loop
     * @param array $options
     */
    public function __construct(protected array $options = [], protected ?EventLoop\LoopInterface $loop = null) {
        if (!isset($this->loop)) {
            $this->loop = EventLoop\Loop::get();
        }

        $this->inprogress = new \SplObjectStorage();
    }

    public function get(string $url, array $headers = []) : PromiseInterface {
        $curl = $this->initCurl();
        curl_setopt($curl, CURLOPT_URL, $url);

        if (!empty($headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        $deferred = new Deferred();
        $fileHandle = fopen('php://temp', 'w+');
        if ($fileHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response body');
        }
        $headerHandle = fopen('php://temp', 'w+');
        if ($headerHandle === false) {
            throw new \RuntimeException('Unable to create temporary file for response headers');
        }
        $fiber = $this->initFiber($curl);
        $this->inprogress[$fiber] = [
            'deferred' => $deferred,
            'file' => $fileHandle,
            'headers' => $headerHandle,
        ];
        curl_setopt($curl, CURLOPT_FILE, $fileHandle);
        curl_setopt($curl, CURLOPT_WRITEHEADER, $headerHandle);

        //Kickstart the handler any time we initiate a new request and no requests are currently in the queue
        if (count($this->inprogress) === 1) {
            $this->loop->futureTick($this->curlTick(...));
        }

        return $deferred->promise();
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
            $still_running = null;
            do {
                curl_multi_exec($mh, $still_running);
                if ($still_running) {
                    Fiber::suspend();
                }
            } while ($still_running);
            $info = curl_multi_info_read($mh);
            $curl = $info["handle"];
            curl_multi_remove_handle($mh, $curl);
            curl_multi_close($mh);

            $deferred = $this->inprogress[$fiber]['deferred'];
            if ($info['result'] === CURLE_OK) {
                $responseBodyHandle = $this->inprogress[$fiber]['file'];
                stream_set_blocking($responseBodyHandle, false);
                rewind($responseBodyHandle);
                $responseHeaderHandle = $this->inprogress[$fiber]['headers'];
                rewind($responseHeaderHandle);
                $headers = stream_get_contents($responseHeaderHandle);
                $deferred->resolve($this->constructResponseFromCurl($curl, $headers, $responseBodyHandle)); //@TODO implement ReactPHP Browser withRejectErrorResponse support
            } else {
                $deferred->reject(new ConnectionException($curl));
            }
            curl_close($curl);
        });

        $fiber->start($multi);
        return $fiber;
    }

    private function curlTick(): void
    {
        foreach($this->inprogress as $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->inprogress[$fiber]);
            } else {
                $fiber->resume();
            }
        }

        if (count($this->inprogress)) {
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
        $timing = [];
        foreach(['total_time',"namelookup_time", "connect_time", "pretransfer_time", "starttransfer_time", "redirect_time"] as $timingKey) {
            $timing[] = "$timingKey;dur=". $info[$timingKey];
        }
        $headers['ServerTiming'] = $timing;

        return new Psr7Response(
            curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            $headers,
            \RingCentral\Psr7\stream_for($body),
            curl_getinfo($curl, CURLINFO_HTTP_VERSION),
        );
    }
}
