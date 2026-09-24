<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class ConnectTimeout implements CurlOptionInterface
{
    public function __construct(private readonly int $seconds)
    {
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_CONNECTTIMEOUT => $this->seconds];
    }
}
