<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use React\Promise\Deferred;
use React\Stream\ThroughStream;
use function curl_multi_close;
use function curl_multi_remove_handle;
use function curl_pause;
use function curl_setopt;
use function fclose;
use function is_resource;

final class Transaction {

    const STATUS_CONNECTING = 'connecting';
    const STATUS_UPLOADING = 'uploading';

    const STATUS_DOWNLOADING = 'downloading';

    const STATUS_DONE = 'done';

    public string $status = self::STATUS_CONNECTING;

    private bool $closed = false;

    public readonly float $start;

    /**
     * Failure recorded by a cURL callback that cannot be reported from inside the callback
     * (libcurl must not have its handles removed while curl_multi_exec() is running).
     * curlTick() rejects the promise with this exception once the transfer has completed.
     */
    public ?\Throwable $error = null;

    /**
     * @param \CurlMultiHandle $multi
     * @param CurlHandle $curl
     * @param Deferred $deferred
     * @param resource|ThroughStream $file
     * @param resource $headers
     */
    public function __construct(public \CurlMultiHandle $multi, public CurlHandle $curl, public Deferred $deferred, public $file, public $headers) {
        $this->start = hrtime(true);
    }

    public function close() : void {
        if ($this->closed) {
            return;
        }

        // Mark closed before touching the cURL handles: cancelling the deferred below invokes
        // its canceller, which may call close() again. That nested call must be a no-op.
        $this->closed = true;

        // Detach the deferred before cancelling it so a nested close() from the canceller has
        // nothing left to do.
        $deferred = $this->deferred;
        unset($this->deferred);

        try {
            curl_pause($this->curl, CURLPAUSE_CONT);
            curl_multi_remove_handle($this->multi, $this->curl);
            curl_multi_close($this->multi);
            curl_setopt($this->curl, CURLOPT_XFERINFOFUNCTION, null);
            curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, null);
            curl_setopt($this->curl, CURLOPT_READFUNCTION, null);
            unset($this->multi);
            unset($this->curl);
            fclose($this->headers);
            if (is_resource($this->file)) {
                fclose($this->file);
            }
        } finally {
            // Reject a still-pending request with "Request cancelled"
            $deferred->promise()->cancel();
        }
    }

    public function isClosed() : bool {
        return $this->closed;
    }

    public function __destruct() {
        $this->close();
    }

}