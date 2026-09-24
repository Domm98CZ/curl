<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A foreign PSR-7 implementation that performs no validation, used to prove the serialization
 * boundary defends itself instead of trusting whatever RequestInterface it is handed.
 */
final class UnvalidatedRequest implements RequestInterface
{
    /** @param array<string, string[]> $headers */
    public function __construct(
        private readonly RequestInterface $inner,
        private readonly ?string $method = null,
        private readonly ?array $headers = null,
        private readonly ?string $requestTarget = null,
    ) {
    }

    public function getMethod(): string
    {
        return $this->method ?? $this->inner->getMethod();
    }

    public function getHeaders(): array
    {
        return $this->headers ?? $this->inner->getHeaders();
    }

    public function getHeader($name): array
    {
        return $this->getHeaders()[$name] ?? $this->inner->getHeader($name);
    }

    public function hasHeader($name): bool
    {
        return $this->getHeader($name) !== [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function getRequestTarget(): string
    {
        return $this->requestTarget ?? $this->inner->getRequestTarget();
    }

    public function withRequestTarget($requestTarget): static
    {
        return $this;
    }

    public function withMethod($method): static
    {
        return $this;
    }

    public function getUri(): UriInterface
    {
        return $this->inner->getUri();
    }

    public function withUri(UriInterface $uri, $preserveHost = false): static
    {
        return $this;
    }

    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    public function withProtocolVersion($version): static
    {
        return $this;
    }

    public function withHeader($name, $value): static
    {
        return $this;
    }

    public function withAddedHeader($name, $value): static
    {
        return $this;
    }

    public function withoutHeader($name): static
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        return $this;
    }
}
