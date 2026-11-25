<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use SplObjectStorage;

/**
 * Behavioural tests for Browser::withSharedMultiHandle().
 *
 * A single CurlMultiHandle is shared by all requests, but every response must still be
 * settled as soon as its own transfer completes (via curl_multi_info_read()) — a slow
 * download must never delay a fast one.
 */
class SharedMultiHandleTest extends \React\Tests\Http\TestCase
{
    private static ?HttpServer $server = null;
    private static SocketServer $socket;
    private static SplObjectStorage $connections;
    private static string $address;

    public static function setUpBeforeClass(): void
    {
        self::$connections = new SplObjectStorage();

        self::$server = new HttpServer(static function (ServerRequestInterface $request): Response|Promise {
            $path = $request->getUri()->getPath();
            $query = $request->getQueryParams();

            if ($path === '/fast') {
                return Response::plaintext(str_repeat('f', 64 * 1024));
            }

            if ($path === '/stream') {
                return self::streamOverTime((int)($query['bytes'] ?? 8192), (int)($query['ms'] ?? 100));
            }

            if ($path === '/delay') {
                $ms = (int)($query['ms'] ?? 100);
                return new Promise(static function ($resolve) use ($ms): void {
                    Loop::addTimer($ms / 1000, static fn () => $resolve(new Response(200, [], 'delayed')));
                });
            }

            return Response::plaintext('not found')->withStatus(404);
        });

        self::$socket = new SocketServer('127.0.0.1:0');
        self::$socket->on('connection', static function (ConnectionInterface $connection): void {
            self::$connections->offsetSet($connection);
            $connection->on('close', static function () use ($connection): void {
                self::$connections->offsetUnset($connection);
            });
        });
        self::$server->listen(self::$socket);
        self::$address = str_replace('tcp://', 'http://', self::$socket->getAddress());
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
        //snapshot first: detaching while iterating SplObjectStorage skips entries
        $connections = [];
        foreach (self::$connections as $connection) {
            $connections[] = $connection;
        }
        self::$connections = new SplObjectStorage();
        foreach ($connections as $connection) {
            $connection->close();
        }
        self::$socket->close();
    }

    private static function streamOverTime(int $bytes, int $durationMs): Response
    {
        $stream = new ThroughStream();
        $chunkSize = 1024;
        $chunks = (int)ceil($bytes / $chunkSize);
        $sent = 0;

        $timer = Loop::addPeriodicTimer(max($durationMs / 1000 / $chunks, 0.0001), static function () use ($stream, $bytes, $chunkSize, $chunks, &$sent, &$timer): void {
            $length = min($chunkSize, $bytes - $sent);
            $stream->write(str_repeat('s', $length));
            $sent += $length;
            if ($sent >= $bytes) {
                Loop::cancelTimer($timer);
                $stream->end();
            }
        });

        $stream->on('close', static function () use (&$timer): void {
            Loop::cancelTimer($timer);
        });

        return new Response(200, ['Content-Type' => 'text/plain'], $stream);
    }

    private function sharedBrowser(): Browser
    {
        return (new Browser([CURLOPT_TIMEOUT => 30]))->withSharedMultiHandle();
    }

    public function testFastResponseIsNotDelayedBySlowResponse(): void
    {
        $browser = $this->sharedBrowser();

        $start = hrtime(true);
        $completions = [];
        $slow = $browser->get(self::$address . '/stream?bytes=8192&ms=600')->then(static function (ResponseInterface $response) use (&$completions): ResponseInterface {
            $completions['slow'] = hrtime(true);
            return $response;
        });
        $fast = $browser->get(self::$address . '/fast')->then(static function (ResponseInterface $response) use (&$completions): ResponseInterface {
            $completions['fast'] = hrtime(true);
            return $response;
        });

        /** @var ResponseInterface[] $responses */
        $responses = \React\Async\await(\React\Promise\all([$slow, $fast]));

        $this->assertSame(64 * 1024, strlen((string)$responses[1]->getBody()));
        $this->assertSame(8192, strlen((string)$responses[0]->getBody()));

        $this->assertArrayHasKey('fast', $completions);
        $this->assertArrayHasKey('slow', $completions);
        $this->assertLessThan(
            $completions['slow'],
            $completions['fast'],
            'The fast response was only settled after the slow transfer completed (head-of-line blocking)'
        );

        // The slow transfer is constructed to take ~600ms; the fast one must not wait for it
        $fastMs = ($completions['fast'] - $start) / 1e6;
        $this->assertLessThan(500.0, $fastMs, 'Fast response took ' . round($fastMs, 1) . 'ms while a 600ms transfer was in flight');
        $this->assertTrue($browser->isIdle());
    }

    public function testConcurrentStreamsCompleteInDurationOrder(): void
    {
        $browser = $this->sharedBrowser();

        $order = [];
        $promises = [];
        foreach ([100, 300, 600] as $ms) {
            $promises[$ms] = $browser->get(self::$address . '/stream?bytes=8192&ms=' . $ms)
                ->then(static function () use (&$order, $ms): void {
                    $order[] = $ms;
                });
        }

        \React\Async\await(\React\Promise\all($promises));

        $this->assertSame([100, 300, 600], $order);
        $this->assertTrue($browser->isIdle());
    }

