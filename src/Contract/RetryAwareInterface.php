<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface RetryAwareInterface
{
    public function resetForRetry(): bool;
}
