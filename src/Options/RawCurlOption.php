<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class RawCurlOption implements CurlOptionInterface
{
    public function __construct(
        private readonly int $option,
        private readonly mixed $value,
    ) {
    }

    public function toCurlOptions(): array
    {
        return [$this->option => $this->value];
    }
}
