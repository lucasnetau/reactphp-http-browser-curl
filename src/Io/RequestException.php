<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Io;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown by Browser::sendRequest() for invalid requests (bad URL, headers or method).
 * The request was never sent.
 */
final class RequestException extends RuntimeException implements RequestExceptionInterface {
    private RequestInterface $request;

    public function __construct(string $message, RequestInterface $request, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    public function getRequest(): RequestInterface {
        return $this->request;
    }
}
