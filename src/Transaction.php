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

    /** Whether the cURL handles and streams have actually been released */
    private bool $tornDown = false;

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
     * @param resource|ThroughStream|null $file
     * @param resource $headers
     * @param BrowserState $state
     */
    public function __construct(public \CurlMultiHandle $multi, public CurlHandle $curl, public Deferred $deferred, public $file, public $headers, private BrowserState $state) {
        $this->start = hrtime(true);
    }

    public function close() : void {
        if ($this->closed) {
            return;
        }

        // Mark closed before touching the deferred below: cancelling it invokes the
        // canceller, which may call close() again. That nested call must be a no-op.
        $this->closed = true;

        // Detach the deferred before cancelling it so a nested close() from the canceller has
        // nothing left to do.
        $deferred = $this->deferred;
        unset($this->deferred);

        try {
            if ($this->state->curlCallbackDepth > 0) {
                // libcurl forbids removing an easy handle from a multi handle from within
                // any of its callbacks (reachable through streaming response handlers that
                // call cancelAll()). Keep the transaction alive and let the browser release
                // the cURL resources once the outermost curl_multi_exec() has returned.
                $this->state->pendingTeardown[] = $this;
            } else {
                $this->closeNow();
            }
        } finally {
            // Reject a still-pending request with "Request cancelled"
            $deferred->promise()->cancel();
        }
    }

    /**
     * Release the cURL handles and streams. Must only be called outside of any libcurl
     * callback (Browser::flushPendingTeardown() does this for deferred closes).
     */
    public function closeNow() : void {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;

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
    }

    public function isClosed() : bool {
        return $this->closed;
    }

    public function __destruct() {
        $this->close();
    }

}
