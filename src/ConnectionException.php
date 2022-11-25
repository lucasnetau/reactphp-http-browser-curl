<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http;

use CurlHandle;
use RuntimeException;
use function curl_errno;
use function curl_error;

/**
 * The `EdgeTelemetrics\React\Http\ConnectionException` is an `Exception` sub-class that will be used to reject
 * a request promise if the cURL exec returns an error (Connection, Resolution, Timeout)
 *
 * The `getCode(): int` method can be used to
 * return the cURL error code which can be used with curl_strerror().
 */
final class ConnectionException extends RuntimeException
{
    public function __construct(CurlHandle $curl, $message = null, $code = null, $previous = null)
    {
        if ($message === null) {
            $message = curl_error($curl) . ' (' . curl_errno($curl) . ')';
        }
        if ($code === null) {
            $code = curl_errno($curl);
        }
        parent::__construct($message, $code, $previous);
    }
}