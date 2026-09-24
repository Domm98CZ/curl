<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use InvalidArgumentException;

final class MaxResponseSize implements CurlOptionInterface
{
    public function __construct(private readonly int $bytes)
    {
        if ($bytes <= 0) {
            throw new InvalidArgumentException('Maximum response size must be a positive integer.');
        }
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_MAXFILESIZE => $this->bytes];
    }
}
