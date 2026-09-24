<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Request implements RequestInterface
{
    use MessageTrait;

    private UriInterface $uri;
    private string $method;
    private ?string $requestTarget = null;

    /** @param array<string, string|string[]> $headers */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1'
    ) {
        HttpGrammar::assertMethod($method);

        $this->method = $method;
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->setHeaders($headers);
        $this->body = $body ?? new Stream(self::openTempStream());
        $this->protocolVersion = $protocolVersion;

        if (!$this->hasHeader('Host')) {
            $this->applyHostHeader($this->uri);
        }
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }
        return $target;
    }

    public function withRequestTarget($requestTarget): static
    {
        HttpGrammar::assertRequestTarget($requestTarget);

        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): static
    {
        HttpGrammar::assertMethod($method);

        $new = clone $this;
        $new->method = $method;
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        if ($preserveHost && $this->hasHeader('Host')) {
            return $new;
        }

        $new->applyHostHeader($uri);
        return $new;
    }

    // A UriInterface can come from any implementation, so the derived Host value passes the same
    // validation as every other header and is kept first in the header list, as PSR-7 requires.
    private function applyHostHeader(UriInterface $uri): void
    {
        $host = $uri->getHost();
        if ($host === '') {
            return;
        }

        $value = $uri->getPort() !== null ? $host . ':' . $uri->getPort() : $host;
        HttpGrammar::assertHeaderValue($value);
        HttpGrammar::assertHost($host);

        unset($this->headers['host'], $this->headerNames['host']);
        $this->headerNames = ['host' => 'Host'] + $this->headerNames;
        $this->headers = ['host' => [$value]] + $this->headers;
    }
}
