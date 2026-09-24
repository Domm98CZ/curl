<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

/**
 * @internal
 */
final class RequestBodyPolicy
{
    // Every CURLOPT_* key that decides what body reaches the wire. Resolved by name because
    // CURLOPT_INFILESIZE_LARGE has no PHP binding on every supported version.
    private const BODY_OPTION_NAMES = [
        'CURLOPT_POSTFIELDS', 'CURLOPT_INFILE', 'CURLOPT_INFILESIZE', 'CURLOPT_INFILESIZE_LARGE',
        'CURLOPT_READDATA', 'CURLOPT_READFUNCTION', 'CURLOPT_UPLOAD', 'CURLOPT_PUT', 'CURLOPT_POST',
        'CURLOPT_CUSTOMREQUEST', 'CURLOPT_NOBODY', 'CURLOPT_HTTPGET',
    ];

    // HEAD carries no request body: under CURLOPT_NOBODY libcurl is expected to discard whatever the
    // body options describe (POSTFIELDS, INFILESIZE and READFUNCTION leaving upload=0 with no
    // Content-Length on the wire), while CURLOPT_UPLOAD - the one switch that would send it - clears
    // no_body, so libcurl would stop treating the exchange as HEAD and block waiting for a response
    // body the server is not allowed to send. No test exercises these combinations - the option
    // filter above refuses them first - so this records the expectation, not a standing measurement.
    public static function methodCarriesRequestBody(string $method): bool
    {
        return $method !== 'HEAD';
    }

    // Deciding the shape must never consume the body: getContents() and rewind() would spend a
    // one-shot stream just by being asked what it looks like, so the size is the only input.
    public static function shapeForSize(?int $size): BodyShape
    {
        if ($size === 0) {
            return BodyShape::None;
        }
        if ($size !== null && $size <= CurlOptionsMapper::REPLAYABLE_BODY_LIMIT) {
            return BodyShape::Buffered;
        }
        return BodyShape::Streamed;
    }

    /** @param array<int, mixed> $curlOptions */
    public static function writesBody(array $curlOptions): bool
    {
        foreach (self::bodyOptionKeys() as $key) {
            if (array_key_exists($key, $curlOptions)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    private static function bodyOptionKeys(): array
    {
        /** @var list<int>|null $keys */
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }

        $keys = [];
        foreach (self::BODY_OPTION_NAMES as $name) {
            if (defined($name)) {
                $keys[] = constant($name);
            }
        }

        return $keys;
    }
}
