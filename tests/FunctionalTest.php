<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use EdgeTelemetrics\React\Http\Io\NetworkException;
use EdgeTelemetrics\React\Http\Io\RequestException;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Stream\ThroughStream;
use Throwable;
use function gc_collect_cycles;
use function str_replace;

/**
 * Functional Tests
 */
class FunctionalTest extends \React\Tests\Http\TestCase
{
    private static ?HttpServer $server;

    private static ?\React\Socket\SocketServer $socket;

    private static \SplObjectStorage $connections;

    private static ?string $testServerAddress;

    public static function setUpBeforeClass(): void
    {
        $server = new \React\Http\HttpServer(
            function (\Psr\Http\Message\ServerRequestInterface $request) {
                try {
                    $path = $request->getUri()->getPath();
                    $method = $request->getMethod();

                    if ($path === '/echo') {
                        return Response::json([
                            'method' => $method,
                            'body' => (string)$request->getBody(),
                        ]);
                    }

                    if ($path === '/gzip') {
                        return new Response(
                            StatusCodeInterface::STATUS_OK,
                            ['Content-Type' => 'text/plain', 'Content-Encoding' => 'gzip'],
                            (string)gzencode('compressed payload')
                        );
                    }

                    if ($method === 'GET') {
                        return match ($path) {
                            '/file/128kb' => self::streamFile(128),
                            '/file/5mb' => self::streamFile(1024 * 4),
                            '/file/10mb' => self::streamFile(10240),
                            '/file/50mb' => self::streamFile(51200),
                            '/file/sleep' => self::latency(),
                            '/500' => Response::plaintext('500 Error')->withStatus('500'),
                            default => Response::plaintext(
                                "Hello World!\n"
                            ),
                        };
                    }
                    return Response::plaintext(
                        "Hello World!\n"
                    );
                } catch (Throwable $e) {
                    exit($e->getMessage());
                }
            }
        );

        $socket = new \React\Socket\SocketServer('127.0.0.1:0');
        self::$connections = new \SplObjectStorage();
        $socket->on('connection', static function (\React\Socket\ConnectionInterface $connection) {
            self::$connections->attach($connection);
            $connection->on('close', static function () use ($connection) {
                self::$connections->detach($connection);
            });
        });
        $server->listen($socket);
        self::$server = $server;
        self::$socket = $socket;
        self::$testServerAddress = str_replace('tcp://', 'http://', $socket->getAddress());
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
        foreach (self::$connections as $connection) {
            $connection->close();
            self::$connections->detach($connection);
        }
        self::$socket?->close();
        self::$socket = null;
    }

    private static function latency() : Response {
        $stream = new ThroughStream();

        $timer = Loop::addTimer(5, function() use ($stream) {
            $stream->write('slept for a while');
            $stream->end();
        });

        // stop timer if stream is closed (such as when connection is closed)
        $stream->on('close', function () use ($timer) {
            Loop::cancelTimer($timer);
        });

        return new Response(
            StatusCodeInterface::STATUS_OK,
            array(
                'Content-Type' => 'text/plain'
            ),
            $stream
        );
    }

    static private function streamFile($sizeInKb): Response
    {
        $stream = new ThroughStream();

        // send some data every once in a while with periodic timer
        $sizeInBytes = $sizeInKb * 1024;
        $written = 0;
        $timer = Loop::addPeriodicTimer(0.001, function () use ($stream, $sizeInBytes, &$written) {
            $remaining = (int)floor(min($sizeInBytes-$written, (1024*1024*2/10)));
            $bytes = str_repeat('a', $remaining);
            $stream->write($bytes);
            //echo PHP_EOL. 'wrote' . $remaining . ':';
            $written += $remaining;
            //echo ($sizeInBytes-$written) . ' remaining' . PHP_EOL;
            if ($written >= $sizeInBytes) {
                //echo PHP_EOL . $sizeInBytes . 'finished' . PHP_EOL;
                $stream->end();
            }
        });

        // stop timer if stream is closed (such as when connection is closed)
        $stream->on('close', function () use ($timer) {
            Loop::cancelTimer($timer);
        });

        return new Response(
            StatusCodeInterface::STATUS_OK,
            array(
                'Content-Type' => 'text/plain'
            ),
            $stream
        );
    }

