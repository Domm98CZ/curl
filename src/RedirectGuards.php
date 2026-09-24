<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

final class RedirectGuards
{
    public function __construct(
        public readonly bool $credentials = false,
        public readonly bool $streamedBody = false,
        public readonly bool $pinnedRequestTarget = false,
    ) {
    }
}
