<?php declare(strict_types=1);

class FifoStreamWrapper {

    protected int $maxBlocks;

    const BLOCK_SIZE = 8192; //This is how CURL reads

    static private array $buffers = [];

    static private int $bufferIndex = 0;

    static private array $bufferInfo = [];

    static private array $hasWriter = [];

    /** @var string Mode in which the stream was opened */
    private string $mode;

    protected int $bufferKey;

    /** @var resource|null Stream context (this is set by PHP) */
    public $context;

    protected ?Closure $fullCallback;

    protected ?Closure $drainCallback;

    protected ?Closure $emptyCallback;

    protected ?Closure $availableCallback;

    private SplQueue $buffer;

    function stream_open($path, $mode, $options, &$opened_path): bool
    {
        $index = parse_url($path, PHP_URL_HOST);
        if (false === $index) {
            $index = self::$bufferIndex++;
        } else {
            $index = (int)$index;
        }
        $this->bufferKey = $index;

        try {
            match ($mode) {
                "w" => $this->startWriter(),
                "r" => $this->startReader(),
            };
        } catch (UnhandledMatchError $ex) {
            return false;
        }
        $this->mode = $mode;

        //@TODO Use memory_limit to limit size of buffer
        $this->maxBlocks = (int)floor(10000000 / self::BLOCK_SIZE);

        $contextOptions = stream_context_get_options($this->context);
        $fifoOptions = $contextOptions['fifo'] ?? [];
        $this->fullCallback = $fifoOptions['onFull'] ?? null;
        $this->drainCallback = $fifoOptions['onDrain'] ?? null;
        $this->emptyCallback = $fifoOptions['onEmpty'] ?? null;
        $this->availableCallback = $fifoOptions['onAvailable'] ?? null;
        self::$buffers[$this->bufferKey] ??= new SplQueue();
        $this->buffer = self::$buffers[$this->bufferKey];
        self::$bufferInfo[$this->bufferKey] ??= [
            'blockCount' => 0,
            'bytesWritten' => 0,
            'bytesRead' => 0,
            'full' => false,
            'fullcount' => 0,
            'draincount' => 0,
        ];

        return true;
    }

    protected function startWriter(): void
    {
        self::$hasWriter[$this->bufferKey] = true;
    }

    protected function startReader() {

    }

    function stream_read($count)
    {
        if ($this->mode === 'r') {
            $buffer = $this->buffer;
            if (self::$bufferInfo[$this->bufferKey]['blockCount'] === 0) {
                $block = '';
            } else {
                $block = $buffer->shift();
                --self::$bufferInfo[$this->bufferKey]['blockCount'];
            }

            $blockSize = strlen($block);

            if ($blockSize > $count) {
                //Unaligned reads
                $remaining = substr($block, $count);
                $block = substr($block,0, $count);
                $blockSize = $count;
                $buffer->unshift($remaining); //Pop remaining data back on (Slow)
                ++self::$bufferInfo[$this->bufferKey]['blockCount'];
            }

            if ($blockSize === 0 && $this->stream_eof()) {
                return '';
            }

            self::$bufferInfo[$this->bufferKey]['bytesRead'] += $blockSize;

            if ((self::$bufferInfo[$this->bufferKey]['full']) && self::$bufferInfo[$this->bufferKey]['blockCount'] < (int)ceil($this->maxBlocks/2)) {
                ++self::$bufferInfo[$this->bufferKey]['draincount'];
                self::$bufferInfo[$this->bufferKey]['full'] = false;
                if (isset($this->drainCallback)) { ($this->drainCallback)();}
            }

            if ($blockSize === 0) {
                if(isset($this->emptyCallback)) {
                    ($this->emptyCallback)();
                }
                return false; //No data available
            }

            return $block;
        }
        return null;
    }

