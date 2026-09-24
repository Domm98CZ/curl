<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testConstructorSetsMethodUriHeadersBody(): void
    {
        $request = new Request('GET', 'https://example.com/path', ['X-Test' => 'a']);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://example.com/path', (string) $request->getUri());
        self::assertSame(['a'], $request->getHeader('X-Test'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);

        self::assertTrue($request->hasHeader('content-type'));
        self::assertSame('application/json', $request->getHeaderLine('CONTENT-TYPE'));
    }

    public function testWithHeaderWithEmptyArrayRemovesHeader(): void
    {
        $original = new Request('GET', '/', ['X-Foo' => 'bar', 'X-Other' => 'unchanged']);

        $withoutHeader = $original->withHeader('X-Foo', []);

        self::assertNotSame($original, $withoutHeader);
        self::assertTrue($original->hasHeader('X-Foo'));
        self::assertFalse($withoutHeader->hasHeader('X-Foo'));
        self::assertArrayNotHasKey('X-Foo', $withoutHeader->getHeaders());
        self::assertSame(['unchanged'], $withoutHeader->getHeader('X-Other'));

        $withoutMissingHeader = $withoutHeader->withHeader('X-Missing', []);
        self::assertNotSame($withoutHeader, $withoutMissingHeader);
        self::assertSame($withoutHeader->getHeaders(), $withoutMissingHeader->getHeaders());
    }

    public function testWithAddedHeaderWithEmptyArrayPreservesExistingHeaders(): void
    {
        $original = new Request('GET', '/', ['X-Foo' => ['first', 'second'], 'X-Other' => 'unchanged']);
        $headers = $original->getHeaders();

        $unchanged = $original->withAddedHeader('X-Foo', []);

        self::assertNotSame($original, $unchanged);
        self::assertSame($headers, $unchanged->getHeaders());
    }

    public function testWithAddedHeaderWithEmptyArrayDoesNotCreateHeader(): void
    {
        $original = new Request('GET', '/', ['X-Other' => 'unchanged']);
        $headers = $original->getHeaders();

        $unchanged = $original->withAddedHeader('X-Foo', []);

        self::assertNotSame($original, $unchanged);
        self::assertFalse($unchanged->hasHeader('X-Foo'));
        self::assertArrayNotHasKey('X-Foo', $unchanged->getHeaders());
        self::assertSame($headers, $unchanged->getHeaders());
    }

    public function testConstructorOmitsHeaderWithEmptyArray(): void
    {
        $request = new Request('GET', '/', ['X-Foo' => []]);

        self::assertFalse($request->hasHeader('X-Foo'));
        self::assertArrayNotHasKey('X-Foo', $request->getHeaders());
    }

    public function testWithHeaderWithEmptyArrayStillRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X Foo', []);
    }

    public function testWithAddedHeaderWithEmptyArrayStillRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withAddedHeader('X Foo', []);
    }

    public function testWithoutHeaderRemovesIt(): void
    {
        $request = (new Request('GET', '/', ['X-Test' => 'a']))->withoutHeader('X-Test');
        self::assertFalse($request->hasHeader('X-Test'));
    }

    public function testGetRequestTargetDefaultsToPathAndQuery(): void
    {
        $request = new Request('GET', 'https://example.com/a/b?x=1');
        self::assertSame('/a/b?x=1', $request->getRequestTarget());
    }

    public function testGetRequestTargetInOriginFormNormalizesUriWithMultipleLeadingSlashesInPath(): void
    {
        $request = new Request('GET', 'http://example.org//valid///path');

        self::assertSame('/valid///path', $request->getRequestTarget());
    }

    public function testGetRequestTargetDefaultsToSlashForEmptyPath(): void
    {
        $request = new Request('GET', 'https://example.com');
        self::assertSame('/', $request->getRequestTarget());
    }

    #[DataProvider('invalidRequestTargetProvider')]
    public function testWithRequestTargetRejectsInvalidValues(string $requestTarget): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withRequestTarget($requestTarget);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequestTargetProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['/a b'];
        yield 'tab' => ["/a\tb"];
        yield 'carriage return' => ["/a\rb"];
        yield 'line feed' => ["/a\nb"];
        yield 'NUL' => ["/a\0b"];
        yield 'unit separator' => ["/a\x1Fb"];
        yield 'DEL' => ["/a\x7Fb"];
        yield 'high byte' => ["/a\x80b"];
        yield 'overlong UTF-8 CR' => ["/ok\xC0\x8D"];
        yield 'overlong UTF-8 LF' => ["/ok\xC0\x8A"];
        yield 'trailing newline' => ["/ok\n"];
    }

    public function testWithBody(): void
    {
        $body = new Stream(fopen('php://temp', 'r+'));
        $request = (new Request('POST', '/'))->withBody($body);
        self::assertSame($body, $request->getBody());
    }

    public function testProtocolVersion(): void
    {
        $request = new Request('GET', '/');
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('2', $request->withProtocolVersion('2')->getProtocolVersion());
    }

    public function testWithHeaderRejectsCrlfInjectionInValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X-Test', "x\r\n\r\nGET /admin HTTP/1.1\r\nHost: victim\r\n");
    }

    public function testWithHeaderRejectsBareCarriageReturnInValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X-Test', "value\rwith-cr");
    }

    public function testWithHeaderRejectsBareLineFeedInValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X-Test', "value\nwith-lf");
    }

    public function testWithHeaderRejectsInvalidHeaderNameWithSpace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X Test', 'value');
    }

    public function testWithHeaderRejectsInvalidHeaderNameWithColon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader('X-Test:', 'value');
    }

    public function testWithHeaderRejectsHeaderNameWithTrailingLineFeed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withHeader("X-Test\n", 'value');
    }

    public function testWithAddedHeaderRejectsCrlfInjectionInValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('GET', '/'))->withAddedHeader('X-Test', "x\r\nHost: victim");
    }

    #[DataProvider('nonStringHeaderValueProvider')]
    public function testWithHeaderRejectsNonStringValues(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Request('GET', '/'))->withHeader('X-Test', $value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringHeaderValueProvider(): iterable
    {
        yield 'integer' => [1];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
        yield 'nested array' => [[[]]];
        yield 'object' => [new \stdClass()];
    }

    public function testWithAddedHeaderRejectsANonStringValueWithInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Request('GET', '/'))->withAddedHeader('X-Test', 1);
    }

    public function testConstructorRejectsANonStringHeaderValueWithInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Request('GET', '/', ['X-Test' => 1]);
    }

    public function testConstructorRejectsMaliciousHeaderViaSetHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Request('GET', '/', ['X-Test' => "x\r\n\r\nGET /admin HTTP/1.1\r\nHost: victim\r\n"]);
    }

    public function testHostHeaderIncludesNonDefaultPort(): void
    {
        $request = new Request('GET', 'https://example.com:8443/path');
        self::assertSame('example.com:8443', $request->getHeaderLine('Host'));
    }

    public function testHostHeaderOmitsDefaultPort(): void
    {
        $request = new Request('GET', 'https://example.com/path');
        self::assertSame('example.com', $request->getHeaderLine('Host'));
    }

    public function testWithUriHostHeaderIncludesNonDefaultPort(): void
    {
        $request = (new Request('GET', 'https://example.com'))
            ->withUri(new Uri('https://example.com:8443/path'));

        self::assertSame('example.com:8443', $request->getHeaderLine('Host'));
    }
}
