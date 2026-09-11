<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Io;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown by Browser::sendRequest() when the request could not be completed
 * (connection failure, timeout, malformed response, ...).
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface {
    private RequestInterface $request;

    public function __construct(string $message, RequestInterface $request, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    public function getRequest(): RequestInterface {
        return $this->request;
    }
}
