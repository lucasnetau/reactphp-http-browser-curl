<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use Psr\Http\Message\ServerRequestInterface;
use React\Async;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use SplObjectStorage;
use Throwable;
use function count;
use function explode;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function gc_collect_cycles;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function mt_rand;
use function mt_srand;
use function random_bytes;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Seeded fuzzing / invariant tests.
 *
 * Tests named `testFuzz...Cannot...` / `...Never...` / `...LeaveNo...` encode the intended
 * guarantees and currently fail where a finding is still open (S1, S2, B10, B11, B12).
 */
class FuzzTest extends \React\Tests\Http\TestCase
{
    private const SEED = 20260910;

    private static ?HttpServer $http = null;

    private static ?SocketServer $socket = null;

    private static ?string $base = null;

    private static ?Browser $browser = null;

    private static SplObjectStorage $connections;

    public static function setUpBeforeClass(): void
    {
        mt_srand(self::SEED);
        self::$connections = new SplObjectStorage();

        self::$http = new HttpServer(static function (ServerRequestInterface $request) {
            if ($request->getUri()->getPath() === '/headers') {
                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($request->getHeaders()));
            }

            return new Response(200, [], 'hello');
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
        self::$browser = new Browser([CURLOPT_TIMEOUT => 10]);
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
        self::$browser = null;
        gc_collect_cycles();
    }

    /**
     * @return array{0: SocketServer, 1: string}
     */
    private static function rawServer(callable $onRequest): array
    {
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', static function (ConnectionInterface $connection) use ($onRequest) {
            $connection->on('data', static function (string $data) use ($connection, $onRequest) {
                $onRequest($connection, $data);
            });
        });

