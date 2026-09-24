<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Exceptions;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class RequestException extends ClientException implements RequestExceptionInterface
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
