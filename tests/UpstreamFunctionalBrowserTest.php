<?php


use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use EdgeTelemetrics\React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Promise;
use React\Promise\Stream;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;

class UpstreamFunctionalBrowserTest extends \React\Tests\Http\TestCase
{
    private Browser $browser;
    private ?string $base;

    /** @var ?SocketServer */
    private SocketServer|null $socket;

    private SplObjectStorage $connectionList;

    /**
     * @before
     */
    public function setUpBrowserAndServer()
    {
        $this->browser = new Browser([CURLOPT_MAXAGE_CONN => 10, CURLOPT_VERBOSE => false]);
        $this->connectionList = new SplObjectStorage();

        $http = new HttpServer(new StreamingRequestMiddleware(), function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();

            $headers = array();
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            if ($path === '/get') {
                return new Response(
                    200,
                    array(),
                    'hello'
                );
            }

            if ($path === '/redirect-to') {
                $params = $request->getQueryParams();
                return new Response(
                    302,
                    array('Location' => $params['url'])
                );
            }

            if ($path === '/basic-auth/user/pass') {
                return new Response(
                    $request->getHeaderLine('Authorization') === 'Basic dXNlcjpwYXNz' ? 200 : 401,
                    array(),
                    ''
                );
            }

            if ($path === '/status/204') {
                return new Response(
                    204,
                    array(),
                    ''
                );
            }

            if ($path === '/status/304') {
                return new Response(
                    304,
                    array(),
                    'Not modified'
                );
            }


            if ($path === '/status/404') {
                return new Response(
                    404,
                    array(),
                    ''
                );
            }

            if ($path === '/status/404/body') {
                return new Response(
                    404,
                    array(),
                    'not found'
                );
            }

            if ($path === '/status/404/delay/10') {
                $timer = null;
                return new Promise(function ($resolve) use (&$timer) {
                    $timer = Loop::addTimer(10, function () use ($resolve) {
                        $resolve(new Response(
                            404,
                            array(),
                            ''
                        ));
                    });
                }, function () use (&$timer) {
                    Loop::cancelTimer($timer);
                });
            }

            if ($path === '/delay/10') {
                $timer = null;
                return new Promise(function ($resolve) use (&$timer) {
                    $timer = Loop::addTimer(10, function () use ($resolve) {
                        $resolve(new Response(
                            200,
                            array(),
                            'hello'
                        ));
                    });
                }, function () use (&$timer) {
                    Loop::cancelTimer($timer);
                });
            }

            if ($path === '/post') {
                return new Promise(function ($resolve) use ($request, $headers) {
                    $body = $request->getBody();
                    assert($body instanceof ReadableStreamInterface);

                    $buffer = '';
                    $body->on('data', function ($data) use (&$buffer) {
                        $buffer .= $data;
                    });

                    $body->on('close', function () use (&$buffer, $resolve, $headers) {
                        $resolve(new Response(
                            200,
                            array(),
                            json_encode(array(
                                'data' => $buffer,
                                'headers' => $headers
                            ))
                        ));
                    });
                });
            }

            if ($path === '/hash') {
                return new Promise(function ($resolve) use ($request) {
                    $body = $request->getBody();
                    assert($body instanceof ReadableStreamInterface);

                    $hash = hash_init('sha256');
                    $length = 0;
                    $resolved = false;
                    $body->on('data', function ($data) use ($hash, &$length) {
                        hash_update($hash, $data);
                        $length += strlen($data);
                    });
                    $body->on('close', function () use (&$resolved, $resolve, $hash, &$length) {
                        if ($resolved) {
                            return;
                        }
                        $resolved = true;
                        $resolve(new Response(
                            200,
                            array('Content-Type' => 'application/json'),
                            json_encode(array(
                                'bytes' => $length,
                                'sha256' => hash_final($hash)
                            ))
                        ));
                    });
                });
            }

            if ($path === '/stream/1') {
                $stream = new ThroughStream();

                Loop::futureTick(function () use ($stream, $headers) {
                    $stream->end(json_encode(array(
                        'headers' => $headers
                    )));
                });

                return new Response(
                    200,
                    array(),
                    $stream
                );
            }

            var_dump($path);
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $this->socket->on('connection', function ($conn) {
            $this->connectionList->offsetSet($conn);
            $func = function () use ($conn) {$conn->close();};
            $timer = Loop::addTimer(11, $func);
            $conn->on('data', function ($data) use (&$timer, $func) {
                Loop::cancelTimer($timer);
                $timer = Loop::addTimer(11, $func);
            });
            $conn->on('close', function() use (&$timer, $conn) {
                Loop::cancelTimer($timer);
                $this->connectionList->detach($conn);
            });
        });

        $http->listen($this->socket);

        $this->base = str_replace('tcp:', 'http:', $this->socket->getAddress()) . '/';
    }

    /**
     * @after
     */
    public function cleanUpSocketServer()
    {
        unset($this->browser);
        //error_log('gc' . gc_collect_cycles() + gc_collect_cycles());

        $this->socket->pause();
        $this->socket->close();
        foreach($this->connectionList as $conn) {
            $conn->close();
            $this->connectionList->detach($conn);
        }
        $this->socket = null;
    }


    public function testSimpleRequest()
    {
        $res = \React\Async\await($this->browser->get($this->base . 'get'));
        $this->assertEquals('hello', (string)$res->getBody());
    }

    public function testGetRequestWithRelativeAddressRejects()
    {
        $promise = $this->browser->get('delay');

        $this->setExpectedException('InvalidArgumentException', 'Invalid request URL given');
        \React\Async\await($promise);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGetRequestWithBaseAndRelativeAddressResolves()
    {
        \React\Async\await($this->browser->withBase($this->base)->get('get'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGetRequestWithBaseAndFullAddressResolves()
    {
        \React\Async\await($this->browser->withBase('http://example.com/')->get($this->base . 'get'));
    }

    public function testProtocolRelativeUrlReplacesBaseUrl()
    {
        $browser = $this->browser->withBase('http://example.com/');
        $authority = substr($this->base, strlen('http://'));

        $response = \React\Async\await($browser->get('//' . $authority . 'get'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCancelGetRequestWillRejectRequest()
    {
        $promise = $this->browser->get($this->base . 'get');
        $promise->cancel();

        $this->setExpectedException('RuntimeException');
        \React\Async\await($promise);
    }

    public function testCancelRequestWithPromiseFollowerWillRejectRequest()
    {
        $promise = $this->browser->request('GET', $this->base . 'get')->then(function () {
            var_dump('noop');
        });
        $promise->cancel();

        $this->setExpectedException('RuntimeException');
        \React\Async\await($promise);
    }

    public function testRequestWithoutAuthenticationFails()
    {
        $this->setExpectedException('RuntimeException');
        $response = \React\Async\await($this->browser->get($this->base . 'basic-auth/user/pass', ['Connection' => 'close']));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRequestWithAuthenticationSucceeds()
    {
        $browser = new Browser();
        $base = str_replace('://', '://user:pass@', $this->base);

        \React\Async\await($browser->get($base . 'basic-auth/user/pass'));
    }

    /**
     * ```bash
     * $ curl -vL "http://httpbin.org/redirect-to?url=http://user:pass@httpbin.org/basic-auth/user/pass"
     * ```
     *
     * @doesNotPerformAssertions
     */
    public function testRedirectToPageWithAuthenticationSendsAuthenticationFromLocationHeader()
    {
        $target = str_replace('://', '://user:pass@', $this->base) . 'basic-auth/user/pass';

        \React\Async\await($this->browser->get($this->base . 'redirect-to?url=' . urlencode($target)));
    }

    /**
     * ```bash
     * $ curl -vL "http://unknown:invalid@httpbin.org/redirect-to?url=http://user:pass@httpbin.org/basic-auth/user/pass"
     * ```
     *
     * @doesNotPerformAssertions
     */
    public function testRedirectFromPageWithInvalidAuthToPageWithCorrectAuthenticationSucceeds()
    {
        if (version_compare(curl_version()['version'], "8.2.0") < 0) {
            $this->markTestSkipped("cURL before version 8.2.0 does not support getting password from redirect location");
        }
        $base = str_replace('://', '://unknown:invalid@', $this->base);
        $target = str_replace('://', '://user:pass@', $this->base) . 'basic-auth/user/pass';

        \React\Async\await($this->browser->get($base . 'redirect-to?url=' . urlencode($target)));
    }

    public function testCancelRedirectedRequestShouldReject()
    {
        $promise = $this->browser->get($this->base . 'redirect-to?url=delay%2F10');

        Loop::addTimer(0.1, function () use ($promise) {
            $promise->cancel();
        });

        $this->setExpectedException('RuntimeException', 'Request cancelled');
        \React\Async\await($promise);
    }

    public function testTimeoutDelayedResponseShouldReject()
    {
        $promise = $this->browser->withTimeout(1.1)->get($this->base . 'delay/10');

        $this->setExpectedException('RuntimeException', 'Request timed out after ');
        \React\Async\await($promise);
    }

    public function testFractionalTimeoutDelayedResponseShouldReject()
    {
        $start = microtime(true);
        $promise = $this->browser->withTimeout(0.5)->get($this->base . 'delay/10');

        $this->setExpectedException('RuntimeException', 'Request timed out after ');
        try {
            \React\Async\await($promise);
        } finally {
            $this->assertLessThan(5, microtime(true) - $start);
        }
    }

    public function testTimeoutDelayedResponseAfterStreamingRequestShouldReject()
    {
        $stream = new ThroughStream();
        $promise = $this->browser->withTimeout(1.1)->post($this->base . 'delay/10', array(), $stream);
        $stream->end();

        $this->setExpectedException('RuntimeException', 'Request timed out after ');
        \React\Async\await($promise);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testTimeoutFalseShouldResolveSuccessfully()
    {
        \React\Async\await($this->browser->withTimeout(false)->get($this->base . 'get'));
    }

    public function testDefaultSocketTimeoutAppliesWhenNoTimeoutConfigured()
    {
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '1');

        try {
            $start = microtime(true);
            $promise = $this->browser->get($this->base . 'delay/10');

            $this->setExpectedException('RuntimeException', 'Request timed out after ');
            try {
                \React\Async\await($promise);
            } finally {
                $this->assertLessThan(5, microtime(true) - $start);
            }
        } finally {
            ini_set('default_socket_timeout', $previous);
        }
    }

    public function testConstructorTimeoutTakesPrecedenceOverDefaultSocketTimeout()
    {
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '1');

        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) {
            $rawRequest = '';
            $connection->on('data', function ($data) use ($connection, &$rawRequest) {
                $rawRequest .= $data;
                if (strpos($rawRequest, "\r\n\r\n") !== false) {
                    Loop::addTimer(1.5, function () use ($connection) {
                        $connection->end("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                    });
                }
            });
        });

        try {
            $response = \React\Async\await(
                (new Browser([CURLOPT_TIMEOUT => 5]))->get(str_replace('tcp:', 'http:', $socket->getAddress()) . '/')
            );
            $this->assertSame(200, $response->getStatusCode());
        } finally {
            $socket->close();
            ini_set('default_socket_timeout', $previous);
        }
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRedirectRequestRelative()
    {
        \React\Async\await($this->browser->get($this->base . 'redirect-to?url=get'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRedirectRequestAbsolute()
    {
        \React\Async\await($this->browser->get($this->base . 'redirect-to?url=' . urlencode($this->base . 'get')));
    }

    public function testNonHttpSchemesAreRejected()
    {
        foreach (['file://localhost/etc/hosts', 'gopher://127.0.0.1:1/_', 'dict://127.0.0.1:1/', 'ftp://127.0.0.1/', 'data:text/plain,hello'] as $url) {
            try {
                \React\Async\await($this->browser->get($url));
                $this->fail('Expected ' . $url . ' to be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid request URL given', $e->getMessage());
            }
        }
    }

    public function testRedirectToNonHttpSchemeCannotReadLocalFiles()
    {
        $file = tempnam(sys_get_temp_dir(), 'scheme');
        file_put_contents($file, 'SCHEME_SENTINEL');

        try {
            try {
                $response = \React\Async\await($this->browser->get(
                    $this->base . 'redirect-to?url=' . rawurlencode('file://localhost' . $file)
                ));
                $this->assertStringNotContainsString('SCHEME_SENTINEL', (string)$response->getBody());
            } catch (\Throwable $e) {
                //redirect blocked before the local file could be read
                $this->assertStringNotContainsString('SCHEME_SENTINEL', $e->getMessage());
            }
        } finally {
            unlink($file);
        }
    }

    public function testSsrfProtectionBlocksRequestToLocalAddress()
    {
        if (!\defined('CURLOPT_PREREQFUNCTION')) {
            $this->markTestSkipped('CURLOPT_PREREQFUNCTION requires PHP 8.4+');
        }

        try {
            \React\Async\await($this->browser->withSsrfProtection()->get($this->base . 'get'));
            $this->fail('Expected request to a local address to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame(CURLE_ABORTED_BY_CALLBACK, $e->getCode());
            $this->assertStringContainsString('blocked address', $e->getMessage());
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testSsrfProtectionDisabledByDefault()
    {
        $response = \React\Async\await($this->browser->get($this->base . 'get'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSsrfProtectionCannotBeEnabledWithoutPhp84Support()
    {
        if (\defined('CURLOPT_PREREQFUNCTION')) {
            $this->markTestSkipped('CURLOPT_PREREQFUNCTION is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSRF protection requires PHP 8.4+');
        $this->browser->withSsrfProtection();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testFollowingRedirectsFalseResolvesWithRedirectResult()
    {
        $browser = $this->browser->withFollowRedirects(false);

        \React\Async\await($browser->get($this->base . 'redirect-to?url=get'));
    }

    public function testFollowRedirectsZeroRejectsOnRedirect()
    {
        $browser = $this->browser->withFollowRedirects(0);

        $this->setExpectedException('RuntimeException');
        \React\Async\await($browser->get($this->base . 'redirect-to?url=get'));
    }

    public function testResponseStatus204ShouldResolveWithEmptyBody()
    {
        $response = \React\Async\await($this->browser->get($this->base . 'status/204'));
        $this->assertFalse($response->hasHeader('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    public function testResponseStatus304ShouldResolveWithEmptyBodyButContentLengthResponseHeader()
    {
        $response = \React\Async\await($this->browser->get($this->base . 'status/304'));
        $this->assertEquals('12', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    /**
     * @doesNotPerformAssertions
     **/
    public function testGetRequestWithResponseBufferMatchedExactlyResolves()
    {
        $promise = $this->browser->withResponseBuffer(5)->get($this->base . 'get');

        \React\Async\await($promise);
    }

    public function testGetRequestWithResponseBufferExceededRejects()
    {
        $promise = $this->browser->withResponseBuffer(4)->get($this->base . 'get');

        $this->setExpectedException(
            'OverflowException',
            'Response body size of 5 bytes exceeds maximum of 4 bytes',
            defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90
        );
        \React\Async\await($promise);
    }

    public function testGetRequestWithResponseBufferExceededDuringStreamingRejects()
    {
        $promise = $this->browser->withResponseBuffer(4)->get($this->base . 'stream/1');

        try {
            \React\Async\await($promise);
            $this->fail('Expected OverflowException');
        } catch (\OverflowException $e) {
            // cURL may deliver more than the limit in one chunk, so the byte count is variable
            $this->assertMatchesRegularExpression(
                '/^Response body size of \d+ bytes exceeds maximum of 4 bytes$/',
                $e->getMessage()
            );
            $this->assertSame(defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90, $e->getCode());
        }
    }

    /**
     * @group internet
     * @doesNotPerformAssertions
     */
    public function testCanAccessHttps()
    {
        \React\Async\await($this->browser->get('https://www.google.com/'));
    }

    /**
     * @group internet
     */
    public function testVerifyPeerEnabledForBadSslRejects()
    {
        $browser = new Browser([CURLOPT_SSL_VERIFYPEER => true]);

        $this->setExpectedException('RuntimeException');
        \React\Async\await($browser->get('https://self-signed.badssl.com/'));
    }

    /**
     * @group internet
     * @doesNotPerformAssertions
     */
    public function testVerifyPeerDisabledForBadSslResolves()
    {
        $browser = new Browser([CURLOPT_SSL_VERIFYPEER => false]);

        \React\Async\await($browser->get('https://self-signed.badssl.com/'));
    }

    /**
     * @group internet
     */
    public function testInvalidPort()
    {
        $this->setExpectedException('RuntimeException');
        \React\Async\await($this->browser->get('http://www.google.com:443/', ['Connection' => 'close']));
    }

    public function testErrorStatusCodeRejectsWithResponseException()
    {
        try {
            \React\Async\await($this->browser->get($this->base . 'status/404', ['Connection' => 'close']));
            $this->fail();
        } catch (ResponseException $e) {
            $this->assertEquals(404, $e->getCode());

            $this->assertInstanceOf('Psr\Http\Message\ResponseInterface', $e->getResponse());
            $this->assertEquals(404, $e->getResponse()->getStatusCode());
        }
    }

    public function testStreamingErrorStatusCodeWithBodyRejectsWithResponseException()
    {
        try {
            \React\Async\await($this->browser->requestStreaming('GET', $this->base . 'status/404/body'));
            $this->fail();
        } catch (ResponseException $e) {
            $this->assertEquals(404, $e->getCode());
            $this->assertInstanceOf('Psr\Http\Message\ResponseInterface', $e->getResponse());
            $this->assertEquals(404, $e->getResponse()->getStatusCode());
        }
    }

    public function testErrorStatusCodeDoesNotRejectWithRejectErrorResponseFalse()
    {
        $response = \React\Async\await($this->browser->withRejectErrorResponse(false)->get($this->base . 'status/404'));

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testPostString()
    {
        $response = \React\Async\await($this->browser->post($this->base . 'post', array(), 'hello world'));

        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
    }

    public function testRequestStreamReturnsResponseBodyUntilConnectionsEndsForHttp10()
    {
        $response = \React\Async\await($this->browser->withProtocolVersion('1.0')->get($this->base . 'stream/1'));

        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertFalse($response->hasHeader('Transfer-Encoding'));

        $this->assertStringStartsWith('{', (string) $response->getBody());
        $this->assertStringEndsWith('}', (string) $response->getBody());
    }

    public function testRequestStreamReturnsResponseWithTransferEncodingChunkedAndResponseBodyDecodedForHttp11()
    {
        $response = \React\Async\await($this->browser->withProtocolVersion('1.1')->get($this->base . 'stream/1'));

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));

        $this->assertStringStartsWith('{', (string) $response->getBody());
        $this->assertStringEndsWith('}', (string) $response->getBody());
    }

    public function testRequestStreamWithHeadRequestReturnsEmptyResponseBodWithTransferEncodingChunkedForHttp11()
    {
        $response = \React\Async\await($this->browser->withProtocolVersion('1.1')->head($this->base . 'stream/1'));

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));
        $this->assertEquals('', (string) $response->getBody());
    }

    public function testRequestStreamReturnsResponseWithResponseBodyUndecodedWhenResponseHasDoubleTransferEncoding()
    {
        $this->markTestSkipped('BROKEN: libcurl decodes chunked transfer itself; the unframed body fails with CURLE_RECV_ERROR (56) and a framed body is decoded, so upstream react/http pass-through semantics do not apply');
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (\React\Socket\ConnectionInterface $connection) {
            $connection->on('data', function () use ($connection) {
                $connection->end("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked, chunked\r\nConnection: close\r\n\r\nhello");
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        try {
            $response = \React\Async\await($this->browser->get($this->base . 'stream/1'));
        } finally { //ensure we cleanup
            $socket->close();
        }

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked, chunked', $response->getHeaderLine('Transfer-Encoding'));
        $this->assertEquals('hello', (string) $response->getBody());

        $socket->close();
    }

    public function testReceiveStreamAndExplicitlyCloseConnectionEvenWhenServerKeepsConnectionOpen()
    {
        $closed = new \React\Promise\Deferred();
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (\React\Socket\ConnectionInterface $connection) use ($closed) {
            $connection->on('data', function () use ($connection) {
                $connection->write("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            });
            $connection->on('close', function () use ($closed) {
                $closed->resolve(true);
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = \React\Async\await($this->browser->get($this->base . 'get', ['Connection' => 'close']));
        $this->assertEquals('hello', (string)$response->getBody());

        $ret = \React\Async\await(\React\Promise\Timer\timeout($closed->promise(), 0.1));
        $this->assertTrue($ret);

        $socket->close();
    }

    public function testRequestWillCreateNewConnectionForSecondRequestByDefaultEvenWhenServerKeepsConnectionOpen()
    {
        $twice = $this->expectCallableOnce();
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (\React\Socket\ConnectionInterface $connection) use ($socket, $twice) {
            $connection->on('data', function () use ($connection) {
                $connection->write("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            });

            $socket->on('connection', $twice);
            $socket->on('connection', function () use ($socket) {
                $socket->close();
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = \React\Async\await($this->browser->get($this->base . 'get', ['Connection' => 'close']));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = \React\Async\await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $socket->close();
    }


    public function testRequestWithoutConnectionHeaderWillReuseExistingConnectionForSecondRequest()
    {
        $this->socket->on('connection', $this->expectCallableOnce());

        $response = \React\Async\await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = \React\Async\await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testRequestWithoutConnectionHeaderWillReuseExistingConnectionForRedirectedRequest()
    {
        $this->socket->on('connection', $this->expectCallableOnce());

        $response = \React\Async\await($this->browser->get($this->base . 'redirect-to?url=get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testPostStreamChunked()
    {
        $stream = new ThroughStream();

        Loop::addTimer(0.001, function () use ($stream) {
            $stream->end('hello world');
        });

        $response = \React\Async\await($this->browser->post($this->base . 'post', array(), $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
        $this->assertFalse(isset($data['headers']['Content-Length']));
        $this->assertEquals('chunked', $data['headers']['Transfer-Encoding']);
    }

    public function testPostStreamKnownLength()
    {
        $stream = new ThroughStream();

        Loop::addTimer(0.001, function () use ($stream) {
            $stream->end('hello world');
        });

        $response = \React\Async\await($this->browser->post($this->base . 'post', array('Content-Length' => 11), $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPostStreamWillStartSendingRequestEvenWhenBodyDoesNotEmitData()
    {
        $http = new HttpServer(new StreamingRequestMiddleware(), function (ServerRequestInterface $request) {
            return new Response(200);
        });

        $socket = new SocketServer('127.0.0.1:0');
        try {
            $http->listen($socket);

            $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

            $stream = new ThroughStream();
            \React\Async\await($this->browser->post($this->base . 'post', ['Connection' => 'close'], $stream));

        } finally {
            $socket->close();
        }
    }

    public function testPostStreamClosed()
    {
        $stream = new ThroughStream();
        $stream->close();

        $response = \React\Async\await($this->browser->post($this->base . 'post', ['Connection' => 'close'], $stream));

        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('', $data['data']);
    }

    public function testPostStreamErrorRejectsRequestWithSourceError()
    {
        $stream = new ThroughStream();
        $stream->write('partial body');

        Loop::addTimer(0.001, static function () use ($stream) {
            $stream->emit('error', [new \RuntimeException('upload source failed')]);
        });

        $this->setExpectedException('RuntimeException', 'upload source failed');
        \React\Async\await($this->browser->post($this->base . 'post', ['Content-Length' => 100], $stream));
    }

    public function testChunkedPostStreamErrorRejectsRequestWithSourceError()
    {
        $stream = new ThroughStream();

        Loop::addTimer(0.001, static function () use ($stream) {
            $stream->emit('error', [new \RuntimeException('upload source failed')]);
        });

        $this->setExpectedException('RuntimeException', 'upload source failed');
        \React\Async\await($this->browser->post($this->base . 'post', ['Connection' => 'close'], $stream));
    }

    /**
     * Feeds a ThroughStream in chunks, respecting the pause/resume backpressure signalled
     * by UploadBodyStream when its buffer is full.
     */
    private function pumpBody(ThroughStream $stream, string $body, int $chunkSize, float $interval = 0.001): void
    {
        $offset = 0;
        $blocked = false;
        $length = strlen($body);

        // ThroughStream::write() returns false while paused and emits 'drain' on resume
        $stream->on('drain', static function () use (&$blocked) {
            $blocked = false;
        });

        $timer = Loop::addPeriodicTimer($interval, static function () use ($stream, $body, $length, $chunkSize, &$offset, &$blocked, &$timer) {
            if ($blocked) {
                return;
            }
            if ($offset >= $length) {
                Loop::cancelTimer($timer);
                $stream->end();
                return;
            }
            // write() delivers the chunk even when it returns false, so advance before blocking
            $chunk = substr($body, $offset, $chunkSize);
            $offset += $chunkSize;
            if ($stream->write($chunk) === false) {
                $blocked = true;
            }
        });
    }

    /** @return array{bytes: int, sha256: string} */
    private function assertHashedUpload(Browser $browser, string $body, array $headers = [], int $chunkSize = 256 * 1024): array
    {
        $stream = new ThroughStream();
        $this->pumpBody($stream, $body, $chunkSize);

        $response = \React\Async\await($browser->post($this->base . 'hash', $headers, $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertSame(strlen($body), $data['bytes'], 'uploaded byte count mismatch');
        $this->assertSame(hash('sha256', $body), $data['sha256'], 'uploaded body hash mismatch');

        return $data;
    }

    public function testPostStreamLargerThanCurlUploadBufferUploadsIntact()
    {
        //exceeds cURL's ~20 MiB internal upload buffer, so cURL has to pause and wait for the producer
        $total = 32 * 1024 * 1024;
        $body = str_repeat('abcdefgh', intdiv($total, 8));

        $this->assertHashedUpload(new Browser([CURLOPT_TIMEOUT => 60]), $body, ['Content-Length' => $total]);
    }

    public function testPostStreamWithSmallCurlUploadBufferUploadsIntact()
    {
        //tiny cURL upload buffer makes cURL read the body in small pieces while it is still being produced
        $total = 4 * 1024 * 1024;
        $body = str_repeat('abcdefgh', intdiv($total, 8));

        $this->assertHashedUpload(
            new Browser([CURLOPT_TIMEOUT => 30, CURLOPT_UPLOAD_BUFFERSIZE => 131072]),
            $body,
            ['Content-Length' => $total]
        );
    }

    public function testChunkedPostStreamLargerThanCurlUploadBufferUploadsIntact()
    {
        $total = 32 * 1024 * 1024;
        $body = str_repeat('0123456789', intdiv($total, 10));

        $this->assertHashedUpload(new Browser([CURLOPT_TIMEOUT => 60]), $body);
    }

    public function testSlowChunkedPostStreamDripsThroughUploadBufferUploadsIntact()
    {
        $total = 256 * 1024;
        $body = str_repeat('slow-drip-', intdiv($total, 10));

        $this->assertHashedUpload($this->browser, $body, [], 1024);
    }

    public function testSendsHttp11ByDefault()
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return new Response(
                200,
                array(),
                $request->getProtocolVersion()
            );
        });
        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = \React\Async\await($this->browser->withProtocolVersion('1.1')->get($this->base));
        $this->assertEquals('1.1', (string)$response->getBody());

        $socket->close();
    }

    public function testSendsExplicitHttp10Request()
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return new Response(
                200,
                array(),
                $request->getProtocolVersion()
            );
        });
        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = \React\Async\await($this->browser->withProtocolVersion('1.0')->get($this->base));

        $this->assertEquals('1.0', (string)$response->getBody());

        $socket->close();
    }

    public function testHeadRequestReceivesResponseWithEmptyBodyButWithContentLengthResponseHeader()
    {
        $response = \React\Async\await($this->browser->head($this->base . 'get'));
        $this->assertEquals('5', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    public function testRequestStreamingGetReceivesResponseWithStreamingBodyAndKnownSize()
    {
        $response = \React\Async\await($this->browser->requestStreaming('GET', $this->base . 'get'));
        $this->assertEquals('5', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(5, $body->getSize());
        $this->assertEquals('', (string) $body);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $body);
    }

    public function testRequestStreamingGetReceivesResponseWithStreamingBodyAndUnknownSizeFromStreamingEndpoint()
    {
        $response = \React\Async\await($this->browser->requestStreaming('GET', $this->base . 'stream/1'));
        $this->assertFalse($response->hasHeader('Content-Length'));

        $body = $response->getBody();
        $this->assertNull($body->getSize());
        $this->assertEquals('', (string) $body);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $body);
    }

    public function testRequestStreamingGetReceivesStreamingResponseBody()
    {
        $buffer = \React\Async\await(
            $this->browser->requestStreaming('GET', $this->base . 'get')->then(function (ResponseInterface $response) {
                return Stream\buffer($response->getBody());
            })
        );

        $this->assertEquals('hello', $buffer);
    }

    public function testRequestStreamingGetReceivesStreamingResponseBodyEvenWhenResponseBufferExceeded()
    {
        $buffer = \React\Async\await(
            $this->browser->withResponseBuffer(4)->requestStreaming('GET', $this->base . 'get')->then(function (ResponseInterface $response) {
                return Stream\buffer($response->getBody());
            })
        );

        $this->assertEquals('hello', $buffer);
    }

    public function testRequestStreamingGetWithLowercaseContentLengthHeaderReportsBodySize()
    {
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (\React\Socket\ConnectionInterface $connection) {
            $connection->on('data', function () use ($connection) {
                $connection->end("HTTP/1.1 200 OK\r\ncontent-length: 5\r\nConnection: close\r\n\r\nhello");
            });
        });
        $base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        try {
            $response = \React\Async\await($this->browser->requestStreaming('GET', $base));
        } finally {
            $socket->close();
        }

        $this->assertEquals('5', $response->getHeaderLine('content-length'));
        $this->assertEquals(5, $response->getBody()->getSize());
    }

    public function testRequestWithListFormatHeadersSendsTrimmedHeaders()
    {
        $rawRequest = '';
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) use (&$rawRequest) {
            $connection->on('data', function ($data) use ($connection, &$rawRequest) {
                $rawRequest .= $data;
                if (strpos($rawRequest, "\r\n\r\n") !== false) {
                    $connection->end("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                }
            });
        });
        $base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        try {
            $response = \React\Async\await($this->browser->get($base, ['Content-Type: application/json', 'Connection: close']));
        } finally {
            $socket->close();
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString("\r\ncontent-type: application/json\r\n", strtolower($rawRequest));
        $this->assertStringContainsString("\r\nconnection: close\r\n", strtolower($rawRequest));
    }

    public function testCrlfInjectionInHeadersIsRejected()
    {
        $cases = [
            ['X-Test' => "hello\r\nX-Injected: yes"],
            ['X-Test' => "hello\nX-Injected: yes"],
            ['X-Test' => "hello\0X-Injected: yes"],
            ["X-Test\r\nX-Injected: yes" => 'value'],
            ["X-Test: value\r\nX-Injected: yes"],
        ];

        foreach ($cases as $headers) {
            try {
                \React\Async\await($this->browser->get($this->base . 'get', $headers));
                $this->fail('Expected header injection to be rejected for ' . json_encode($headers));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('Invalid request header', $e->getMessage());
            }
        }

        $browser = $this->browser->withHeader('X-Default', "value\r\nX-Injected: yes");
        try {
            \React\Async\await($browser->get($this->base . 'get'));
            $this->fail('Expected header injection via withHeader() to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Invalid request header', $e->getMessage());
        }

        $this->assertTrue($this->browser->isIdle());
    }
}
