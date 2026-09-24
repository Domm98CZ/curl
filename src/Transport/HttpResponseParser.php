<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use Domm98CZ\Curl\Contract\ResponseParserInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpResponseParser implements ResponseParserInterface
{
    public function parse(RawResponse $rawResponse): ResponseInterface
    {
        return $this->buildResponse(
            $this->lastHeaderBlock($rawResponse->headerRaw),
            $rawResponse->body,
            $rawResponse->bodyBuffered
        );
    }

    /** @internal */
    public function parseHeaderBlock(string $headerBlock, ?StreamInterface $body = null): ResponseInterface
    {
        return $this->buildResponse(trim($headerBlock), $body ?? new Stream(self::openTempStream()), false);
    }

    private function buildResponse(string $headerBlock, StreamInterface $body, bool $bodyBuffered): ResponseInterface
    {
        $lines = $headerBlock === '' ? [] : explode("\r\n", $headerBlock);

        [$statusCode, $reasonPhrase, $protocolVersion] = $this->parseStatusLine((string) array_shift($lines));

        $response = new Response($statusCode, [], $body, $protocolVersion, $reasonPhrase);

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }
            $value = trim($parts[1] ?? '');
            // A remote server's raw header block is untrusted input, not an application request;
            // a non-conformant header name/value must not crash the client (PSR-18 contract).
            try {
                $response = $response->withAddedHeader($name, $value);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $this->normalizeDecodedBodyHeaders($response, $bodyBuffered);
    }

    // libcurl decodes the body transparently, so Content-Encoding/Content-Length still describe the
    // wire bytes and would contradict what getBody() now returns.
    private function normalizeDecodedBodyHeaders(ResponseInterface $response, bool $bodyBuffered): ResponseInterface
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        if ($encoding === '' || $encoding === 'identity') {
            return $response;
        }

        $response = $response->withoutHeader('Content-Encoding');

        $size = $bodyBuffered ? $response->getBody()->getSize() : null;
        if ($size === null) {
            return $response->withoutHeader('Content-Length');
        }

        return $response->withHeader('Content-Length', (string) $size);
    }

    private function lastHeaderBlock(string $headerRaw): string
    {
        $blocks = array_values(array_filter(
            explode("\r\n\r\n", $headerRaw),
            static fn (string $block): bool => trim($block) !== ''
        ));

        return $blocks === [] ? '' : trim(end($blocks));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function parseStatusLine(string $line): array
    {
        if (preg_match('#^HTTP/(\d+(?:\.\d+)?)\s+(\d{3})(?:\s+(.*))?$#', trim($line), $matches) !== 1) {
            throw new ClientException(sprintf(
                'Unparsable HTTP status line: "%s". The response did not come from an HTTP server.',
                $line
            ));
        }
        return [(int) $matches[2], $matches[3] ?? '', $matches[1]];
    }

    /** @return resource */
    private static function openTempStream()
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open "php://temp" stream.');
        }
        return $resource;
    }
}
