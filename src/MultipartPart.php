<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Psr7\HttpGrammar;
use Psr\Http\Message\StreamInterface;

final class MultipartPart
{
    /** @param array<string, string> $headers */
    private function __construct(
        private readonly string $name,
        private readonly StreamInterface|string $contents,
        private readonly ?string $filename,
        private readonly array $headers,
    ) {
        HttpGrammar::assertQuotedParameter($name, 'multipart part name');
        if ($filename !== null) {
            HttpGrammar::assertQuotedParameter($filename, 'multipart filename');
        }
    }

    public static function field(string $name, StreamInterface|string $contents): self
    {
        return new self($name, $contents, null, []);
    }

    public static function file(string $name, StreamInterface|string $contents, string $filename): self
    {
        return new self($name, $contents, $filename, ['Content-Type' => 'application/octet-stream']);
    }

    public function withContentType(string $mediaType): self
    {
        return $this->withHeader('Content-Type', $mediaType);
    }

    public function withHeader(string $name, string $value): self
    {
        HttpGrammar::assertHeaderName($name);
        HttpGrammar::assertHeaderValue($value);
        $headers = array_filter(
            $this->headers,
            static fn (string $existing): bool => strcasecmp($existing, $name) !== 0,
            ARRAY_FILTER_USE_KEY
        );
        $headers[$name] = $value;
        return new self($this->name, $this->contents, $this->filename, $headers);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function contents(): StreamInterface|string
    {
        return $this->contents;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
