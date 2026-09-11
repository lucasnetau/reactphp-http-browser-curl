<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ServerRequestInterface;
use React\Async;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use SplObjectStorage;
use function fwrite;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function json_decode;
use function microtime;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;

/**
 * Local throughput measurements. Results are printed to STDERR; the assertions only
 * guard against order-of-magnitude regressions so the tests stay stable on loaded CI.
 *
 * @group performance
 */
class PerformanceTest extends \React\Tests\Http\TestCase
{
    private const MIB = 1048576;

    private const PAYLOAD_MIB = 16;

    private static ?HttpServer $http = null;

    private static ?SocketServer $socket = null;

    private static ?string $base = null;

    private static SplObjectStorage $connections;

    public static function setUpBeforeClass(): void
    {
        self::$connections = new SplObjectStorage();
        $payload = str_repeat('abcdefgh', (self::PAYLOAD_MIB * self::MIB) / 8);

        self::$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) use ($payload) {
            if ($request->getUri()->getPath() === '/upload') {
                return new Promise(static function ($resolve) use ($request) {
                    $body = $request->getBody();
                    $hash = hash_init('sha256');
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
                            'sha256' => hash_final($hash),
                        ])));
                    });
                });
            }

            return new Response(200, ['Content-Type' => 'application/octet-stream'], $payload);
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

    public function testDownloadThroughput(): void
    {
        $browser = (new Browser([CURLOPT_TIMEOUT => 30]))->withResponseBuffer(64 * self::MIB);

        $start = microtime(true);
        $response = Async\await($browser->get(self::$base . '/download'));
        $elapsed = microtime(true) - $start;

        $this->assertSame(self::PAYLOAD_MIB * self::MIB, strlen((string)$response->getBody()));
        $this->assertGreaterThan(1.0, self::PAYLOAD_MIB / $elapsed, 'download throughput below 1 MiB/s');
        $this->report('download', self::PAYLOAD_MIB, $elapsed);
    }

    public function testUploadThroughput(): void
    {
        $body = str_repeat('abcdefgh', (self::PAYLOAD_MIB * self::MIB) / 8);
        $browser = new Browser([CURLOPT_TIMEOUT => 30]);

        $stream = new ThroughStream();
        $this->pump($stream, $body, 256 * 1024);

        $start = microtime(true);
        $response = Async\await($browser->post(self::$base . '/upload', ['Content-Length' => strlen($body)], $stream));
        $elapsed = microtime(true) - $start;

        $data = json_decode((string)$response->getBody(), true);
        $this->assertSame(strlen($body), $data['bytes']);
        $this->assertSame(hash('sha256', $body), $data['sha256']);
        $this->assertGreaterThan(1.0, self::PAYLOAD_MIB / $elapsed, 'upload throughput below 1 MiB/s');
        $this->report('upload', self::PAYLOAD_MIB, $elapsed);
    }

    private function report(string $name, int $mib, float $elapsed): void
    {
        fwrite(STDERR, sprintf("\n[perf] %s %d MiB in %.3fs = %.1f MiB/s\n", $name, $mib, $elapsed, $mib / $elapsed));
    }

    /**
     * Feeds a ThroughStream as fast as the request accepts it, respecting backpressure.
     */
    private function pump(ThroughStream $stream, string $body, int $chunkSize): void
    {
        $offset = 0;
        $length = strlen($body);

        $pump = null;
        $pump = static function () use (&$pump, $stream, $body, $length, $chunkSize, &$offset) {
            if ($offset >= $length) {
                $stream->end();
                return;
            }
            $chunk = substr($body, $offset, $chunkSize);
            $offset += $chunkSize;
            if ($stream->write($chunk) === false) {
                $stream->once('drain', $pump);
            } else {
                Loop::futureTick($pump);
            }
        };

        Loop::futureTick($pump);
    }
}
