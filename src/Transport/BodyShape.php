<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

/**
 * The effective shape the mapper gave a request body, not a property of the PSR-7 stream it came
 * from. Buffered means the bytes sit in CURLOPT_POSTFIELDS, which libcurl can replay within one
 * transfer; it says nothing about whether the original stream could be read a second time.
 *
 * @internal
 */
enum BodyShape
{
    case None;
    case Buffered;
    case Streamed;

    public function hasBody(): bool
    {
        return $this !== self::None;
    }

    public function isStreamed(): bool
    {
        return $this === self::Streamed;
    }
}
