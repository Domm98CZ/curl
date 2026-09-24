<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface RetryPolicyInterface
{
    public function shouldRetry(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?ClientExceptionInterface $exception,
        int $attempt
    ): bool;
}
