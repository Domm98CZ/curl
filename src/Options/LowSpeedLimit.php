<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use InvalidArgumentException;

final class LowSpeedLimit implements CurlOptionInterface
{
    public function __construct(
        private readonly int $bytesPerSecond,
        private readonly int $seconds,
    ) {
        // libcurl reads zero in either value as "check disabled"
        if ($bytesPerSecond < 1 || $seconds < 1) {
            throw new InvalidArgumentException('Low speed limit needs a positive rate and a positive window in seconds.');
        }
    }

    public static function stalledFor(int $seconds): self
    {
        return new self(1, $seconds);
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_LOW_SPEED_LIMIT => $this->bytesPerSecond, CURLOPT_LOW_SPEED_TIME => $this->seconds];
    }
}
