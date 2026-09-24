<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

/**
 * A body resource that withholds a fixed number of bytes from every write and reports the truncation
 * the way a real failing write does — by return value, with no warning and no exception.
 *
 * The deficit is per call rather than an absolute ceiling because fwrite() re-offers whatever a
 * userland wrapper did not take: an absolute ceiling is reached again on the second pass and the call
 * ends up reporting the full length after all.
 */
final class ShortWriteStreamWrapper
{
    public const SCHEME = 'curl-test-short-write';

    private static int $withheld = 0;

    /** @var resource|null */
    public $context;

    public static function register(int $withheld): void
    {
        self::$withheld = $withheld;
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return max(0, strlen($data) - self::$withheld);
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
