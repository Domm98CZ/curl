<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\Uri;
use PHPUnit\Framework\TestCase;

final class UriTest extends TestCase
{
    public function testWithUserInfo(): void
    {
        $uri = (new Uri('https://example.com'))->withUserInfo('user', 'pass');
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('user:pass@example.com', $uri->getAuthority());

        $noPassword = (new Uri('https://example.com'))->withUserInfo('user');
        self::assertSame('user', $noPassword->getUserInfo());
    }

    public function testWithUserInfoCannotChangeTheHostThroughAPathDelimiter(): void
    {
        $uri = (new Uri('https://example.com/path'))->withUserInfo('evil.example.com/');

        self::assertSame('example.com', $uri->getHost());
        self::assertSame('https://evil.example.com%2F@example.com/path', (string) $uri);
        self::assertStringNotContainsString('evil.example.com/@', (string) $uri);
    }

    public function testWithUserInfoRejectsCrlfInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Uri('https://example.com'))->withUserInfo("a\r\nHost: x");
    }

    public function testWithUserInfoEncodesUserAndPasswordSeparately(): void
    {
        $uri = (new Uri('https://example.com'))->withUserInfo('u', 'p:w');

        self::assertSame('u:p:w', $uri->getUserInfo());
        self::assertSame('https://u:p:w@example.com', (string) $uri);
        self::assertSame('u:p:w', (new Uri((string) $uri))->getUserInfo());
    }

    public function testConstructorFiltersRawUserInfo(): void
    {
        $uri = new Uri('https://a b:p w@example.com/path');

        self::assertSame('a%20b:p%20w', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame('https://a%20b:p%20w@example.com/path', (string) $uri);
    }

    public function testWithPortRejectsOutOfRangeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Uri('https://example.com'))->withPort(70000);
    }

    public function testGetPathNormalizesMultipleLeadingSlashesToSingleSlashToPreventXSS(): void
    {
        $uri = new Uri('http://example.org//valid///path');

        self::assertSame('/valid///path', $uri->getPath());
        self::assertSame('http://example.org//valid///path', (string) $uri);
        self::assertNotSame('http://example.org' . $uri->getPath(), (string) $uri);
        self::assertSame('/valid///path', (new Uri())->withPath('//valid///path')->getPath());
        self::assertSame('/valid///path', (string) (new Uri())->withPath('//valid///path'));
    }

    public function testToStringRoundtrips(): void
    {
        $raw = 'https://user@example.com:8080/a/b?x=1&y=2#frag';
        self::assertSame($raw, (string) new Uri($raw));
    }

    public function testRelativeUriWithoutScheme(): void
    {
        $uri = new Uri('/just/a/path?x=1');
        self::assertSame('', $uri->getScheme());
        self::assertSame('', $uri->getAuthority());
        self::assertSame('/just/a/path?x=1', (string) $uri);
    }

    public function testWithSchemePreservesPortThatIsNonDefaultUnderTheNewScheme(): void
    {
        $uri = (new Uri('https://example.com:443/path'))->withScheme('http');
        self::assertSame(443, $uri->getPort());
    }

    public function testToStringOmitsAuthoritySlashesForSchemedHostlessUri(): void
    {
        $uri = (new Uri())->withScheme('mailto')->withPath('foo@example.com');
        self::assertSame('mailto:foo@example.com', (string) $uri);
    }
}
