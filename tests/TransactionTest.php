<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\BrowserState;
use EdgeTelemetrics\React\Http\Transaction;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

class TransactionTest extends TestCase
{
    public function testCloseIsIdempotentAndToleratesReentrantCloseFromCanceller(): void
    {
        $multi = curl_multi_init();
        $curl = curl_init('http://127.0.0.1:1/');
        curl_multi_add_handle($multi, $curl);

        $cancellations = 0;
        $transaction = null;
        $deferred = new Deferred(static function () use (&$transaction, &$cancellations): void {
            $cancellations++;
            // Browser's deferred canceller closes the transaction when the promise is
            // cancelled; this must not repeat the cURL cleanup or cancel recursively.
            $transaction->close();
        });

        $transaction = new Transaction(
            $multi,
            $curl,
            $deferred,
            fopen('php://memory', 'w+'),
            new BrowserState()
        );

        $transaction->close();

        $this->assertTrue($transaction->isClosed());
        $this->assertSame(1, $cancellations);

        // A second close (e.g. from __destruct()) must be a no-op
        $transaction->close();
        $this->assertTrue($transaction->isClosed());
        $this->assertSame(1, $cancellations);
    }

    public function testCloseDefersCurlTeardownWhileInsideCurlCallback(): void
    {
        $state = new BrowserState();
        $multi = curl_multi_init();
        $curl = curl_init('http://127.0.0.1:1/');
        curl_multi_add_handle($multi, $curl);

        $deferred = new Deferred();
        $transaction = new Transaction(
            $multi,
            $curl,
            $deferred,
            fopen('php://memory', 'w+'),
            $state
        );

        // Simulate being called from inside curl_multi_exec() (e.g. cancelAll() from a
        // streaming response handler): the cURL handles must stay attached until the
        // outermost exec has returned.
        $state->curlCallbackDepth = 1;

        $transaction->close();

        $this->assertTrue($transaction->isClosed());
        $this->assertCount(1, $state->pendingTeardown);
        $this->assertSame($transaction, $state->pendingTeardown[0]);
        $this->assertTrue(isset($transaction->multi), 'cURL handles must not be released from inside a libcurl callback');
        $this->assertTrue(isset($transaction->curl), 'cURL handles must not be released from inside a libcurl callback');

        // Browser::flushPendingTeardown() equivalent
        $state->curlCallbackDepth = 0;
        $pending = $state->pendingTeardown;
        $state->pendingTeardown = [];
        foreach ($pending as $pendingTransaction) {
            $pendingTransaction->closeNow();
        }

        $this->assertFalse(isset($transaction->multi));
        $this->assertFalse(isset($transaction->curl));

        // closeNow() must be idempotent
        $transaction->closeNow();
        $this->assertFalse(isset($transaction->multi));
    }
}