    public function testDifferingSizesCompleteIndependently(): void
    {
        $browser = $this->sharedBrowser();

        $completed = [];
        $largeSlow = $browser->get(self::$address . '/stream?bytes=65536&ms=700')->then(static function (ResponseInterface $response) use (&$completed): void {
            $completed['large'] = strlen((string)$response->getBody());
        });
        $smallFast = $browser->get(self::$address . '/stream?bytes=2048&ms=50')->then(static function (ResponseInterface $response) use (&$completed): void {
            $completed['small'] = strlen((string)$response->getBody());
        });

        \React\Async\await(\React\Promise\all([$largeSlow, $smallFast]));

        $this->assertSame(65536, $completed['large']);
        $this->assertSame(2048, $completed['small']);
        $this->assertTrue($browser->isIdle());
    }

    public function testStreamingResponseWithSharedMultiHandle(): void
    {
        $buffer = \React\Async\await(
            $this->sharedBrowser()
                ->requestStreaming('GET', self::$address . '/stream?bytes=32768&ms=100')
                ->then(static fn (ResponseInterface $response) => \React\Promise\Stream\buffer($response->getBody()))
        );

        $this->assertSame(32768, strlen($buffer));
        $this->assertSame(str_repeat('s', 32768), $buffer);
    }

    public function testCancellingOneRequestLeavesOthersRunning(): void
    {
        $browser = $this->sharedBrowser();

        $first = $browser->get(self::$address . '/stream?bytes=4096&ms=400');
        $cancelled = $browser->get(self::$address . '/stream?bytes=4096&ms=400');
        $third = $browser->get(self::$address . '/stream?bytes=4096&ms=400');

        $cancelled->cancel();

        $results = \React\Async\await(\React\Promise\all([
            $first->then(static fn (ResponseInterface $r) => strlen((string)$r->getBody()), static fn () => 'rejected'),
            $cancelled->then(static fn () => 'resolved', static fn () => 'rejected'),
            $third->then(static fn (ResponseInterface $r) => strlen((string)$r->getBody()), static fn () => 'rejected'),
        ]));

        $this->assertSame([4096, 'rejected', 4096], $results);
        $this->assertTrue($browser->isIdle());
    }

    public function testCancelAllFromStreamingCallbackWithSharedMultiHandle(): void
    {
        $browser = $this->sharedBrowser();

        $browser->requestStreaming('GET', self::$address . '/stream?bytes=65536&ms=500')->then(
            static function () use ($browser): void {
                // runs inside curl_multi_exec()'s write callback: teardown must be deferred
                $browser->cancelAll();
            }
        );

        \React\Async\await(\React\Promise\Timer\sleep(0.05));
        $this->assertTrue($browser->isIdle());

        // the shared multi handle must still be usable after cancelling a running transfer
        $response = \React\Async\await($browser->get(self::$address . '/fast'));
        $this->assertSame(64 * 1024, strlen((string)$response->getBody()));
    }

    public function testResponseBufferOverflowWithSharedMultiHandle(): void
    {
        $browser = $this->sharedBrowser()->withResponseBuffer(1024);

        try {
            \React\Async\await($browser->get(self::$address . '/stream?bytes=65536&ms=50'));
            $this->fail('Expected OverflowException');
        } catch (\OverflowException $e) {
            $this->assertStringStartsWith('Response body size of ', $e->getMessage());
        }

        $this->assertTrue($browser->isIdle());

        $response = \React\Async\await($this->sharedBrowser()->get(self::$address . '/fast'));
        $this->assertSame(64 * 1024, strlen((string)$response->getBody()));
    }

    public function testSharedAndPerRequestModesCoexistOnClones(): void
    {
        $shared = $this->sharedBrowser();
        $perRequest = $shared->withSharedMultiHandle(false);

        $responses = \React\Async\await(\React\Promise\all([
            $shared->get(self::$address . '/stream?bytes=8192&ms=200'),
            $perRequest->get(self::$address . '/stream?bytes=8192&ms=200'),
            $shared->get(self::$address . '/fast'),
            $perRequest->get(self::$address . '/fast'),
        ]));

        $this->assertSame([8192, 8192, 65536, 65536], array_map(
            static fn (ResponseInterface $response) => strlen((string)$response->getBody()),
            $responses
        ));
        $this->assertTrue($shared->isIdle());
    }

    public function testSequentialRequestsReuseConnectionWithSharedMultiHandle(): void
    {
        $browser = $this->sharedBrowser();

        $first = \React\Async\await($browser->get(self::$address . '/fast'));
        $second = \React\Async\await($browser->get(self::$address . '/fast'));

        $this->assertContains('connection;count=1', $first->getHeader('X-Connection'));
        $this->assertContains('connection;count=0', $second->getHeader('X-Connection'));
    }
}