        return [$socket, str_replace('tcp://', 'http://', $socket->getAddress())];
    }

    private function fetchHeaders(array $headers): ?array
    {
        try {
            $response = Async\await(self::$browser->post(self::$base . '/headers', $headers, 'body'));

            return json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function testFuzzRequestHeaderRoundTrip(): void
    {
        $cases = [
            ['X-Fuzz-Plain' => 'plain value'],
            ['X-Fuzz-Unicode' => 'üñïçødé'],
            ['X-Fuzz-Long' => str_repeat('a', 1000)],
            ['X-Fuzz-Int' => 123],
            ['X-Fuzz-List' => ['a', 'b']],
            ['X-Fuzz-ListFormat: plain'],
        ];
        for ($i = 0; $i < 15; $i++) {
            $cases[] = ['X-Fuzz-Random-' . mt_rand(0, 9999) => str_repeat(chr(65 + mt_rand(0, 25)), mt_rand(1, 100))];
        }

        foreach ($cases as $headers) {
            $received = $this->fetchHeaders($headers);
            $this->assertNotNull($received, 'Request failed for ' . json_encode($headers));

            foreach ($headers as $key => $value) {
                if (is_int($key)) {
                    [$key, $value] = explode(':', $value, 2);
                    $value = trim($value);
                }
                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                $name = strtolower($key);
                $this->assertArrayHasKey($name, $received, 'Missing header ' . $name . ' for ' . json_encode($headers));
                $this->assertContains((string) $value, $received[$name], 'Wrong value for ' . $name);
            }
        }
    }

    public function testFuzzRequestHeadersCannotInjectAdditionalHeaders(): void
    {
        $cases = [
            ['X-Fuzz' => "value\r\nX-Injected: yes"],
            ['X-Fuzz' => "value\nX-Injected: yes"],
            ["X-Fuzz: value\r\nX-Injected: yes"],
        ];

        $rejected = 0;
        foreach ($cases as $headers) {
            $received = $this->fetchHeaders($headers);
            if ($received === null) {
                $rejected++;
                continue; // rejected outright (S1 fix): injection cannot happen
            }
            $this->assertStringNotContainsString(
                'x-injected',
                strtolower((string) json_encode($received)),
                'CRLF header injection succeeded for ' . json_encode($headers)
            );
        }

        $this->assertSame(count($cases), $rejected, 'CRLF header injection attempts must be rejected');
    }

    public function testFuzzRequestUrlsAlwaysSettleWithoutPhpErrors(): void
    {
        $urls = [
            self::$base,
            str_replace('http://', 'HTTP://', self::$base),
            str_replace('http://', 'http://user:pass@', self::$base),
            self::$base . '/?q=1#frag',
            self::$base . '/a%20b',
            self::$base . '/a?x=%zz',
            'http://127.0.0.1:1/',
            'http://[::1]:1/',
            'http://',
            'http://[bad',
            'http://exa mple.com/',
            '//127.0.0.1:1/',
        ];

        foreach ($urls as $url) {
            $promise = null;
            try {
                $promise = self::$browser->get($url);
            } catch (\InvalidArgumentException $e) {
                continue; // synchronous rejection of malformed URLs is fine
            }

            try {
                Async\await($promise);
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(\Error::class, $e, 'PHP Error for ' . $url . ': ' . $e->getMessage());
            }
        }

        $this->assertTrue(self::$browser->isIdle());
    }

    public function testFuzzNonHttpSchemesCannotReadLocalFiles(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fuzz');
        file_put_contents($file, 'FUZZ_SENTINEL_' . self::SEED);

        try {
            $browser = self::$browser->withRejectErrorResponse(false);

            $body = '';
            try {
                $response = Async\await($browser->get('file://localhost' . $file));
                $body = (string) $response->getBody();
            } catch (Throwable $e) {
                $body = $e->getMessage();
            }

            $this->assertStringNotContainsString('FUZZ_SENTINEL', $body, 'Local file content was returned');
        } finally {
            unlink($file);
        }
    }

    public function testFuzzRawHttpResponsesSettleExactlyOnceAndLeaveBrowserIdle(): void
    {
        $cases = [
            'lowercase content-length' => ["HTTP/1.1 200 OK\r\ncontent-length: 5\r\nConnection: close\r\n\r\nhello", 'hello'],
            'duplicate content-length' => ["HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello", null],
            'conflicting content-length' => ["HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 6\r\nConnection: close\r\n\r\nhello", null],
            'chunked with extension' => ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5;ext=1\r\nhello\r\n0\r\n\r\n", 'hello'],
            'truncated body' => ["HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\nhello", null],
            'no content' => ["HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n", ''],
            'malformed header line' => ["HTTP/1.1 200 OK\r\nNotAHeader\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello", 'hello'],
            'obs-fold' => ["HTTP/1.1 200 OK\r\nX-Fold: a\r\n b\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok", null],
        ];

        foreach ($cases as $name => [$bytes, $expected]) {
            [$socket, $base] = self::rawServer(static function (ConnectionInterface $connection) use ($bytes) {
                $connection->write($bytes);
                $connection->end();
            });

            try {
                $response = Async\await(self::$browser->get($base, ['Connection' => 'close']));
                if ($expected !== null) {
                    $this->assertSame($expected, (string) $response->getBody(), $name);
                }
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(\Error::class, $e, $name . ': ' . $e->getMessage());
            } finally {
                $socket->close();
            }

            $this->assertTrue(self::$browser->isIdle(), $name . ' leaked a transaction');
        }
    }

    public function testFuzzMutatedHttpResponsesAlwaysSettleWithoutPhpErrors(): void
    {
        $template = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";

        for ($i = 0; $i < 25; $i++) {
            $bytes = $template;
            $pos = mt_rand(0, strlen($bytes) - 1);

            switch (mt_rand(0, 4)) {
                case 0: // delete a few bytes
                    $bytes = substr($bytes, 0, $pos) . substr($bytes, $pos + mt_rand(1, 5));
                    break;
                case 1: // duplicate a chunk
                    $bytes = substr($bytes, 0, $pos) . substr($bytes, mt_rand(0, $pos)) . substr($bytes, $pos);
                    break;
                case 2: // insert a random byte
                    $bytes = substr($bytes, 0, $pos) . random_bytes(1) . substr($bytes, $pos);
                    break;
                case 3: // LF-only line endings
                    $bytes = str_replace("\r\n", "\n", $bytes);
                    break;
                case 4: // truncate anywhere
                    $bytes = substr($bytes, 0, mt_rand(0, strlen($bytes)));
                    break;
            }

            [$socket, $base] = self::rawServer(static function (ConnectionInterface $connection) use ($bytes) {
                $connection->write($bytes);
                $connection->end();
            });

            try {
                try {
                    Async\await(self::$browser->get($base, ['Connection' => 'close']));
                } catch (Throwable $e) {
                    $this->assertNotInstanceOf(\Error::class, $e, 'mutation ' . $i . ': ' . $e->getMessage());
                }
            } finally {
                $socket->close();
            }

            $this->assertTrue(self::$browser->isIdle(), 'mutation ' . $i . ' leaked a transaction');
        }
    }

    public function testFuzzConcurrentRequestsWithCancellationRemainIdleAndBounded(): void
    {
        $baseline = null;
        for ($round = 0; $round < 3; $round++) {
            $promises = [];
            for ($i = 0; $i < 8; $i++) {
                $promises[] = self::$browser->get(self::$base . '/?round=' . $round . '&i=' . $i);
            }

            foreach ($promises as $promise) {
                if (mt_rand(0, 1) === 1) {
                    Loop::addTimer(mt_rand(1, 20) / 1000, static function () use ($promise) {
                        $promise->cancel();
                    });
                }
            }

            $settled = array_map(
                static fn ($promise) => $promise->then(static fn () => null, static fn () => null),
                $promises
            );
            Async\await(Promise\all($settled));

            $this->assertTrue(self::$browser->isIdle(), 'round ' . $round . ' is not idle');

            unset($promises, $settled);
            gc_collect_cycles();

            if ($round === 0) {
                $baseline = memory_get_usage();
            }
        }

        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory grew across rounds');
    }

    public function testFuzzConcurrentRequestsLeaveNoReferenceCycles(): void
    {
        $browser = new Browser([CURLOPT_TIMEOUT => 10]);
        $ref = \WeakReference::create($browser);

        $promises = [];
        for ($i = 0; $i < 7; $i++) {
            $promises[] = $browser->get(self::$base . '/?cycle=' . $i);
        }

        $settled = array_map(
            static fn ($promise) => $promise->then(static fn () => null, static fn () => null),
            $promises
        );
        Async\await(Promise\all($settled));
        unset($promises, $settled);

        $browser = null;
        // React\Async suspends the loop inside a curlTick frame until it is resumed
        Async\await(\React\Promise\Timer\sleep(0));

        $this->assertNull($ref->get(), 'Browser is still referenced after requests completed (B10)');
        gc_collect_cycles(); // drain test-harness (React server) garbage
        $this->assertSame(0, gc_collect_cycles(), 'Reference cycles remain after 7 concurrent requests (B10)');
    }

    public function testFuzzInvalidCurlOptionKeysAreRejected(): void
    {
        foreach ([[999999 => true], ['foo' => 'bar']] as $options) {
            $browser = new Browser($options);
            try {
                $browser->get(self::$base);
                $this->fail('Expected RuntimeException for ' . json_encode($options));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Invalid cURL options', $e->getMessage());
            }
        }

        $this->assertSame(200, Async\await((new Browser())->get(self::$base))->getStatusCode());
    }

    public function testFuzzConstructorOptionsNeverEscapePhpErrors(): void
    {
        mt_srand(self::SEED + 1);
        $optionKeys = [
            CURLOPT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT,
            CURLOPT_MAXREDIRS,
            CURLOPT_USERAGENT,
            CURLOPT_PROXY,
            CURLOPT_INTERFACE,
            CURLOPT_DNS_SERVERS,
            CURLOPT_RESOLVE,
            CURLOPT_HTTPHEADER,
            CURLOPT_CAINFO,
        ];
        $values = [null, true, false, 0, -1, 3.14, 'abc', [], ['a'], str_repeat('x', 100)];

        $cases = [
            'curated resolve string' => [CURLOPT_RESOLVE => 'x'],
            'curated httpheader string' => [CURLOPT_HTTPHEADER => 'x'],
        ];
        for ($i = 0; $i < 20; $i++) {
            $cases['random ' . $i] = [$optionKeys[mt_rand(0, count($optionKeys) - 1)] => $values[mt_rand(0, count($values) - 1)]];
        }

        $errors = [];
        foreach ($cases as $name => $options) {
            $browser = new Browser($options);

            try {
                $promise = $browser->get(self::$base);
                Async\await($promise);
            } catch (\Error $e) {
                $errors[] = $name . ' ' . json_encode($options) . ' escaped ' . get_class($e) . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                // controlled rejection while applying/using an option is fine
            }
        }

        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertSame(200, Async\await((new Browser())->get(self::$base))->getStatusCode());
    }
}
