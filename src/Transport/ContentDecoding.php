<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

/** @internal */
final class ContentDecoding
{
    /** @param array<int, mixed> $curlOptions */
    public static function isEnabled(array $curlOptions): bool
    {
        // a written null leaves libcurl's decoder off, so it must not count as a present encoding
        if (!array_key_exists(CURLOPT_ENCODING, $curlOptions) || $curlOptions[CURLOPT_ENCODING] === null) {
            return false;
        }
        if (!array_key_exists(CURLOPT_HTTP_CONTENT_DECODING, $curlOptions)) {
            return true;
        }

        // the binding coerces this long option like an int cast, so null, 'no' and 0.5 all switch it off
        return (int) $curlOptions[CURLOPT_HTTP_CONTENT_DECODING] !== 0;
    }
}
