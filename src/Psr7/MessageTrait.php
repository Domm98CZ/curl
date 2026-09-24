<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

trait MessageTrait
{
    private string $protocolVersion = '1.1';

    /** @var array<string, string[]> lowercase header name => values */
    private array $headers = [];

    /** @var array<string, string> lowercase header name => original case */
    private array $headerNames = [];

    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): static
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $lower => $values) {
            $result[$this->headerNames[$lower]] = $values;
        }
        return $result;
    }

    public function hasHeader($name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): static
    {
        $this->assertValidHeaderName($name);
        $values = is_array($value) ? array_values($value) : [$value];
        $this->assertValidHeaderValues($values);

        $new = clone $this;
        $lower = strtolower($name);
        if ($this->representsEmptyHeader($values)) {
            unset($new->headers[$lower], $new->headerNames[$lower]);
        } else {
            $new->headerNames[$lower] = $name;
            $new->headers[$lower] = $values;
        }
        return $new;
    }

    public function withAddedHeader($name, $value): static
    {
        $this->assertValidHeaderName($name);
        $values = is_array($value) ? array_values($value) : [$value];
        $this->assertValidHeaderValues($values);

        $new = clone $this;
        $lower = strtolower($name);
        if ($this->representsEmptyHeader($values)) {
            return $new;
        }
        if (array_key_exists($lower, $new->headers)) {
            $new->headers[$lower] = array_merge($new->headers[$lower], $values);
        } else {
            $new->headerNames[$lower] = $name;
            $new->headers[$lower] = $values;
        }
        return $new;
    }

    public function withoutHeader($name): static
    {
        $new = clone $this;
        $lower = strtolower($name);
        unset($new->headers[$lower], $new->headerNames[$lower]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /** @param array<string, string|string[]> $headers */
    private function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $this->assertValidHeaderName($name);
            $values = is_array($value) ? array_values($value) : [$value];
            $this->assertValidHeaderValues($values);

            $lower = strtolower($name);
            if ($this->representsEmptyHeader($values)) {
                unset($this->headers[$lower], $this->headerNames[$lower]);
                continue;
            }
            $this->headerNames[$lower] = $name;
            $this->headers[$lower] = $values;
        }
    }

    private function assertValidHeaderName(string $name): void
    {
        HttpGrammar::assertHeaderName($name);
    }

    /** @param mixed[] $values */
    private function representsEmptyHeader(array $values): bool
    {
        return $values === [];
    }

    /** @param mixed[] $values */
    private function assertValidHeaderValues(array $values): void
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Header values must be strings.');
            }
            HttpGrammar::assertHeaderValue($value);
        }
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
