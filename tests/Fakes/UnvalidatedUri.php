<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Psr\Http\Message\UriInterface;

/** A foreign PSR-7 URI that performs no host validation, used to prove Request validates what it derives. */
final class UnvalidatedUri implements UriInterface
{
    public function __construct(
        private readonly string $host,
        private readonly string $scheme = 'https',
        private readonly string $path = '/',
    ) {
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        return $this->host;
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme($scheme): static
    {
        return $this;
    }

    public function withUserInfo($user, $password = null): static
    {
        return $this;
    }

    public function withHost($host): static
    {
        return $this;
    }

    public function withPort($port): static
    {
        return $this;
    }

    public function withPath($path): static
    {
        return $this;
    }

    public function withQuery($query): static
    {
        return $this;
    }

    public function withFragment($fragment): static
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->scheme . '://' . $this->host . '/';
    }
}
