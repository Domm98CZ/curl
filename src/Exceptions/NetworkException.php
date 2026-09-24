<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class NetworkException extends ClientException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?Throwable $previous = null,
        int $curlErrno = 0
    ) {
        parent::__construct($message, $curlErrno, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
