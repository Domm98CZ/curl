<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Exceptions\RequestException;
use Psr\Http\Message\RequestInterface;

/** @internal */
final class HttpSchemeGuard
{
    private const SUPPORTED_SCHEMES = ['', 'http', 'https'];

    public static function isHttp(string $scheme): bool
    {
        return in_array(strtolower($scheme), self::SUPPORTED_SCHEMES, true);
    }

    public static function assert(RequestInterface $request): void
    {
        $scheme = strtolower($request->getUri()->getScheme());
        if (self::isHttp($scheme)) {
            return;
        }

        throw new RequestException(
            sprintf(
                'Scheme "%s" is outside the PSR-18 contract this client implements; use RequestBuilder::sendRaw() for non-HTTP protocols.',
                $scheme
            ),
            $request
        );
    }
}