    public function testStreamingDownloadAppliesCurlBackpressure()
    {
        $browser = new Browser([CURLOPT_TIMEOUT => 180]);

        $received = 0;
        $receivedWhilePaused = 0;
        $paused = false;
        $done = new \React\Promise\Deferred();

        $browser->requestStreaming('GET', self::$testServerAddress . '/file/50mb')->then(
            function (ResponseInterface $response) use (&$received, &$receivedWhilePaused, &$paused, $done) {
                $body = $response->getBody();
                $body->pause();
                $paused = true;
                $body->on('data', function ($data) use (&$received, &$receivedWhilePaused, &$paused) {
                    $received += strlen($data);
                    if ($paused) {
                        $receivedWhilePaused += strlen($data);
                    }
                });
                $body->on('end', function () use ($done) {
                    $done->resolve(null);
                });
                $body->on('error', function ($e) use ($done) {
                    $done->reject($e);
                });
                Loop::addTimer(0.05, function () use (&$paused, $body) {
                    $paused = false;
                    $body->resume();
                });
            },
            function ($e) use ($done) {
                $done->reject($e);
            }
        );

        \React\Async\await($done->promise());

        $this->assertSame(50 * 1024 * 1024, $received, 'Did not receive the full response body');
        $this->assertLessThan(1024 * 1024, $receivedWhilePaused, 'cURL kept delivering while the body was paused');

        \React\Async\await(\React\Promise\Timer\sleep(0));
        $this->assertTrue($browser->isIdle());
    }

    public function testLocal()
    {
        $browser = new Browser([CURLOPT_TIMEOUT => 180]);

        /** @var ResponseInterface $response */
        $response = \React\Async\await($browser->get(self::$testServerAddress . '/helloworld'));

        $this->assertEquals("Hello World!\n", (string)$response->getBody(), "Server did not say hello to us");
        $this->assertTrue($browser->isIdle());
        $this->assertBrowserLeavesNoCycles($browser);
    }

    /**
     * Asserts the browser (and everything created while handling its requests) is released
     * without leaving reference cycles.
     *
     * `gc_collect_cycles()` also reports cyclic garbage from the in-process React test
     * server, so first verify the browser itself is freed, then drain any harness garbage
     * and re-check so it cannot mask library cycles.
     */
    private function assertBrowserLeavesNoCycles(Browser &$browser): void
    {
        $ref = \WeakReference::create($browser);
        $browser = null;

        // React\Async suspends the loop inside a curlTick frame until it is resumed
        \React\Async\await(\React\Promise\Timer\sleep(0));

        $this->assertNull($ref->get(), 'Browser is still referenced after all requests completed');
        gc_collect_cycles(); // drain test-harness (React server) garbage
        $this->assertSame(0, gc_collect_cycles(), 'Reference cycles remain after requests');
    }


    public function testFull()
    {
        $browser = new Browser([CURLOPT_TIMEOUT => 180]);

        $answer = [];
        $order = [];
        $promises = [];

        $sizes = ['10mb','sleep','5mb','128kb','128kb','128kb','128kb'];

        foreach($sizes as $size) {
            $promise = $browser->get(self::$testServerAddress . '/file/' . $size);
            $promise->then(static function ($result) use (&$answer, &$order, $size) {
                $answer[] = $result;
                $order[] = $size;
            }, 'print_r');
            $promises[] = $promise;
        }

        \React\Async\await(\React\Promise\all($promises));

        $this->assertEquals('10mb', $order[5], 'Received data out of expected order');
        $this->assertEquals('sleep', $order[6], 'Received data out of expected order');
        $this->assertCount(count($sizes), $answer);
        $this->assertTrue($browser->isIdle());
        $this->assertBrowserLeavesNoCycles($browser);
    }

    public function testTimeout()
    {
        $browser = (new Browser())->withTimeout(2);

        $promise = $browser->get(self::$testServerAddress . '/file/sleep');
        $this->setExpectedException('RuntimeException', 'Request timed out after ');

        \React\Async\await($promise);
        $this->assertTrue($browser->isIdle());
        unset($promise);
        $this->assertBrowserLeavesNoCycles($browser);
    }

