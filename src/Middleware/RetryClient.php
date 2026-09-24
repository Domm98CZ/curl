<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Middleware;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\BackoffStrategyInterface;
use Domm98CZ\Curl\Contract\HttpClientInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\ReplayAwareInterface;
use Domm98CZ\Curl\Contract\RetryAwareInterface;
use Domm98CZ\Curl\Contract\RetryPolicyInterface;
use Domm98CZ\Curl\Contract\SleeperInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Retry\SystemSleeper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class RetryClient implements HttpClientInterface, ReplayAwareInterface
{
    private readonly SleeperInterface $sleeper;

    /** @param int $maxRetryAfterSeconds an upstream Retry-After beyond this is ignored so a hostile or broken header cannot stall the caller */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly RetryPolicyInterface $policy,
        private readonly BackoffStrategyInterface $backoff,
        ?SleeperInterface $sleeper = null,
        private readonly int $maxRetryAfterSeconds = 60,
    ) {
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    /** three attempts on 429/502/503/504 and transport failures, exponential backoff with jitter */
    public static function withDefaults(ClientInterface $inner): self
    {
        return new self($inner, new MaxAttemptsRetryPolicy(), new ExponentialBackoff());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    // Forwarded rather than answered here so stacked middleware still reaches the one participant
    // that knows what the mapping sends.
    public function replayabilityOf(RequestInterface $request, ?OptionsInterface $options = null): BodyReplay
    {
        return $this->inner instanceof ReplayAwareInterface
            ? $this->inner->replayabilityOf($request, $options)
            : BodyReplay::Unknown;
    }

    public function send(RequestInterface $request, ?OptionsInterface $options = null): ResponseInterface
    {
        $attempt = 1;

        while (true) {
            if ($attempt > 1 && $request->getBody()->isSeekable()) {
                // A stream can claim to be seekable and still refuse the seek; letting that escape as a
                // bare RuntimeException would put a failure a PSR-18 consumer cannot catch on a path
                // whose whole job is to decide whether the body can be replayed.
                try {
                    $request->getBody()->rewind();
                } catch (\Throwable $exception) {
                    throw $this->cannotRetry('request body', $exception);
                }
            }

            try {
                $response = $this->sendOnce($request, $options);
            } catch (ClientExceptionInterface $exception) {
                if (!$this->policy->shouldRetry($request, null, $exception, $attempt)) {
                    throw $exception;
                }
                $this->prepareRetry($request, $options, $exception);
                $this->sleeper->sleep($this->backoff->delayFor($attempt));
                $attempt++;
                continue;
            }

            if (!$this->policy->shouldRetry($request, $response, null, $attempt)) {
                return $response;
            }
            $this->prepareRetry($request, $options, $response);
            $this->sleeper->sleep($this->delayFor($attempt, $response));
            $attempt++;
        }
    }

    private function sendOnce(RequestInterface $request, ?OptionsInterface $options): ResponseInterface
    {
        if ($this->inner instanceof HttpClientInterface) {
            return $this->inner->send($request, $options);
        }
        if ($options !== null) {
            throw new ClientException(sprintf(
                'The decorated client %s cannot carry per-request options; decorate a %s instead.',
                $this->inner::class,
                HttpClientInterface::class
            ));
        }
        return $this->inner->sendRequest($request);
    }

    private function prepareRetry(RequestInterface $request, ?OptionsInterface $options, ResponseInterface|Throwable $cause): void
    {
        // A consumed non-seekable body cannot be replayed, and the mapper would hand libcurl a read
        // callback that is already at EOF: the retry would put an empty body on the wire and the
        // consumer would read the resulting response as if the payload had been delivered.
        $body = $request->getBody();
        $verdict = $this->replayabilityOf($request, $options);

        // On a non-seekable stream getSize() is whatever fstat reports for a pipe or a socket, so a
        // reported 0 is an artifact rather than proof the body is empty and cannot exempt the refusal.
        $refusedBy = match ($verdict) {
            BodyReplay::Replayable => null,
            // a refusing verdict also covers the per-request options, so naming only the body would
            // send the caller looking at a stream that may have nothing wrong with it
            BodyReplay::NotReplayable => 'request body or its per-request options',
            BodyReplay::Unknown => $body->isSeekable() ? null : 'request body',
        };

        if ($refusedBy !== null) {
            throw $this->cannotRetry($refusedBy, $cause);
        }

        if ($options === null) {
            return;
        }

        $collaborators = [
            'stream handler' => $options->getStreamHandler(),
            'transfer info collector' => $options->getTransferInfoCollector(),
        ];

        foreach ($collaborators as $name => $collaborator) {
            if ($collaborator === null) {
                continue;
            }
            if ($collaborator instanceof RetryAwareInterface && $collaborator->resetForRetry()) {
                continue;
            }

            throw $this->cannotRetry($name, $cause);
        }
    }

    private function cannotRetry(string $collaborator, ResponseInterface|Throwable $cause): ClientException
    {
        $reason = $cause instanceof ResponseInterface
            ? sprintf('HTTP response status %d', $cause->getStatusCode())
            : sprintf('%s: %s', $cause::class, $cause->getMessage());

        return new ClientException(
            sprintf('Cannot retry after %s because the %s cannot reset safely.', $reason, $collaborator),
            previous: $cause instanceof Throwable ? $cause : null,
        );
    }

    private function delayFor(int $attempt, ResponseInterface $response): int
    {
        return $this->retryAfterMicroseconds($response) ?? $this->backoff->delayFor($attempt);
    }

    private function retryAfterMicroseconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }

        $seconds = ctype_digit($value)
            ? (int) $value
            : $this->secondsUntilHttpDate($value);

        if ($seconds === null || $seconds < 0 || $seconds > $this->maxRetryAfterSeconds) {
            return null;
        }

        return $seconds * 1_000_000;
    }

    private function secondsUntilHttpDate(string $value): ?int
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return $timestamp - time();
    }
}
