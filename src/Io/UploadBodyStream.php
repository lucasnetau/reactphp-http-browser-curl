<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Io;
use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use function fclose;
use function fstat;
use function fwrite;
use function strlen;
use function substr;

class UploadBodyStream extends EventEmitter {

    /**
     * @var false|resource
     */
    private $readStream;

    public function __construct(ReadableStreamInterface $input) {
        $that = $this;
        $writeContext = [
            'fifo' => [
                //FIFO drained below low water mark, resume input
                'onDrain' => static function() use ($input) {
                    $input->emit('data', ['']);
                },
                //FIFO contains data, resume the cURL upload
                'onAvailable' => static function() use ($that) {
                    $that->emit('continue');
                },
            ]
        ];

        $readContext = [
            'fifo' => [
                //When FIFO is empty we pause cURL upload and resume writing of the input body to the FIFO
                'onEmpty' => static function() use ($input, $that) {
                    $that->emit('pause');
                    $input->emit('data', ['']);
                },
            ]
        ];

        $writeContext = \stream_context_create($writeContext);
        $readContext = \stream_context_create($readContext);
        $writeStream = \fopen('fifo://', 'w', false, $writeContext);
        $key = fstat($writeStream)['ino'];
        $this->readStream = \fopen('fifo://' . $key, 'r', false, $readContext);

        $pendingClose = false;
        $input->on('data', static function ($data) use (&$writeStream, &$input, &$pendingClose, $that) {
            static $paused = false;
            static $buffer = '';
            $buffer .= $data;
            if (strlen($buffer) > 0) {
                $written = fwrite($writeStream, $buffer);
                if ($written < strlen($buffer)) {
                    $input->pause();
                    $paused = true;
                    if ($written > 0) {
                        $buffer = substr($buffer, $written);
                    }
                    return;
                } else {
                    $buffer = '';
                }
            }
            if ($pendingClose) {
                if ($writeStream !== null) {
                    fclose($writeStream);
                    $writeStream = null;
                    $that->emit('continue'); //Ensure curl isn't blocked on pause
                    $input->removeAllListeners();
                    $that->removeAllListeners();
                }
            } else {
                if ($paused) {
                    $paused = false;
                    $input->resume();
                }
            }
        });
        $input->on('close', static function () use (&$pendingClose, $input) {
            $pendingClose = true;
            $input->emit('data', ['']); //Flush buffers
        });
    }

    public function getReadableStream() {
        return $this->readStream;
    }
}
