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
use function strlen;

final class Transaction {

    const STATUS_CONNECTING = 'connecting';
    const STATUS_UPLOADING = 'uploading';

    const STATUS_DOWNLOADING = 'downloading';

    const STATUS_DONE = 'done';

    /** Largest total size of response headers (all redirect hops and 1xx responses included) */
    private const MAX_RESPONSE_HEADER_SIZE = 262144; //256 KiB

    public string $status = self::STATUS_CONNECTING;

    /** Raw response headers (all hops), bounded by MAX_RESPONSE_HEADER_SIZE */
    public string $headers = '';

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
     * @param ThroughStream|resource|null $file
     * @param BrowserState $state
     * @param bool $ownsMulti Whether $multi is private to this transaction (closed on teardown)
     */
    public function __construct(public \CurlMultiHandle $multi, public CurlHandle $curl, public Deferred $deferred, public $file, private BrowserState $state, private bool $ownsMulti = true) {
        $this->start = hrtime(true);

        //Collect raw response headers (all hops) in a bounded string: a malicious server
        //cannot exhaust memory with an endless header block. Holding the text directly
        //avoids a php://memory stream per request.
        $headerBytes = 0;
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function($curl, $data) use (&$headerBytes) {
            $length = strlen($data);
            $headerBytes += $length;

            if ($headerBytes > self::MAX_RESPONSE_HEADER_SIZE) {
                //record the failure and abort the transfer, but do not remove or close the
                //cURL handles here: this runs inside a libcurl callback (see curlTick())
                if (!$this->closed && $this->error === null) {
                    $this->error = new \OverflowException(
                        'Response headers size of ' . $headerBytes . ' bytes exceeds maximum of ' . self::MAX_RESPONSE_HEADER_SIZE . ' bytes',
                        \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
                    );
                }
                return 0;
            }

            $this->headers .= $data;
            return $length;
        });
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
        if ($this->ownsMulti) {
            curl_multi_close($this->multi);
        }
        curl_setopt($this->curl, CURLOPT_XFERINFOFUNCTION, null);
        curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, null);
        curl_setopt($this->curl, CURLOPT_READFUNCTION, null);
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, null);
        unset($this->multi);
        unset($this->curl);
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
