<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface SleeperInterface
{
    public function sleep(int $microseconds): void;
}
