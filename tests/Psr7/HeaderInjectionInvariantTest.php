<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Uri;
use Domm98CZ\Curl\Tests\Fakes\UnvalidatedUri;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HeaderInjectionInvariantTest extends TestCase
{
    public function testUriRejectsCrlfInTheHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Uri('https://example.com'))->withHost("example.com\r\nX-Injected: yes");
    }

    public function testUriRejectsASpaceInTheHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Uri('https://example.com'))->withHost('exa mple.com');
    }

    public function testUriAcceptsAnIpv6Literal(): void
    {
        self::assertSame('[::1]', (new Uri('https://example.com'))->withHost('[::1]')->getHost());
    }

    public function testRequestRejectsAHostHeaderSmuggledThroughAForeignUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', new UnvalidatedUri("example.com\r\nX-Injected: yes"));
    }

    public function testWithUriRejectsAHostHeaderSmuggledThroughAForeignUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Request('GET', 'https://example.com'))->withUri(new UnvalidatedUri("evil\r\nX-Injected: yes"));
    }

    public function testEveryHeaderOnARequestIsFreeOfCrlfIncludingHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request('GET', 'https://example.com:8443/a', [
            'Host' => ['example.com:8443', "evil.example\0"],
        ]);
    }

    public function testHostHeaderComesFirst(): void
    {
        $request = new Request('GET', 'https://example.com/a', ['X-Test' => 'a', 'Accept' => 'application/json']);

        self::assertSame('Host', array_key_first($request->getHeaders()));
    }

    public function testWithUriKeepsHostFirstAfterARewrite(): void
    {
        $request = (new Request('GET', 'https://old.example.com', ['X-Test' => 'a']))
            ->withUri(new Uri('https://new.example.com'));

        self::assertSame('Host', array_key_first($request->getHeaders()));
        self::assertSame('new.example.com', $request->getHeaderLine('Host'));
    }

    public function testRequestRejectsANonTokenMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request("GET / HTTP/1.1\r\nHost: victim\r\n\r\nDELETE /admin", 'https://example.com');
    }

    public function testRequestRejectsAMethodWithASpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET /admin', 'https://example.com');
    }

    public function testWithMethodRejectsANonTokenMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Request('GET', 'https://example.com'))->withMethod("POST\r\nX: y");
    }

    public function testUriDoesNotFuseARootlessPathIntoTheHost(): void
    {
        $uri = (new Uri('https://example.com'))->withPath('foo');
        self::assertSame('https://example.com/foo', (string) $uri);
    }

    public function testUriDoesNotTurnAPathIntoAnAuthority(): void
    {
        $uri = (new Uri(''))->withPath('//evil.com/x');
        self::assertSame('/evil.com/x', (string) $uri);
    }
}
