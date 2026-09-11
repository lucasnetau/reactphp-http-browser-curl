<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use React\Async;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use RuntimeException;
use SplObjectStorage;

/**
 * A Browser is an immutable config handle over shared request state: with*() clones keep the
 * same transaction queue, a new Browser() gets its own, and letting go of a clone must not
 * cancel requests started on the others.
 */
class BrowserCloneTest extends \React\Tests\Http\TestCase
{
    private static ?HttpServer $http = null;

    private static ?SocketServer $socket = null;

    private static ?string $base = null;

    private static SplObjectStorage $connections;

    private Browser $browser;

    public static function setUpBeforeClass(): void
    {
        self::$connections = new SplObjectStorage();

        self::$http = new HttpServer(static function ($request) {
            if ($request->getUri()->getPath() === '/slow') {
                $timer = null;
                return new Promise(static function ($resolve) use (&$timer) {
                    $timer = Loop::addTimer(0.3, static function () use ($resolve) {
                        $resolve(new Response(200, [], 'slow'));
                    });
                }, static function () use (&$timer) {
                    Loop::cancelTimer($timer);
                });
            }

            return new Response(200, [], 'fast');
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

        //snapshot first: detaching while iterating SplObjectStorage skips entries
        $connections = [];
        foreach (self::$connections as $connection) {
            $connections[] = $connection;
        }
        self::$connections = new SplObjectStorage();

        foreach ($connections as $connection) {
            $connection->close();
        }

        self::$socket?->close();
        self::$socket = null;
        gc_collect_cycles();
    }

    protected function setUp(): void
    {
        $this->browser = new Browser([CURLOPT_TIMEOUT => 10]);
    }

    public function testRebindingMidFlightLetsRequestComplete(): void
    {
        $promise = $this->browser->get(self::$base . '/slow');
        $this->browser = $this->browser->withTimeout(5);

        $response = Async\await($promise);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->browser->isIdle());
    }

    public function testCloneSeesRequestsStartedOnOriginal(): void
    {
        $promise = $this->browser->get(self::$base . '/slow');
        $clone = $this->browser->withTimeout(5);

        $this->assertFalse($clone->isIdle(), 'clone must see requests started on the instance it was derived from');
        Async\await($promise);
        $this->assertTrue($clone->isIdle());
        $this->assertTrue($this->browser->isIdle());
    }

    public function testCancelAllOnCloneCancelsRequestStartedOnOriginal(): void
    {
        $promise = $this->browser->get(self::$base . '/slow');
        $clone = $this->browser->withTimeout(5);
        $clone->cancelAll();

        $this->setExpectedException(RuntimeException::class, 'Request cancelled');
        Async\await($promise);
    }

    public function testDroppingCloneDoesNotCancelRequests(): void
    {
        $promise = $this->browser->get(self::$base . '/slow');
        $clone = $this->browser->withTimeout(5);
        unset($clone);
        gc_collect_cycles();

        $response = Async\await($promise);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNewBrowserHasSeparateRequestState(): void
    {
        $other = new Browser([CURLOPT_TIMEOUT => 10]);
        $promise = $this->browser->get(self::$base . '/slow');

        $this->assertFalse($this->browser->isIdle());
        $this->assertTrue($other->isIdle(), 'a new Browser must not share transaction state');

        $other->cancelAll(); // must not touch the first browser's request

        $response = Async\await($promise);
        $this->assertSame(200, $response->getStatusCode());
    }
}
