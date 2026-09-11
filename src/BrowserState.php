<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use React\EventLoop;

/**
 * Mutable request state shared by all clones of a Browser.
 *
 * Configuration (`with*()`) is immutable per clone, but in-flight requests, the
 * cURL multi handles driving them and the tick scheduling belong to the client
 * as a whole. Every `new Browser()` gets its own state; `with*()` clones share
 * the state of the instance they were derived from.
 */
final class BrowserState {

    /**
     * @var \SplObjectStorage<\CurlMultiHandle, Transaction>
     */
    public \SplObjectStorage $inProgress;

    /** Pending curlTick wake-up timer, cancelled once no requests are in progress */
    public ?EventLoop\TimerInterface $tickTimer = null;

    /** Whether a curlTick callback is queued or currently running */
    public bool $tickScheduled = false;

    public function __construct() {
        $this->inProgress = new \SplObjectStorage();
    }
}
