<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Retry;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Retry\SystemSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\RequestExceptionInterface;

final class RetryPolicyAndBackoffTest extends TestCase
{
    public function testRetriesOnTransportException(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3);
        $request = new Request('GET', '/');
        $exception = new NetworkException('refused', $request);

        self::assertTrue($policy->shouldRetry($request, null, $exception, 1));
    }

    public function testRetriesOnRetryableStatusCode(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3, retryableStatusCodes: [503]);
        $request = new Request('GET', '/');

        self::assertTrue($policy->shouldRetry($request, new Response(503), null, 1));
    }

    public function testDoesNotRetryOnNonRetryableStatusCode(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3, retryableStatusCodes: [503]);
        $request = new Request('GET', '/');

        self::assertFalse($policy->shouldRetry($request, new Response(404), null, 1));
    }

    public function testStopsAtMaxAttempts(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 2);
        $request = new Request('GET', '/');
        $exception = new NetworkException('refused', $request);

        self::assertFalse($policy->shouldRetry($request, null, $exception, 2));
    }

    public function testExponentialBackoffDoublesEachAttempt(): void
    {
        $backoff = new ExponentialBackoff(baseDelayMicroseconds: 100_000, maxDelayMicroseconds: 10_000_000, jitter: false);

        self::assertSame(100_000, $backoff->delayFor(1));
        self::assertSame(200_000, $backoff->delayFor(2));
        self::assertSame(400_000, $backoff->delayFor(3));
    }

    public function testExponentialBackoffIsCappedAtMaxDelay(): void
    {
        $backoff = new ExponentialBackoff(baseDelayMicroseconds: 1_000_000, maxDelayMicroseconds: 2_000_000, jitter: false);
        self::assertSame(2_000_000, $backoff->delayFor(5));
    }

    public function testJitterIsOnByDefaultAndStaysWithinTheCappedDelay(): void
    {
        $backoff = new ExponentialBackoff(baseDelayMicroseconds: 100_000, maxDelayMicroseconds: 10_000_000);

        $delays = [];
        for ($i = 0; $i < 25; $i++) {
            $delay = $backoff->delayFor(3);
            self::assertGreaterThanOrEqual(0, $delay);
            self::assertLessThanOrEqual(400_000, $delay);
            $delays[] = $delay;
        }

        self::assertGreaterThan(1, count(array_unique($delays)));
    }

    public function testDoesNotRetryNonIdempotentMethodsByDefault(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3, retryableStatusCodes: [503]);

        self::assertFalse($policy->shouldRetry(new Request('POST', '/'), new Response(503), null, 1));
        self::assertFalse($policy->shouldRetry(new Request('PATCH', '/'), new Response(503), null, 1));
    }

    public function testRetriesNonIdempotentMethodsOnlyWhenExplicitlyOptedIn(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3, retryableStatusCodes: [503], retryNonIdempotentMethods: true);

        self::assertTrue($policy->shouldRetry(new Request('POST', '/'), new Response(503), null, 1));
    }

    public function testRetriesIdempotentMethods(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3, retryableStatusCodes: [503]);

        foreach (['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'] as $method) {
            self::assertTrue($policy->shouldRetry(new Request($method, '/'), new Response(503), null, 1), $method);
        }
    }

    public function testDoesNotRetryARequestLevelException(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3);
        $request = new Request('GET', '/');

        self::assertFalse($policy->shouldRetry($request, null, new RequestException('malformed', $request), 1));
    }

    public function testDoesNotRetryAFailureMarkedNonRetryable(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3);
        $request = new Request('GET', '/');
        $failure = ResponseBufferException::forRefusedWrite(0, 5);

        self::assertFalse($policy->shouldRetry($request, null, $failure, 1));
        // The marker, not RequestExceptionInterface, is what stops the retry: the request was fine.
        self::assertNotInstanceOf(RequestExceptionInterface::class, $failure);
    }

    public function testHonoursTheNonRetryableMarkerOnAnyClientException(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3);
        $request = new Request('GET', '/');
        $failure = new class ('local resource exhausted') extends ClientException implements NonRetryableExceptionInterface {
        };

        self::assertFalse($policy->shouldRetry($request, null, $failure, 1));
    }

    public function testDoesNotRetryNonIdempotentMethodsOnATransportException(): void
    {
        $policy = new MaxAttemptsRetryPolicy(maxAttempts: 3);
        $request = new Request('POST', '/');

        self::assertFalse($policy->shouldRetry($request, null, new NetworkException('refused', $request), 1));
    }

    public function testSystemSleeperActuallySleeps(): void
    {
        $start = hrtime(true);
        (new SystemSleeper())->sleep(10_000);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertGreaterThanOrEqual(8.0, $elapsedMs);
    }
}
