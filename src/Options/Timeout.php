<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use InvalidArgumentException;

final class Timeout implements CurlOptionInterface
{
    private function __construct(private readonly int $milliseconds)
    {
    }

    // zero is libcurl's "no limit" and stays reachable here as the documented sentinel
    public static function seconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Timeout in seconds must not be negative.');
        }

        return new self($seconds * 1000);
    }

    // a millisecond budget is always a budget: zero would silently switch the limit off
    public static function milliseconds(int $milliseconds): self
    {
        if ($milliseconds < 1) {
            throw new InvalidArgumentException('Timeout in milliseconds must be a positive integer.');
        }

        return new self($milliseconds);
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_TIMEOUT_MS => $this->milliseconds];
    }
}
