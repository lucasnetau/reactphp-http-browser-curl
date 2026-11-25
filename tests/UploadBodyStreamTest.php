<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Io\UploadBodyStream;
use Evenement\EventEmitter;
use PHPUnit\Framework\TestCase;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * Minimal readable stream double that records pause/resume calls and lets tests push
 * data, close and error events (ThroughStream is final and buffers backpressure itself).
 */
class RecordingReadableStream extends EventEmitter implements ReadableStreamInterface
{
    /** @var list<string> */
    public array $calls = [];

    private bool $readable = true;

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function pause(): void
    {
        $this->calls[] = 'pause';
    }

    public function resume(): void
    {
        $this->calls[] = 'resume';
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function close(): void
    {
        $this->readable = false;
        $this->emit('close');
    }

    public function writeData(string $data): void
    {
        $this->emit('data', [$data]);
    }

    public function emitError(\Throwable $error): void
    {
        $this->emit('error', [$error]);
    }
}

class UploadBodyStreamTest extends TestCase
{
    public function testReadPreservesBufferedBytesAcrossPartialReads(): void
    {
        $input = new RecordingReadableStream();
        $upload = new UploadBodyStream($input);
        $input->writeData('abcdef');

        $this->assertSame('ab', $upload->read(2));
        $this->assertSame('cdef', $upload->read(10));
        $this->assertSame(CURL_READFUNC_PAUSE, $upload->read(1));

        $input->close();
        $this->assertSame('', $upload->read(1));
    }

    public function testReadReturnsAbortAfterSourceError(): void
    {
        $input = new RecordingReadableStream();
        $upload = new UploadBodyStream($input);

        $input->emitError(new \RuntimeException('upload failed'));

        $this->assertSame(0x10000000, $upload->read(1024));
    }

    public function testReadPausesSourceAtHighWatermarkAndResumesBelowHalf(): void
    {
        $input = new RecordingReadableStream();
        $upload = new UploadBodyStream($input);

        // 1 MiB + 64 KiB crosses the 1 MiB high watermark once
        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 17; $i++) {
            $input->writeData($chunk);
        }
        $this->assertSame(['pause'], $input->calls);

        // Drain 640 KiB: pending drops to 448 KiB, below half the watermark
        $received = '';
        for ($i = 0; $i < 10; $i++) {
            $received .= $upload->read(65536);
        }

        $this->assertSame(str_repeat('x', 10 * 65536), $received);
        $this->assertSame(['pause', 'resume'], $input->calls);
    }

    public function testReadAcrossManySmallReadsPreservesAllBytes(): void
    {
        $input = new RecordingReadableStream();
        $upload = new UploadBodyStream($input);

        $body = str_repeat('0123456789abcdef', 65536); // exactly 1 MiB
        for ($offset = 0; $offset < strlen($body); $offset += 65536) {
            $input->writeData(substr($body, $offset, 65536));
        }
        $input->close();

        // 7919 does not divide the chunk size or the buffer size, so reads repeatedly
        // straddle compaction boundaries
        $received = '';
        while (true) {
            $chunk = $upload->read(7919);
            if ($chunk === '') {
                break;
            }
            $this->assertIsString($chunk);
            $received .= $chunk;
        }

        $this->assertSame($body, $received);
    }
}
