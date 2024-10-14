<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use React\Promise\Deferred;
use function curl_close;
use function curl_multi_close;
use function curl_multi_remove_handle;
use function curl_pause;
use function curl_setopt;
use function fclose;
use function is_resource;

class Transaction {

    const STATUS_CONNECTING = 'connecting';
    const STATUS_UPLOADING = 'uploading';

    const STATUS_DOWNLOADING = 'downloading';

    const STATUS_DONE = 'done';

    public string $status = self::STATUS_CONNECTING;

    protected bool $closed = false;

    public function __construct(public \CurlMultiHandle $multi, public CurlHandle $curl, public Deferred $deferred, public $file, public $headers) {

    }

    public function close() : void {
        if (!$this->closed) {
            curl_pause($this->curl, CURLPAUSE_CONT);
            curl_multi_remove_handle($this->multi, $this->curl);
            curl_multi_close($this->multi);
            curl_setopt($this->curl, CURLOPT_XFERINFOFUNCTION, null);
            curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, null);
            curl_setopt($this->curl, CURLOPT_INFILE, null);
            curl_close($this->curl);
            unset($this->multi);
            unset($this->curl);
            fclose($this->headers);
            if (is_resource($this->file)) {
                fclose($this->file);
            }
            $this->closed = true;
        }
        if (isset($this->deferred)) {
            $this->deferred->promise()->cancel();
            unset($this->deferred);
        }
    }

    public function __destruct() {
        $this->close();
    }

}