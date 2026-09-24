<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ftp' => 21, 'ftps' => 990];

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException(sprintf('Unable to parse URI: %s', $uri));
        }

        $this->scheme = $this->filterScheme($parts['scheme'] ?? '');
        $this->host = $this->filterHost($parts['host'] ?? '');
        $this->port = $this->filterPort($parts['port'] ?? null);
        $this->path = $this->encodePath($parts['path'] ?? '');
        $this->query = $this->encodeQueryOrFragment($parts['query'] ?? '');
        $this->fragment = $this->encodeQueryOrFragment($parts['fragment'] ?? '');

        if (array_key_exists('user', $parts)) {
            $user = HttpGrammar::encodeUserInfo($parts['user']);
            $this->userInfo = $user . (array_key_exists('pass', $parts) ? ':' . HttpGrammar::encodeUserInfo($parts['pass']) : '');
        }
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ':' . $port;
        }
        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        if ($this->port === null) {
            return null;
        }
        if ((self::DEFAULT_PORTS[$this->scheme] ?? null) === $this->port) {
            return null;
        }
        return $this->port;
    }

    public function getPath(): string
    {
        // PSR-7 normalizes the accessor but preserves the stored slash run when serializing with authority.
        if (!str_starts_with($this->path, '//')) {
            return $this->path;
        }
        return '/' . ltrim($this->path, '/');
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme($scheme): UriInterface
    {
        $new = clone $this;
        $new->scheme = $new->filterScheme($scheme);
        return $new;
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        $new = clone $this;
        $encodedUser = HttpGrammar::encodeUserInfo($user);
        $new->userInfo = $encodedUser === ''
            ? ''
            : $encodedUser . ($password !== null ? ':' . HttpGrammar::encodeUserInfo($password) : '');
        return $new;
    }

    public function withHost($host): UriInterface
    {
        $new = clone $this;
        $new->host = $new->filterHost($host);
        return $new;
    }

    public function withPort($port): UriInterface
    {
        $new = clone $this;
        $new->port = $new->filterPort($port);
        return $new;
    }

    public function withPath($path): UriInterface
    {
        $new = clone $this;
        $new->path = $new->encodePath($path);
        return $new;
    }

    public function withQuery($query): UriInterface
    {
        $new = clone $this;
        $new->query = $new->encodeQueryOrFragment($query);
        return $new;
    }

    public function withFragment($fragment): UriInterface
    {
        $new = clone $this;
        $new->fragment = $new->encodeQueryOrFragment($fragment);
        return $new;
    }

    public function __toString(): string
    {
        $result = '';
        if ($this->scheme !== '') {
            $result .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '' || $this->scheme === 'file') {
            $result .= '//' . $authority;
        }
        $result .= $this->normalizedPathFor($authority);
        if ($this->query !== '') {
            $result .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $result .= '#' . $this->fragment;
        }
        return $result;
    }

    private function normalizedPathFor(string $authority): string
    {
        $path = $this->path;
        // Keep authority paths absolute and prevent authority-less paths from being interpreted as having an authority.
        if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
            return '/' . $path;
        }
        if ($authority === '' && str_starts_with($path, '//')) {
            return '/' . ltrim($path, '/');
        }
        return $path;
    }

    private function filterHost(string $host): string
    {
        $lowercased = strtolower($host);
        HttpGrammar::assertHost($lowercased);
        return $lowercased;
    }

    private function filterScheme(string $scheme): string
    {
        $lowercased = strtolower($scheme);
        HttpGrammar::assertScheme($lowercased);
        return $lowercased;
    }

    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('Invalid port: %d. Must be between 1 and 65535.', $port));
        }
        return $port;
    }

    private function encodePath(string $path): string
    {
        $encoded = preg_replace_callback(
            '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/%]+/',
            static fn (array $m): string => rawurlencode($m[0]),
            $path
        );
        if ($encoded === null) {
            throw new InvalidArgumentException(sprintf('Unable to encode path: %s', $path));
        }
        return $encoded;
    }

    private function encodeQueryOrFragment(string $value): string
    {
        $encoded = preg_replace_callback(
            '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/?%]+/',
            static fn (array $m): string => rawurlencode($m[0]),
            $value
        );
        if ($encoded === null) {
            throw new InvalidArgumentException(sprintf('Unable to encode value: %s', $value));
        }
        return $encoded;
    }
}
