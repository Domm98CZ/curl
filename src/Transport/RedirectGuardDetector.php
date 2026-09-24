<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use Domm98CZ\Curl\RedirectGuards;
use Psr\Http\Message\UriInterface;

/** @internal */
final class RedirectGuardDetector
{
    // This default-deny allowlist treats unknown headers as credentials to keep automatic redirects disabled.
    private const REDIRECT_SAFE_HEADERS = [
        'accept', 'accept-charset', 'accept-encoding', 'accept-language', 'cache-control',
        'connection', 'content-length', 'content-type', 'expect', 'if-match',
        'if-modified-since', 'if-none-match', 'if-range', 'if-unmodified-since', 'pragma',
        'range', 'referer', 'te', 'user-agent',
    ];

    /** @param string[] $headers */
    public static function detect(UriInterface $uri, array $headers, bool $bodyIsStreamed, bool $targetIsPinned): RedirectGuards
    {
        return new RedirectGuards(self::carriesCredentials($uri, $headers), $bodyIsStreamed, $targetIsPinned);
    }

    /** @param string[] $headers */
    private static function carriesCredentials(UriInterface $uri, array $headers): bool
    {
        if ($uri->getUserInfo() !== '') {
            return true;
        }
        foreach ($headers as $header) {
            $separator = strpos($header, ':');
            // CurlOptionsMapper::mapHeaders() emits curl-formatted, not PSR-7, header lines, where ; denotes an empty value.
            if ($separator === false && str_ends_with($header, ';')) {
                $separator = strlen($header) - 1;
            }
            if ($separator === false || !in_array(strtolower(substr($header, 0, $separator)), self::REDIRECT_SAFE_HEADERS, true)) {
                return true;
            }
        }
        return false;
    }
}
