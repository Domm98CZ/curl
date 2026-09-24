<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Retry;

use Domm98CZ\Curl\Contract\SleeperInterface;

final class SystemSleeper implements SleeperInterface
{
    public function sleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
