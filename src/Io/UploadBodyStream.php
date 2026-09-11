<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Io;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use function strlen;
use function substr;

/**
 * Buffers the request body and feeds it to cURL through CURLOPT_READFUNCTION.
 *
 * cURL cannot be fed a non-blocking PHP stream through CURLOPT_INFILE: that path casts
 * the stream to stdio and a 0-byte read means EOF, so a body that is not buffered yet
 * either truncates the upload or fails with CURLE_READ_ERROR once Content-Length is set.
 * A read callback can instead return CURL_READFUNC_PAUSE to say "no data yet", which is
 * the mechanism non-blocking uploads need.
 */
class UploadBodyStream extends EventEmitter {

    /**
     * Pause the source once this much body data is buffered; resume again below half.
     * cURL stops invoking the read callback once CURLOPT_UPLOAD_BUFFERSIZE is queued,
     * so this only bounds sources that emit faster than the request is sent.
     */
    private const MAX_BUFFERED_BYTES = 1048576; //1 MiB

    private ReadableStreamInterface $input;

    private string $buffer = '';

    private bool $ended = false;

    private bool $paused = false;

    public function __construct(ReadableStreamInterface $input) {
        $this->input = $input;

        $input->on('data', function ($data) {
            $this->buffer .= $data;

            if (!$this->paused && strlen($this->buffer) >= self::MAX_BUFFERED_BYTES) {
                $this->paused = true;
                $this->input->pause();
            }

            //cURL may be paused waiting for body data
            $this->emit('continue');
        });

        $input->on('close', function () {
            $this->ended = true;
            //let a paused cURL read the remaining buffer and the EOF
            $this->emit('continue');
        });

        //a stream that is already closed will not emit 'close' again
        if (!$input->isReadable()) {
            $this->ended = true;
        }
    }

    /**
     * CURLOPT_READFUNCTION callback body.
     *
     * Returns up to $length bytes of buffered body, an empty string at EOF, or
     * CURL_READFUNC_PAUSE while no data is buffered yet and the source is still open.
     */
    public function read(int $length): string|int
    {
        if ($this->buffer === '') {
            if ($this->ended) {
                return ''; //EOF
            }

            return CURL_READFUNC_PAUSE;
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($data));

        if ($this->paused && strlen($this->buffer) < self::MAX_BUFFERED_BYTES / 2) {
            $this->paused = false;
            $this->input->resume();
        }

        return $data;
    }
}
