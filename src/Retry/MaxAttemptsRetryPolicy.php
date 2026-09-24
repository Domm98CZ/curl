<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Retry;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Domm98CZ\Curl\Contract\RetryPolicyInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MaxAttemptsRetryPolicy implements RetryPolicyInterface
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /**
     * @param int[] $retryableStatusCodes
     * @param bool $retryNonIdempotentMethods replaying a POST/PATCH can duplicate a payment or an
     *        order, so it stays opt-in even though the transport cannot tell the request arrived
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly array $retryableStatusCodes = [429, 502, 503, 504],
        private readonly bool $retryNonIdempotentMethods = false,
    ) {
    }

    public function shouldRetry(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?ClientExceptionInterface $exception,
        int $attempt
    ): bool {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if (!$this->retryNonIdempotentMethods && !$this->isIdempotent($request)) {
            return false;
        }
        if ($exception !== null) {
            // a RequestExceptionInterface means the request itself is unusable — replaying it cannot help
            return !$exception instanceof RequestExceptionInterface
                && !$exception instanceof NonRetryableExceptionInterface;
        }
        return $response !== null && in_array($response->getStatusCode(), $this->retryableStatusCodes, true);
    }

    private function isIdempotent(RequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::IDEMPOTENT_METHODS, true);
    }
}
