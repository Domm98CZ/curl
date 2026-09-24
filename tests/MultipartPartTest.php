<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\MultipartPart;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MultipartPartTest extends TestCase
{
    public function testFieldHasNoFilenameOrHeaders(): void
    {
        $part = MultipartPart::field('title', 'Holiday');

        self::assertSame('title', $part->name());
        self::assertSame('Holiday', $part->contents());
        self::assertNull($part->filename());
        self::assertSame([], $part->headers());
    }

    public function testFileDefaultsToOctetStream(): void
    {
        $part = MultipartPart::file('file', 'data', 'a.bin');

        self::assertSame('a.bin', $part->filename());
        self::assertSame(['Content-Type' => 'application/octet-stream'], $part->headers());
    }

    public function testWithContentTypeReplacesTheDefaultCaseInsensitively(): void
    {
        $part = MultipartPart::file('file', 'data', 'a.jpg')
            ->withHeader('content-type', 'image/png')
            ->withContentType('image/jpeg');

        self::assertSame(['Content-Type' => 'image/jpeg'], $part->headers());
    }

    public function testIsImmutable(): void
    {
        $part = MultipartPart::file('file', 'data', 'a.jpg');
        $part->withContentType('image/jpeg');

        self::assertSame('application/octet-stream', $part->headers()['Content-Type']);
    }

    public function testRejectsAnInvalidNameAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MultipartPart::field("a\r\nb", 'x');
    }

    public function testRejectsAnInvalidFilenameAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MultipartPart::file('f', 'x', 'a".php');
    }

    public function testRejectsAnInvalidHeaderValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MultipartPart::field('f', 'x')->withHeader('X-Test', "a\r\nb");
    }
}
