<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Exceptions;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class ResponseSizeException extends ClientException implements NonRetryableExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?Throwable $previous = null,
        int $curlErrno = 0,
    ) {
        parent::__construct($message, $curlErrno, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
