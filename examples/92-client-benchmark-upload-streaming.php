<?php

// a) simple 1 MB upload benchmark against public HTTP endpoint
// $ php examples/92-client-benchmark-upload.php http://httpbin.org/post 1
//
// b) local 10 GB upload benchmark against localhost address to avoid network overhead
//
// b1) first run example HTTP server:
// $ php examples/63-server-streaming-request.php 8080
//
// b2) run HTTP client sending a 10 GB upload
// $ php examples/92-client-benchmark-upload.php http://localhost:8080/ 10000

use Evenement\EventEmitter;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use EdgeTelemetrics\React\Http\Browser;
use React\Stream\ReadableResourceStream;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

ini_set('memory_limit',-1);

require __DIR__ . '/../vendor/autoload.php';

if (extension_loaded('xdebug')) {
    echo 'NOTICE: The "xdebug" extension is loaded, this has a major impact on performance.' . PHP_EOL;
}

/** A readable stream that can emit a lot of data */
class ChunkRepeater extends EventEmitter implements ReadableStreamInterface
{
    private $chunk;
    private $count;
    private $position = 0;
    private $paused = true;
    private $closed = false;

    private \React\EventLoop\TimerInterface|null $timer = null;

    public function __construct($chunk, $count)
    {
        $this->chunk = $chunk;
        $this->count = $count;

        error_log('chunk size: ' . strlen($chunk) . ', count: ' . $count . ', max: ' . (strlen($chunk) * $count));
    }

    public function pause()
    {
        $this->paused = true;
        //error_log('paused');
        if (isset($this->timer)) {
            Loop::cancelTimer($this->timer);
        }
    }

    public function resume()
    {
        //echo 'resume called';
        if (!$this->paused || $this->closed) {
            return;
        }

        // keep emitting until stream is paused
        $this->paused = false;

        $this->timer = Loop::addPeriodicTimer(0.000000000000001, function () {
                if ($this->paused) {
                    Loop::cancelTimer($this->timer);
                    return;
                }
                ++$this->position;
                $this->emit('data', array($this->chunk));

                // end once the last chunk has been written
                if ($this->position >= $this->count) {
                    Loop::cancelTimer($this->timer);
                    $this->emit('end');
                    $this->close();
                }
        });

    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return Util::pipe($this, $dest, $options);
    }

    public function isReadable()
    {
        return !$this->closed;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->count = 0;
        $this->paused = true;
        $this->emit('close');
    }

    public function getPosition()
    {
        return $this->position * strlen($this->chunk);
    }
}

$client = new Browser();

$url = isset($argv[1]) ? $argv[1] : 'http://httpbin.org/post';
$n = isset($argv[2]) ? $argv[2] : 10;


$bytes = 1000000*$n;
$string = str_repeat('x', 8192*6);
$chunks = (int)floor($bytes / strlen($string));
$bytes = $chunks * strlen($string);
$source = new ChunkRepeater($string, $chunks);
Loop::futureTick(function () use ($source) {
    $source->resume();
});


/*$file = fopen(__DIR__ . '/../tests/1GB.bin','r');
stream_set_blocking($file, false);
$source = new ReadableResourceStream($file, null, 8192*10);
$bytes = 1073741824;*/

echo 'POSTing ' . $n . ' MB to ' . $url . PHP_EOL;

$start = microtime(true);
$report = Loop::addPeriodicTimer(0.1, function () use ($source, $start) {
    printf("\r%d bytes in %0.3fs...", $source->getPosition(), microtime(true) - $start);
});

$client->post($url, array('Content-Length' => $bytes, 'Connection' => 'close'), $source)->then(function (ResponseInterface $response) use ($source, $report, $start, $bytes) {
    $now = microtime(true);
    Loop::cancelTimer($report);

    printf("\r%d bytes in %0.3fs => %.1f MB/s\n", $bytes, $now - $start, $bytes / ($now - $start) / 1000000);

    echo rtrim(preg_replace('/x{5,}/','x…', (string) $response->getBody()), PHP_EOL) . PHP_EOL;

    print_r($response->getHeaders()['ServerTiming']);
}, function (Exception $e) use ($report) {
    Loop::cancelTimer($report);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