    function stream_write($data)
    {
        if ($this->mode === 'w') {
            if ($data === '') {
                return 0;
            }

            $bufferBlockCount = self::$bufferInfo[$this->bufferKey]['blockCount'];

            $sendAvailableOnWrite = false;
            if ($bufferBlockCount === 0) {
                $sendAvailableOnWrite = true;
            }

            if ($bufferBlockCount >= $this->maxBlocks) { //Still full
                self::$bufferInfo[$this->bufferKey]['full'] = true;
                return 0;
            }

            $data = str_split($data, self::BLOCK_SIZE);
            $newDataBlockCount = count($data);

            if (($bufferBlockCount + $newDataBlockCount) > $this->maxBlocks) {
                $blockCount = $this->maxBlocks - $bufferBlockCount;
                if ($blockCount === 0) {
                    return 0;
                }
                $data = array_slice($data, 0, $blockCount);
                $newDataBlockCount = count($data);
            }

            $bufferBlockCount += $newDataBlockCount;
            self::$bufferInfo[$this->bufferKey]['blockCount'] = $bufferBlockCount; //Update out current count

            $writtenDataBytes = $newDataBlockCount * self::BLOCK_SIZE;
            $lastBlock = $data[array_key_last($data)];
            $lastBlockLen = strlen($lastBlock);
            if ($lastBlockLen < self::BLOCK_SIZE) {
                $writtenDataBytes -= (self::BLOCK_SIZE - $lastBlockLen);
            }
            foreach($data as $block) {
                $this->buffer->push($block);
            }
            self::$bufferInfo[$this->bufferKey]['bytesWritten'] += $writtenDataBytes;

            if ($bufferBlockCount >= $this->maxBlocks) {
                self::$bufferInfo[$this->bufferKey]['full'] = true;
                ++self::$bufferInfo[$this->bufferKey]['fullcount'];
                if (isset($this->fullCallback)) {
                    ($this->fullCallback)();
                }
            }

            if ($sendAvailableOnWrite && isset($this->availableCallback)) {
                ($this->availableCallback)();
            }
            return $writtenDataBytes;
        }
        return 0;
    }

    function stream_eof(): bool
    {
        if ($this->mode === 'w' || (self::$hasWriter[$this->bufferKey] ?? false)) {
            return false;
        }
        return count(self::$buffers[$this->bufferKey] ?? []) === 0;
    }

    function stream_seek($offset, $whence): false
    {
        return false;
    }

    function stream_set_option(int $option, int $arg1, ?int $arg2): true
    {
        return true;
    }

    function stream_cast(int $cast_as): false
    {
        return false;
    }

    public function stream_stat(): array|false {
        $info = self::$bufferInfo[$this->bufferKey];
        return [
            0  => 0,  'dev'     => 0,
            1  => $this->bufferKey,  'ino'     => $this->bufferKey,
            2  => $this->mode,  'mode'    => $this->mode,
            3  => 0,  'nlink'   => 0,
            4  => 0,  'uid'     => 0,
            5  => 0,  'gid'     => 0,
            6  => -1, 'rdev'    => -1,
            7  => ($info['bytesWritten']-$info['bytesRead']),  'size'    => ($info['bytesWritten']-$info['bytesRead']),
            8  => 0,  'atime'   => 0,
            9  => 0,  'mtime'   => 0,
            10 => 0,  'ctime'   => 0,
            11 => self::BLOCK_SIZE, 'blksize' => self::BLOCK_SIZE,
            12 => ($info['blockCount']*(self::BLOCK_SIZE/512)), 'blocks' => ($info['blockCount']*(self::BLOCK_SIZE/512)),
        ];
    }

    function stream_close(): void
    {
        unset($this->buffer);
        if ($this->mode === 'w') {
            //If writer we can close but keep reading buffer around
            self::$hasWriter[$this->bufferKey] = false;
        } elseif (!(self::$hasWriter[$this->bufferKey] ?? false) && array_key_exists($this->bufferKey, self::$buffers)) {
            //If reader we cleanup on close if no writer
            if (self::$bufferInfo[$this->bufferKey]['blockCount'] !== 0) {
                trigger_error('FIFO Stream closed before all data read', E_USER_WARNING);
            }
            unset(self::$buffers[$this->bufferKey]);
            unset(self::$bufferInfo[$this->bufferKey]);
        }
    }

    public function __destruct()
    {
        $this->stream_close();
    }
}

stream_register_wrapper('fifo', FifoStreamWrapper::class);