<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use Evenement\EventEmitter;
use Psr\Http\Message\ServerRequestInterface;
use React\Async;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use SplObjectStorage;
use function fwrite;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function ini_set;
use function json_decode;
use function microtime;
use function preg_match;
use function str_repeat;
use function str_replace;
use function strlen;

/**
 * A readable stream that emits the same chunk a fixed number of times.
 *
 * Lets the test server (and the streaming upload client) deliver large bodies without
 * ever materialising them: react/socket's write buffer otherwise holds the whole body
 * and substr()s the remainder on every write event (O(n^2) copying).
 */
final class ChunkRepeater extends EventEmitter implements ReadableStreamInterface
{
    private string $chunk;

    private int $remaining;

    private bool $paused = false;

    private bool $closed = false;

    private bool $scheduled = false;

    public function __construct(string $chunk, int $count)
    {
        $this->chunk = $chunk;
        $this->remaining = $count;

        $this->schedule();
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        if ($this->paused) {
            $this->paused = false;
            $this->schedule();
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this, $dest, $options);
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->remaining = 0;
        $this->emit('close');
        $this->removeAllListeners();
    }

    private function schedule(): void
    {
        if ($this->scheduled || $this->paused || $this->closed) {
            return;
        }

        $this->scheduled = true;
        Loop::futureTick(function () {
            $this->scheduled = false;

            if ($this->paused || $this->closed) {
                return;
            }

            if ($this->remaining > 0) {
                --$this->remaining;
                $this->emit('data', [$this->chunk]);
                $this->schedule();
                return;
            }

            $this->emit('end');
            $this->close();
        });
    }
}

/**
 * Local throughput measurements for buffered and streaming uploads and downloads.
 * Results are printed to STDERR; the assertions only guard against order-of-magnitude
 * regressions so the tests stay stable on loaded CI.
 *
 * @group performance
 */
class PerformanceTest extends \React\Tests\Http\TestCase
{
    private const MIB = 1048576;

    private const CHUNK = 65536;

    private const TIMEOUT = 120;

    private static ?HttpServer $http = null;

    private static ?SocketServer $socket = null;

    private static ?string $base = null;

    private static SplObjectStorage $connections;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');

        self::$connections = new SplObjectStorage();

        self::$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();

