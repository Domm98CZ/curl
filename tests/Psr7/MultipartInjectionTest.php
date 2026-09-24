<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\MultipartStream;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MultipartInjectionTest extends TestCase
{
    public function testRejectsAnExtraPartSmuggledThroughThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultipartStream([
            [
                'name' => "avatar\"\r\n\r\ninjected\r\n--BOUNDARY\r\nContent-Disposition: form-data; name=\"role\"\r\n\r\nadmin",
                'contents' => 'x',
            ],
        ], 'BOUNDARY');
    }

    public function testRejectsCrlfInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([['name' => "a\r\nX-Injected: yes", 'contents' => 'x']], 'BOUNDARY');
    }

    public function testRejectsAQuoteInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([['name' => 'a"; filename="shell.php', 'contents' => 'x']], 'BOUNDARY');
    }

    public function testRejectsABackslashInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([['name' => 'a\\"b', 'contents' => 'x']], 'BOUNDARY');
    }

    public function testRejectsCrlfInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([
            ['name' => 'file', 'contents' => 'x', 'filename' => "a.txt\r\nX-Injected: yes"],
        ], 'BOUNDARY');
    }

    public function testRejectsAQuoteInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([
            ['name' => 'file', 'contents' => 'x', 'filename' => 'a"; name="role'],
        ], 'BOUNDARY');
    }

    public function testRejectsANulByteInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([
            ['name' => 'file', 'contents' => 'x', 'filename' => "a.php\0.jpg"],
        ], 'BOUNDARY');
    }

    public function testRejectsCrlfInACustomPartHeaderValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([
            ['name' => 'f', 'contents' => 'x', 'headers' => ['X-Test' => "a\r\nX-Injected: yes"]],
        ], 'BOUNDARY');
    }

    public function testRejectsAnInvalidCustomPartHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([
            ['name' => 'f', 'contents' => 'x', 'headers' => ["X-Test\r\nX-Injected" => 'yes']],
        ], 'BOUNDARY');
    }

    public function testRejectsABoundaryThatWouldEscapeTheEnvelope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([], "BOUND\r\nX-Injected: yes");
    }

    public function testStillBuildsALegitimatePart(): void
    {
        $stream = new MultipartStream([
            ['name' => 'file', 'contents' => 'data', 'filename' => 'photo (1).jpg'],
        ], 'BOUNDARY');

        self::assertStringContainsString(
            'Content-Disposition: form-data; name="file"; filename="photo (1).jpg"',
            (string) $stream
        );
    }
}
