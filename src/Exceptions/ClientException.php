<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Exceptions;

use Domm98CZ\Curl\RawResponse;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class ClientException extends RuntimeException implements ClientExceptionInterface
{
    // CURLE_URL_MALFORMAT_USER is deprecated/unavailable on some libcurl builds — only the stable CURLE_URL_MALFORMAT is used here
    private const REQUEST_LEVEL_ERRNOS = [CURLE_URL_MALFORMAT, CURLE_UNSUPPORTED_PROTOCOL];

    public function __construct(
        string $message = '',
        private readonly int $curlErrno = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $curlErrno, $previous);
    }

    public function getCurlErrno(): int
    {
        return $this->curlErrno;
    }

    public static function forTransportError(RawResponse $raw, RequestInterface $request): ClientExceptionInterface
    {
        if ($raw->responseSizeExceeded) {
            return new ResponseSizeException($raw->error, $request, null, $raw->errno);
        }
        if (in_array($raw->errno, self::REQUEST_LEVEL_ERRNOS, true)) {
            return new RequestException($raw->error, $request, null, $raw->errno);
        }
        return new NetworkException($raw->error, $request, null, $raw->errno);
    }
}
