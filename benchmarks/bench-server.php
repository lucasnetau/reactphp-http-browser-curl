<?php declare(strict_types=1);
/**
 * Local benchmark server for benchmarks/shared-multi.php.
 *
 * Endpoints:
 *   /fast?bytes=N          N bytes as fast as possible (64 KiB chunks, batched per tick)
 *   /stream?bytes=N&ms=M   N bytes spread over M milliseconds
 *   /delay?ms=M            response headers delayed by M milliseconds
 *
 * Prints "PORT=<port>" on stdout once listening.
 */
require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED);

function streamAsFastAsPossible(int $bytes): ThroughStream
{
    $stream = new ThroughStream();
    $chunkSize = 8192;
    $sent = 0;

    $pump = null;
    $pump = static function () use (&$pump, $stream, $bytes, $chunkSize, &$sent): void {
        $batch = 0;
        while ($sent < $bytes && $batch < 32) {
            $length = min($chunkSize, $bytes - $sent);
            $stream->write(str_repeat('a', $length));
            $sent += $length;
            $batch++;
        }
        if ($sent >= $bytes) {
            $stream->end();
            return;
        }
        Loop::futureTick($pump);
    };
    Loop::futureTick($pump);

    return $stream;
}

function streamOverTime(int $bytes, int $durationMs): ThroughStream
{
    $stream = new ThroughStream();
    $chunkSize = 8192;
    $chunks = max(1, (int)ceil($bytes / $chunkSize));
    $interval = max(($durationMs / 1000) / $chunks, 0.0001);
    $sent = 0;

    $timer = Loop::addPeriodicTimer($interval, static function () use ($stream, $bytes, $chunkSize, &$sent, &$timer): void {
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

    return $stream;
}

$socket = new SocketServer('127.0.0.1:0');
$server = new HttpServer(static function (ServerRequestInterface $request): Response|Promise {
    $path = $request->getUri()->getPath();
    $query = $request->getQueryParams();

    switch ($path) {
        case '/fast':
            return new Response(200, ['Content-Type' => 'application/octet-stream'], streamAsFastAsPossible((int)($query['bytes'] ?? 65536)));

        case '/stream':
            return new Response(200, ['Content-Type' => 'application/octet-stream'], streamOverTime((int)($query['bytes'] ?? 8192), (int)($query['ms'] ?? 100)));

        case '/delay':
            $ms = (int)($query['ms'] ?? 300);
            return new Promise(static function ($resolve) use ($ms): void {
                Loop::addTimer($ms / 1000, static fn () => $resolve(new Response(200, [], 'ok')));
            });
    }

    return new Response(404, [], 'not found');
});

$server->listen($socket);

fwrite(STDOUT, 'PORT=' . parse_url(str_replace('tcp://', 'http://', $socket->getAddress()), PHP_URL_PORT) . "\n");
fflush(STDOUT);

Loop::run();
