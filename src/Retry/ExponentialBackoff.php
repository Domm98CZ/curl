<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Retry;

use Domm98CZ\Curl\Contract\BackoffStrategyInterface;

final class ExponentialBackoff implements BackoffStrategyInterface
{
    /** @param bool $jitter spreads retries of concurrently failing clients so they stop hammering in lockstep */
    public function __construct(
        private readonly int $baseDelayMicroseconds = 100_000,
        private readonly int $maxDelayMicroseconds = 5_000_000,
        private readonly bool $jitter = true,
    ) {
    }

    public function delayFor(int $attempt): int
    {
        $delay = $this->baseDelayMicroseconds * (2 ** max(0, $attempt - 1));
        $capped = min($delay, $this->maxDelayMicroseconds);

        return $this->jitter ? random_int(0, $capped) : $capped;
    }
}