            if ($path === '/upload') {
                return new Promise(static function ($resolve) use ($request) {
                    $body = $request->getBody();
                    $hash = hash_init('xxh3');
                    $length = 0;
                    $resolved = false;
                    $body->on('data', static function ($data) use ($hash, &$length) {
                        hash_update($hash, $data);
                        $length += strlen($data);
                    });
                    $body->on('close', static function () use (&$length, &$resolved, $resolve, $hash) {
                        if ($resolved) {
                            return;
                        }
                        $resolved = true;
                        $resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                            'bytes' => $length,
                            'hash' => hash_final($hash),
                        ])));
                    });
                });
            }

            if (preg_match('#^/download/(\d+)$#', $path, $match) === 1) {
                $mib = (int)$match[1];
                return new Response(
                    200,
                    [
                        'Content-Type' => 'application/octet-stream',
                        'Content-Length' => (string)($mib * self::MIB),
                    ],
                    new ChunkRepeater(self::chunk(), $mib * self::MIB / self::CHUNK)
                );
            }

            return new Response(404);
        });

        self::$http->on('error', static function (\Throwable $e) {
            fwrite(STDERR, "\n[perf] server error: " . $e->getMessage() . "\n");
        });

        self::$socket = new SocketServer('127.0.0.1:0');
        self::$socket->on('connection', static function (ConnectionInterface $connection) {
            self::$connections->attach($connection);
            $connection->on('close', static function () use ($connection) {
                self::$connections->detach($connection);
            });
        });
        self::$http->listen(self::$socket);
        self::$base = str_replace('tcp://', 'http://', self::$socket->getAddress());
    }

    public static function tearDownAfterClass(): void
    {
        self::$http = null;
        foreach (self::$connections as $connection) {
            $connection->close();
            self::$connections->detach($connection);
        }
        self::$socket?->close();
        self::$socket = null;
    }

    public function payloadSizes(): array
    {
        return [
            '16 MiB' => [16],
            '100 MiB' => [100],
        ];
    }

    /** @dataProvider payloadSizes */
    public function testBufferedDownloadThroughput(int $mib): void
    {
        $browser = (new Browser([CURLOPT_TIMEOUT => self::TIMEOUT]))->withResponseBuffer(($mib + 1) * self::MIB);

        $start = microtime(true);
        $response = Async\await($browser->get(self::$base . '/download/' . $mib));
        $elapsed = microtime(true) - $start;

        $this->assertSame($mib * self::MIB, strlen((string)$response->getBody()));
        $this->assertGreaterThan(1.0, $mib / $elapsed, 'download throughput below 1 MiB/s');
        $this->report('buffered download', $mib, $elapsed);
    }

    /** @dataProvider payloadSizes */
    public function testStreamingDownloadThroughput(int $mib): void
    {
        $browser = new Browser([CURLOPT_TIMEOUT => self::TIMEOUT]);

        $start = microtime(true);
        $response = Async\await($browser->requestStreaming('GET', self::$base . '/download/' . $mib));

        $body = $response->getBody();
        $this->assertInstanceOf(ReadableStreamInterface::class, $body);
        $bytes = $this->countBytes($body);
        $elapsed = microtime(true) - $start;

        $this->assertSame($mib * self::MIB, $bytes);
        $this->assertGreaterThan(1.0, $mib / $elapsed, 'download throughput below 1 MiB/s');
        $this->report('streaming download', $mib, $elapsed);
    }

    /** @dataProvider payloadSizes */
    public function testBufferedUploadThroughput(int $mib): void
    {
        $body = self::payload($mib);
        $browser = new Browser([CURLOPT_TIMEOUT => self::TIMEOUT]);

        $start = microtime(true);
        $response = Async\await($browser->post(self::$base . '/upload', ['Content-Length' => strlen($body)], $body));
        $elapsed = microtime(true) - $start;

        $data = json_decode((string)$response->getBody(), true);
        $this->assertSame(strlen($body), $data['bytes']);
        $this->assertSame(hash('xxh3', $body), $data['hash']);
        $this->assertGreaterThan(1.0, $mib / $elapsed, 'upload throughput below 1 MiB/s');
        $this->report('buffered upload', $mib, $elapsed);
    }

    /** @dataProvider payloadSizes */
    public function testStreamingUploadThroughput(int $mib): void
    {
        $source = new ChunkRepeater(self::chunk(), $mib * self::MIB / self::CHUNK);
        $browser = new Browser([CURLOPT_TIMEOUT => self::TIMEOUT]);

        $start = microtime(true);
        $response = Async\await($browser->post(self::$base . '/upload', ['Content-Length' => $mib * self::MIB], $source));
        $elapsed = microtime(true) - $start;

        $data = json_decode((string)$response->getBody(), true);
        $this->assertSame($mib * self::MIB, $data['bytes']);
        $this->assertSame(self::payloadHash($mib), $data['hash']);
        $this->assertGreaterThan(1.0, $mib / $elapsed, 'upload throughput below 1 MiB/s');
        $this->report('streaming upload', $mib, $elapsed);
    }

    private static function chunk(): string
    {
        static $chunk = null;

        return $chunk ??= str_repeat('abcdefgh', self::CHUNK / 8);
    }

    private static function payload(int $mib): string
    {
        return str_repeat(self::chunk(), $mib * self::MIB / self::CHUNK);
    }

    private static function payloadHash(int $mib): string
    {
        $hash = hash_init('xxh3');
        $repeats = $mib * self::MIB / self::CHUNK;

        for ($i = 0; $i < $repeats; ++$i) {
            hash_update($hash, self::chunk());
        }

        return hash_final($hash);
    }

    private function report(string $name, int $mib, float $elapsed): void
    {
        fwrite(STDERR, sprintf("\n[perf] %s %d MiB in %.3fs = %.1f MiB/s\n", $name, $mib, $elapsed, $mib / $elapsed));
    }

    /**
     * Counts bytes received on a response body without buffering it.
     */
    private function countBytes(ReadableStreamInterface $stream): int
    {
        $bytes = 0;
        $stream->on('data', static function ($chunk) use (&$bytes) {
            $bytes += strlen($chunk);
        });

        Async\await(new Promise(static function ($resolve, $reject) use ($stream) {
            $stream->on('close', static function () use ($resolve) {
                $resolve(null);
            });
            $stream->on('error', static function (\Throwable $e) use ($reject) {
                $reject($e);
            });
        }));

        return $bytes;
    }
}
