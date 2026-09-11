<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
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

    }