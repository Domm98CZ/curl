<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use InvalidArgumentException;

final class HttpGrammar
{
    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/';
    private const HEADER_VALUE_FORBIDDEN = '/[\r\n\0]/';
    private const HOST = '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9\-._~%!$&\'()*+,;=]*)\z/';
    private const SCHEME = '/^[A-Za-z][A-Za-z0-9+\-.]*\z/';
    private const BOUNDARY = '/^[0-9A-Za-z\'()+_,\-.\/:=?]{1,70}\z/';
    private const REQUEST_TARGET = '/^[\x21-\x7E]+\z/';

    public static function assertMethod(string $method): void
    {
        if (preg_match(self::TOKEN, $method) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP method: "%s". RFC 9110 requires a token (no spaces, control or separator characters).',
                $method
            ));
        }
    }

    // An allow-list, not a deny-list: bytes >= 0x80 include the overlong UTF-8 encodings of CR and LF,
    // which an intermediary doing lenient decoding turns back into a request line split.
    public static function assertRequestTarget(string $requestTarget): void
    {
        if (preg_match(self::REQUEST_TARGET, $requestTarget) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid request target: "%s". RFC 9110 allows only printable ASCII (0x21-0x7E), '
                    . 'so it must not be empty or contain spaces, control or non-ASCII characters.',
                $requestTarget
            ));
        }
    }

    public static function assertHeaderName(string $name): void
    {
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid header name: "%s".', $name));
        }
    }

    public static function assertHeaderValue(string $value): void
    {
        if (preg_match(self::HEADER_VALUE_FORBIDDEN, $value) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid header value: "%s" contains a CR, LF or NUL byte.',
                $value
            ));
        }
    }

    // PSR-7 asks for a "3-digit integer result code", not for RFC 9110's 100-599: this library's own
    // parser legitimately produces codes up to 999 and real servers send them, so refusing 600-999
    // here would throw on a code the library itself handed the consumer.
    public static function assertStatusCode(int $code): void
    {
        if ($code < 100 || $code > 999) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP status code: %d. PSR-7 requires a three-digit code (100-999).',
                $code
            ));
        }
    }

    public static function assertReasonPhrase(string $phrase): void
    {
        if (preg_match(self::HEADER_VALUE_FORBIDDEN, $phrase) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid reason phrase: "%s" contains a CR, LF or NUL byte.',
                $phrase
            ));
        }
    }

    public static function encodeUserInfo(string $value): string
    {
        self::assertHeaderValue($value);

        $encoded = preg_replace_callback(
            '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:%]+/',
            static fn (array $matches): string => rawurlencode($matches[0]),
            $value
        );
        if ($encoded === null) {
            throw new InvalidArgumentException(sprintf('Unable to encode userinfo: %s', $value));
        }
        return $encoded;
    }

    public static function assertHost(string $host): void
    {
        if (preg_match(self::HOST, $host) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid URI host: "%s".', $host));
        }
    }

    public static function assertScheme(string $scheme): void
    {
        if ($scheme !== '' && preg_match(self::SCHEME, $scheme) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid URI scheme: "%s".', $scheme));
        }
    }

    public static function assertMultipartBoundary(string $boundary): void
    {
        if (preg_match(self::BOUNDARY, $boundary) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid multipart boundary: "%s".', $boundary));
        }
    }

    // Values interpolated into a quoted-string parameter (multipart name/filename) escape it via a
    // quote or backslash just as effectively as via CRLF, so all four bytes are rejected.
    public static function assertQuotedParameter(string $value, string $context): void
    {
        if (preg_match('/["\\\\\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid %s: "%s" contains a quote, backslash, CR, LF or NUL byte.',
                $context,
                $value
            ));
        }
    }
}
