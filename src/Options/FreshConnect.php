<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class FreshConnect implements CurlOptionInterface
{
    public function __construct(private readonly bool $fresh = true)
    {
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_FRESH_CONNECT => $this->fresh];
    }
}
