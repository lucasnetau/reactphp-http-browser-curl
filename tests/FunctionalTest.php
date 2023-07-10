<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Stream\ThroughStream;
use function hrtime;
use function str_replace;

/**
 * Functional Tests
 */
class FunctionalTest extends \React\Tests\Http\TestCase
{
    private Browser $browser;

    protected HttpServer $server;

    protected ?string $testServerAddress;

    public function setUp() : void
    {
        $this->browser = new Browser();
        $this->server = new \React\Http\HttpServer(
            function (\Psr\Http\Message\ServerRequestInterface $request) {
                $path = $request->getUri()->getPath();
                $method = $request->getMethod();

                if ($method === 'GET') {
                    return match($path) {
                        '/file/128kb' => $this->streamFile(128),
                        '/file/5mb' => $this->streamFile(1024*4),
                        '/file/10mb' => $this->streamFile(10240),
                        '/file/50mb' => $this->streamFile(51200),
                        '/file/sleep' => $this->latency(),
                        default => Response::plaintext(
                        "Hello World!\n"
                        ),
                    };
                }
                error_log('say hello world');
                return Response::plaintext(
                    "Hello World!\n"
                );
            }
        );

        $socket = new \React\Socket\SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->testServerAddress = str_replace('tcp://', 'http://', $socket->getAddress());
    }

    public function latency() : Response {
        $stream = new ThroughStream();

        Loop::addTimer(5, function() use ($stream) {
            $stream->write('slept for a while');
            $stream->end();
        });

        return new Response(
            StatusCodeInterface::STATUS_OK,
            array(
                'Content-Type' => 'text/plain'
            ),
            $stream
        );
    }

    public function streamFile($sizeInKb): Response
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

   /* public function testGetGoogleHome()
    {
        $browser = new Browser();

        $promise = $browser->get('https://www.google.com/');

        $answer = null;
        $promise->then(function ($result) use (&$answer) {
            $answer = $result;
        }, 'print_r');

        Loop::run();

        $this->assertNotNull($answer);
x    }*/

    public function testLocal()
    {
        $browser = $this->browser;

        $promise = $browser->get($this->testServerAddress . '/helloworld');

        $answer = null;
        $promise->then(function ($result) use (&$answer) {
            /** @var ResponseInterface $result */
            $answer = (string)$result->getBody();
        }, 'print_r')->always(function() {
            Loop::stop();
        });

        Loop::run();

        $this->assertNotNull($answer);
        $this->assertEquals("Hello World!\n", $answer, "Server did not say hello to us");
        $this->assertTrue($browser->isIdle());
    }

    public function testFull()
    {
        $browser = new Browser([CURLOPT_TIMEOUT => 180]);

        $answer = [];
        $order = [];
        $speed = [];
        $promises = [];

        $sizes = ['10mb','sleep','5mb','128kb','128kb','128kb','128kb'];

        foreach($sizes as $size) {
            $promise = $browser->get($this->testServerAddress . '/file/' . $size);
            $promise->then(function ($result) use (&$answer, &$order, $size, &$start, &$speed) {
                $answer[] = $result;
                $order[] = $size;
                $speed[] = (hrtime(true) - $start) / 1e+6;
            }, 'print_r');
            $promises[] = $promise;
        }

        Loop::addPeriodicTimer(1, function () {
            static $last = 0;
            if ($last === 0) {
                $last = hrtime(true);
                return;
            }
            //echo 'mark ' . ((hrtime(true) - $last)/1e+9) . PHP_EOL;
            $last = hrtime(true);
        });

        \React\Promise\all($promises)->always(function () {
            Loop::stop();
        });

        $start = hrtime(true);
        Loop::run();
        $this->assertEquals('10mb', $order[5], 'Received data out of expected order');
        $this->assertEquals('sleep', $order[6], 'Received data out of expected order');
        $this->assertCount(count($sizes), $answer);
        $this->assertTrue($browser->isIdle());
    }

    public function testTimeout()
    {
        $browser = (new Browser())->withTimeout(2);

        $promise = $browser->get($this->testServerAddress . '/file/sleep');
        $this->setExpectedException('RuntimeException', 'Request timed out after 2 seconds');

        \React\Async\await($promise);
        $this->assertTrue($browser->isIdle());
    }

}
