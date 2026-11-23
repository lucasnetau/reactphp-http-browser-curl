<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

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
            fopen('php://memory', 'w+')
        );

        $transaction->close();

        $this->assertTrue($transaction->isClosed());
        $this->assertSame(1, $cancellations);

        // A second close (e.g. from __destruct()) must be a no-op
        $transaction->close();
        $this->assertTrue($transaction->isClosed());
        $this->assertSame(1, $cancellations);
    }
}
