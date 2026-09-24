<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

final class TransferInfo
{
    public function __construct(
        public readonly float $totalTimeMs,
        public readonly float $nameLookupTimeMs,
        public readonly float $connectTimeMs,
        public readonly ?int $sizeUpload,
        public readonly ?int $sizeDownload,
        public readonly ?string $primaryIp,
        public readonly float $startTransferTimeMs,
    ) {
    }

    /** @param array<string, mixed> $curlInfo raw curl_getinfo() output */
    public static function fromCurlInfo(array $curlInfo): self
    {
        return new self(
            totalTimeMs: (float) ($curlInfo['total_time'] ?? 0.0) * 1000,
            nameLookupTimeMs: (float) ($curlInfo['namelookup_time'] ?? 0.0) * 1000,
            connectTimeMs: (float) ($curlInfo['connect_time'] ?? 0.0) * 1000,
            startTransferTimeMs: (float) ($curlInfo['starttransfer_time'] ?? 0.0) * 1000,
            sizeUpload: self::intOrNull($curlInfo['size_upload'] ?? null),
            sizeDownload: self::intOrNull($curlInfo['size_download'] ?? null),
            primaryIp: $curlInfo['primary_ip'] ?? null,
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