       public function testBrowserIdleBeforePromiseResolve()
       {
           $browser = new Browser([CURLOPT_TIMEOUT => 180]);

           $promise = $browser->get(self::$testServerAddress . '/helloworld');
           $this->assertFalse($browser->isIdle());

           \React\Async\await($promise);

           $this->assertTrue($browser->isIdle());
           unset($promise);
           $this->assertBrowserLeavesNoCycles($browser);
       }

       public function testHandle500()
       {
           $browser = new Browser([CURLOPT_TIMEOUT => 180]);

           $promise = $browser->get(self::$testServerAddress . '/500');

           $this->setExpectedException(
               \React\Http\Message\ResponseException::class,
               'HTTP status code 500 (Internal Server Error)',
               500,
           );
           \React\Async\await($promise);

           unset($promise);
           $this->assertBrowserLeavesNoCycles($browser);
       }

    public function methodAndBodyProvider(): array
    {
        return [
            'POST' => ['POST', 'body-post'],
            'PUT' => ['PUT', 'body-put'],
            'DELETE' => ['DELETE', 'body-delete'],
            'PATCH' => ['PATCH', 'body-patch'],
            'QUERY' => ['QUERY', 'body-query'],
            'WebDAV PROPFIND' => ['PROPFIND', 'body-propfind'],
        ];
    }

    /** @dataProvider methodAndBodyProvider */
    public function testRequestSendsMethodAndBodyVerbatim(string $method, string $body): void
    {
        $response = \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->request($method, self::$testServerAddress . '/echo', [], $body));

        $this->assertSame(['method' => $method, 'body' => $body], json_decode((string)$response->getBody(), true));
    }

    public function testGetRequestWithBodySendsBody(): void
    {
        $response = \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->request('GET', self::$testServerAddress . '/echo', [], 'get-body'));

        $this->assertSame(['method' => 'GET', 'body' => 'get-body'], json_decode((string)$response->getBody(), true));
    }

    public function testOptionsRequestReturnsResponseBody(): void
    {
        $response = \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->options(self::$testServerAddress . '/echo'));

        $this->assertSame('OPTIONS', json_decode((string)$response->getBody(), true)['method']);
    }

    public function testTraceRequestIsSentWithoutBody(): void
    {
        $response = \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->request('TRACE', self::$testServerAddress . '/echo', [], 'ignored'));

        $this->assertSame(['method' => 'TRACE', 'body' => ''], json_decode((string)$response->getBody(), true));
    }

    public function testConnectMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP method CONNECT');

        \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->request('CONNECT', self::$testServerAddress . '/echo'));
    }

    public function testInvalidMethodTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method given');

        \React\Async\await((new Browser([CURLOPT_TIMEOUT => 10]))->request("GET / HTTP/1.1", self::$testServerAddress . '/echo'));
    }

    public function invalidResponseBufferSizes(): array
    {
        return [[0], [-1], [1.5], ['16']];
    }

    /** @dataProvider invalidResponseBufferSizes */
    public function testWithResponseBufferRejectsInvalidSizes(int|float|string $size): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Browser())->withResponseBuffer($size);
    }

    public function testResponseCompressionIsDecodedWhenAcceptEncodingRequested(): void
    {
        $browser = new Browser([CURLOPT_ACCEPT_ENCODING => '']);
        $response = \React\Async\await($browser->get(self::$testServerAddress . '/gzip'));

        $this->assertSame('compressed payload', (string)$response->getBody());
    }

    public function testResponseCompressionIsNotDecodedByDefault(): void
    {
        $response = \React\Async\await((new Browser())->get(self::$testServerAddress . '/gzip'));

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertNotSame('compressed payload', (string)$response->getBody());
    }

    public function testSendRequestImplementsPsr18(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::$testServerAddress . '/echo');
        $response = (new Browser())->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', json_decode((string)$response->getBody(), true)['method']);
    }

    public function testSendRequestDoesNotThrowOnErrorStatusCode(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::$testServerAddress . '/500');
        $response = (new Browser())->sendRequest($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testSendRequestThrowsNetworkExceptionOnConnectionFailure(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'http://127.0.0.1:1/');

        try {
            (new Browser([CURLOPT_CONNECTTIMEOUT => 2]))->sendRequest($request);
            $this->fail('Expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }

    public function testSendRequestThrowsRequestExceptionOnInvalidUrl(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'file:///etc/hosts');

        try {
            (new Browser())->sendRequest($request);
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }

}