<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use Psr\Http\Message\StreamInterface;

final class MultipartStream implements StreamInterface
{
    private string $boundary;
    private Stream $body;

    /** @param array<int, array{name: string, contents: StreamInterface|string, filename?: ?string, headers?: array<string, string>}> $parts */
    public function __construct(array $parts, ?string $boundary = null)
    {
        $this->boundary = $boundary ?? bin2hex(random_bytes(16));
        HttpGrammar::assertMultipartBoundary($this->boundary);

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open "php://temp" stream.');
        }
        foreach ($parts as $part) {
            fwrite($resource, $this->buildPart($part));
        }
        fwrite($resource, sprintf("--%s--\r\n", $this->boundary));
        rewind($resource);

        $this->body = new Stream($resource);
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    public function getContentType(): string
    {
        return sprintf('multipart/form-data; boundary=%s', $this->boundary);
    }

    /** @param array{name: string, contents: StreamInterface|string, filename?: ?string, headers?: array<string, string>} $part */
    private function buildPart(array $part): string
    {
        $filename = $part['filename'] ?? null;
        $headers = $part['headers'] ?? [];

        HttpGrammar::assertQuotedParameter($part['name'], 'multipart part name');

        $disposition = sprintf('form-data; name="%s"', $part['name']);
        if ($filename !== null) {
            HttpGrammar::assertQuotedParameter($filename, 'multipart filename');
            $disposition .= sprintf('; filename="%s"', $filename);
            if (!$this->hasHeaderCaseInsensitive($headers, 'Content-Type')) {
                $headers['Content-Type'] = 'application/octet-stream';
            }
        }

        $chunk = sprintf("--%s\r\n", $this->boundary);
        $chunk .= sprintf("Content-Disposition: %s\r\n", $disposition);
        foreach ($headers as $name => $value) {
            HttpGrammar::assertHeaderName($name);
            HttpGrammar::assertHeaderValue($value);
            $chunk .= sprintf("%s: %s\r\n", $name, $value);
        }
        $chunk .= "\r\n";
        $chunk .= $this->contentsOf($part['contents']);
        $chunk .= "\r\n";

        return $chunk;
    }

    // Read explicitly rather than through (string) casting: StreamInterface::__toString() must not
    // throw, so a stream that cannot be read would silently become an empty part.
    private function contentsOf(StreamInterface|string $contents): string
    {
        if (is_string($contents)) {
            return $contents;
        }
        if ($contents->isSeekable()) {
            $contents->rewind();
        }

        return $contents->getContents();
    }

    /** @param array<string, string> $headers */
    private function hasHeaderCaseInsensitive(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    public function __toString(): string
    {
        return (string) $this->body;
    }

    public function close(): void
    {
        $this->body->close();
    }

    public function detach()
    {
        return $this->body->detach();
    }

    public function getSize(): ?int
    {
        return $this->body->getSize();
    }

    public function tell(): int
    {
        return $this->body->tell();
    }

    public function eof(): bool
    {
        return $this->body->eof();
    }

    public function isSeekable(): bool
    {
        return $this->body->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->body->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->body->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('MultipartStream is immutable once built.');
    }

    public function isReadable(): bool
    {
        return $this->body->isReadable();
    }

    public function read($length): string
    {
        return $this->body->read($length);
    }

    public function getContents(): string
    {
        return $this->body->getContents();
    }

    public function getMetadata($key = null)
    {
        return $this->body->getMetadata($key);
    }
}
