<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface BackoffStrategyInterface
{
    /** @return int delay before the next attempt, in microseconds */
    public function delayFor(int $attempt): int;
}
