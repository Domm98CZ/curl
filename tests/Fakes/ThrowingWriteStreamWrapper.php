<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use RuntimeException;

/**
 * A body resource whose every write throws, and throws something different each time. The harness
 * trap disarms after the first hit, so it cannot show whether a second failure overwrites the first
 * one that was recorded.
 */
final class ThrowingWriteStreamWrapper
{
    public const SCHEME = 'curl-test-refusing-write';

    private static int $writes = 0;

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        self::$writes = 0;
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
        self::$writes++;

        throw new RuntimeException(sprintf('write #%d refused', self::$writes));
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
