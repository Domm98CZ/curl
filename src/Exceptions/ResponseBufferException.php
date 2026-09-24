<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Exceptions;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Throwable;

final class ResponseBufferException extends ClientException implements NonRetryableExceptionInterface
{
    public static function forRefusedWrite(int|false $written, int $expected): self
    {
        return new self(
            sprintf(
                'The library could not buffer the response body locally: %s of %d bytes written. '
                . 'The transfer itself did not fail; the local response buffer did.%s',
                $written === false ? 'none' : (string) $written,
                $expected,
                self::lastPhpError(),
            ),
            CURLE_WRITE_ERROR,
        );
    }

    public static function forSinkWrite(string $path, int|false $written, int $expected): self
    {
        return new self(
            sprintf(
                'Unable to write the response body to "%s": %s of %d bytes written. '
                . 'The transfer itself did not fail; the local file did.%s',
                $path,
                $written === false ? 'none' : (string) $written,
                $expected,
                self::lastPhpError(),
            ),
            CURLE_WRITE_ERROR,
        );
    }

    public static function forThrownWrite(Throwable $previous, int $expected): self
    {
        return new self(
            sprintf(
                'The library could not buffer the response body locally: writing %d bytes raised %s. '
                . 'The transfer itself did not fail; the local response buffer did.',
                $expected,
                get_debug_type($previous),
            ),
            CURLE_WRITE_ERROR,
            $previous,
        );
    }

    // A hint only: nothing guarantees the last PHP error belongs to the write that was refused.
    private static function lastPhpError(): string
    {
        $error = error_get_last();
        if ($error === null || $error['message'] === '') {
            return '';
        }

        return sprintf(' Last PHP error at the time (may be unrelated): %s', $error['message']);
    }
}
