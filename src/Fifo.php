<?php declare(strict_types=1);

class Fifo {

    protected int $maxBlocks;

    const BLOCK_SIZE = 8192; //This is how CURL reads

    static private array $buffers = [];

    static private array $hasWriter = [];

    /** @var string Mode in which the stream was opened */
    private string $mode;

    protected string $bufferKey;

    /** @var resource|null Stream context (this is set by PHP) */
    public $context;

    /**
     * @var bool[]
     */
    static private array $bufferFull = [];

    protected Closure $fullCallback;

    protected Closure $drainCallback;

    function stream_open($path, $mode, $options, &$opened_path): bool
    {
        $url = parse_url($path);
        $this->bufferKey = $url["host"];

        try {
            match ($mode) {
                "w" => $this->startWriter(),
                "r" => $this->startReader(),
            };
        } catch (UnhandledMatchError $ex) {
            return false;
        }
        $this->mode = $mode;

        $this->maxBlocks = (int)round(1000000 / self::BLOCK_SIZE);

        $contextOptions = stream_context_get_options($this->context);
        $fifoOptions = $contextOptions['fifo'] ?? [];
        $this->fullCallback = $fifoOptions['onFull'] ?? static function() {};
        $this->drainCallback = $fifoOptions['onDrain'] ?? static function() {};

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
            self::$buffers[$this->bufferKey] ??= [];

            //$block = array_shift(self::$buffers[$this->bufferKey]) ?? '';
            $block = reset(self::$buffers[$this->bufferKey]); // sets internal array pointer to start
            if ($block === false) {
                $block = '';
            } else {
                unset(self::$buffers[$this->bufferKey][key(self::$buffers[$this->bufferKey])]); // key() returns key of current array element
            }

            $blockSize = strlen($block);

            if ($blockSize > $count) {
                $remaining = substr($block, $count);
                $block = substr($block,0, $count);
                $blockSize = $count;
                array_unshift(self::$buffers[$this->bufferKey], $remaining); //Pop remaining data back on (Slow)
                error_log('unaligned data access');
            }

            if ($blockSize === 0) {
                if ($this->stream_eof()) {
                    return '';
                }
                return false; //No data available
            }

            if ((self::$bufferFull[$this->bufferKey] ?? false) && count(self::$buffers[$this->bufferKey]) < ($this->maxBlocks/2)) {
                self::$bufferFull[$this->bufferKey] = false;
                call_user_func($this->drainCallback);
            }

            return $block;
        }
        return null;
    }

    function stream_write($data)
    {
        if ($this->mode === 'w') {
            self::$buffers[$this->bufferKey] ??= [];

            if ($data === '') {
                return 0;
            }

            $bufferBlockCount = count(self::$buffers[$this->bufferKey]);

            if ($bufferBlockCount > $this->maxBlocks) { //Still full
                return 0;
            }

            $data = str_split($data, self::BLOCK_SIZE);
            $newDataBlockCount = count($data);

            if (($bufferBlockCount + $newDataBlockCount) > $this->maxBlocks) {
                $blockCount = $this->maxBlocks - $bufferBlockCount;
                $data = array_slice($data, 0, $blockCount);
                $newDataBlockCount = count($data);
            }

            if (empty($data)) {
                return 0;
            }

            $writtenDataBytes = $newDataBlockCount * self::BLOCK_SIZE;
            $lastBlock = $data[array_key_last($data)];
            if (strlen($lastBlock) < self::BLOCK_SIZE) {
                $writtenDataBytes -= (self::BLOCK_SIZE - strlen($lastBlock));
            }
            //foreach($data as $block) {
             //   self::$buffers[$this->bufferKey][] = $block;
           // }
            self::$buffers[$this->bufferKey]= array_merge(self::$buffers[$this->bufferKey], $data);

            if (count(self::$buffers[$this->bufferKey]) >= $this->maxBlocks) {
                self::$bufferFull[$this->bufferKey] = true;
                call_user_func($this->fullCallback);
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
         return [
            0  => 0,  'dev'     => 0,
            1  => 0,  'ino'     => 0,
            2  => $this->mode,  'mode'    => $this->mode,
            3  => 0,  'nlink'   => 0,
            4  => 0,  'uid'     => 0,
            5  => 0,  'gid'     => 0,
            6  => -1, 'rdev'    => -1,
            7  => 0,  'size'    => 0,
            8  => 0,  'atime'   => 0,
            9  => 0,  'mtime'   => 0,
            10 => 0,  'ctime'   => 0,
            11 => -1, 'blksize' => -1,
            12 => -1, 'blocks'  => -1,
        ];
    }

    function stream_close(): void
    {
        if ($this->mode === 'w') {
            self::$hasWriter[$this->bufferKey] = false;
        } elseif (!(self::$hasWriter[$this->bufferKey] ?? false)) {
            unset(self::$buffers[$this->bufferKey]);
        }

    }
}

stream_register_wrapper('fifo', Fifo::class, STREAM_IS_URL);