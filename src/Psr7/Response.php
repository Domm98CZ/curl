<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    use MessageTrait;

    private const REASON_PHRASES = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    private int $statusCode;
    private string $reasonPhrase;

    /** @param array<string, string|string[]> $headers */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        ?string $reasonPhrase = null
    ) {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase ?? self::REASON_PHRASES[$statusCode] ?? '';
        $this->setHeaders($headers);
        $this->body = $body ?? new Stream(self::openTempStream());
        $this->protocolVersion = $protocolVersion;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // The constructor stays tolerant on purpose: HttpResponseParser builds inbound responses through
    // it, and a non-conformant status line from a real server must not escape sendRequest().
    public function withStatus($code, $reasonPhrase = ''): static
    {
        HttpGrammar::assertStatusCode($code);
        HttpGrammar::assertReasonPhrase($reasonPhrase);

        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::REASON_PHRASES[$code] ?? '');
        return $new;
    }
}
