<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\MultipartStream;
use Domm98CZ\Curl\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class MultipartStreamTest extends TestCase
{
    public function testGetContentTypeIncludesBoundary(): void
    {
        $stream = new MultipartStream([], 'FIXEDBOUNDARY');
        self::assertSame('multipart/form-data; boundary=FIXEDBOUNDARY', $stream->getContentType());
        self::assertSame('FIXEDBOUNDARY', $stream->getBoundary());
    }

    public function testGeneratesRandomBoundaryWhenNotGiven(): void
    {
        $a = new MultipartStream([]);
        $b = new MultipartStream([]);
        self::assertNotSame($a->getBoundary(), $b->getBoundary());
    }

    public function testBuildsSimpleFieldPart(): void
    {
        $stream = new MultipartStream([
            ['name' => 'field1', 'contents' => 'value1'],
        ], 'BOUNDARY');

        $expected = "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"field1\"\r\n"
            . "\r\n"
            . "value1\r\n"
            . "--BOUNDARY--\r\n";

        self::assertSame($expected, (string) $stream);
    }

    public function testBuildsFilePartWithDefaultContentType(): void
    {
        $stream = new MultipartStream([
            ['name' => 'file', 'contents' => 'binarydata', 'filename' => 'a.bin'],
        ], 'BOUNDARY');

        $body = (string) $stream;
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="a.bin"', $body);
        self::assertStringContainsString('Content-Type: application/octet-stream', $body);
        self::assertStringContainsString('binarydata', $body);
    }

    public function testBuildsFilePartWithCustomHeaders(): void
    {
        $stream = new MultipartStream([
            [
                'name' => 'file',
                'contents' => '{}',
                'filename' => 'a.json',
                'headers' => ['Content-Type' => 'application/json'],
            ],
        ], 'BOUNDARY');

        self::assertStringContainsString('Content-Type: application/json', (string) $stream);
    }

    public function testAcceptsStreamInterfaceContents(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'from-stream');
        rewind($resource);

        $stream = new MultipartStream([
            ['name' => 'field1', 'contents' => new Stream($resource)],
        ], 'BOUNDARY');

        self::assertStringContainsString('from-stream', (string) $stream);
    }

    public function testReadsANonSeekableStreamFromItsCurrentPosition(): void
    {
        $contents = $this->createStub(StreamInterface::class);
        $contents->method('isSeekable')->willReturn(false);
        $contents->method('getContents')->willReturn('piped');

        $stream = new MultipartStream([
            ['name' => 'field1', 'contents' => $contents],
        ], 'BOUNDARY');

        self::assertStringContainsString("\r\n\r\npiped\r\n", (string) $stream);
    }

    // (string) $stream must not throw, so it would have produced an empty part instead of failing.
    public function testAStreamThatCannotBeReadFailsInsteadOfProducingAnEmptyPart(): void
    {
        $contents = $this->createStub(StreamInterface::class);
        $contents->method('isSeekable')->willReturn(false);
        $contents->method('getContents')->willThrowException(new \RuntimeException('read failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read failed');
        new MultipartStream([['name' => 'field1', 'contents' => $contents]], 'BOUNDARY');
    }

    public function testAClosedStreamFailsInsteadOfProducingAnEmptyPart(): void
    {
        $contents = new Stream(fopen('php://temp', 'r+'));
        $contents->close();

        $this->expectException(\RuntimeException::class);
        new MultipartStream([['name' => 'field1', 'contents' => $contents]], 'BOUNDARY');
    }

    public function testMultiplePartsAreJoinedWithBoundaries(): void
    {
        $stream = new MultipartStream([
            ['name' => 'a', 'contents' => '1'],
            ['name' => 'b', 'contents' => '2'],
        ], 'BOUNDARY');

        $body = (string) $stream;
        self::assertSame(2, substr_count($body, '--BOUNDARY' . "\r\n"));
        self::assertStringContainsString('--BOUNDARY--', $body);
    }

    public function testGetSizeIsKnownUpfront(): void
    {
        $stream = new MultipartStream([
            ['name' => 'a', 'contents' => '1'],
        ], 'BOUNDARY');

        // Both getSize() and __toString() read the same underlying stream, so comparing them to each
        // other passes for any envelope; the byte count has to come from outside the object.
        $expected = "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n"
            . "\r\n"
            . "1\r\n"
            . "--BOUNDARY--\r\n";

        self::assertSame($expected, (string) $stream);
        self::assertSame(73, strlen($expected));
        self::assertSame(73, $stream->getSize());
    }
}
