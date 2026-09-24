<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\SleeperInterface;

final class FakeSleeper implements SleeperInterface
{
    /** @var int[] */
    public array $sleptFor = [];

    public function sleep(int $microseconds): void
    {
        $this->sleptFor[] = $microseconds;
    }
}
