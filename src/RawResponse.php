<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Psr\Http\Message\StreamInterface;

final class RawResponse
{
    /**
     * @param array<string, mixed> $info raw curl_getinfo() output
     * @param bool $bodyBuffered false when a stream handler consumed the body, leaving $body empty
     * @param bool $responseSizeExceeded only meaningful alongside a non-zero $errno, because consumers
     *                                   reach it through isTransportError() and never see it otherwise
     */
    public function __construct(
        public readonly int $errno,
        public readonly string $error,
        public readonly string $headerRaw,
        public readonly StreamInterface $body,
        public readonly array $info,
        public readonly bool $bodyBuffered = true,
        public readonly bool $responseSizeExceeded = false,
    ) {
    }

    public function isTransportError(): bool
    {
        return $this->errno !== 0;
    }
}
