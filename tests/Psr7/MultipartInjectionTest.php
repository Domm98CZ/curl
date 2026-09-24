<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\MultipartPart;
use Domm98CZ\Curl\Psr7\MultipartStream;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MultipartInjectionTest extends TestCase
{
    public function testRejectsAnExtraPartSmuggledThroughThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultipartStream([
            MultipartPart::field(
                "avatar\"

injected
--BOUNDARY
Content-Disposition: form-data; name=\"role\"

admin",
                'x'
            ),
        ], 'BOUNDARY');
    }

    public function testRejectsCrlfInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::field("a\r\nX-Injected: yes", 'x')], 'BOUNDARY');
    }

    public function testRejectsAQuoteInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::field('a"; filename="shell.php', 'x')], 'BOUNDARY');
    }

    public function testRejectsABackslashInThePartName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::field('a\\"b', 'x')], 'BOUNDARY');
    }

    public function testRejectsCrlfInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::file('file', 'x', "a.txt\r\nX-Injected: yes")], 'BOUNDARY');
    }

    public function testRejectsAQuoteInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::file('file', 'x', 'a"; name="role')], 'BOUNDARY');
    }

    public function testRejectsANulByteInTheFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::file('file', 'x', "a.php\0.jpg")], 'BOUNDARY');
    }

    public function testRejectsCrlfInACustomPartHeaderValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::field('f', 'x')->withHeader("X-Test", "a\r\nX-Injected: yes")], 'BOUNDARY');
    }

    public function testRejectsAnInvalidCustomPartHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([MultipartPart::field('f', 'x')->withHeader("X-Test\r\nX-Injected", 'yes')], 'BOUNDARY');
    }

    public function testRejectsABoundaryThatWouldEscapeTheEnvelope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartStream([], "BOUND\r\nX-Injected: yes");
    }

    public function testStillBuildsALegitimatePart(): void
    {
        $stream = new MultipartStream([MultipartPart::file('file', 'data', 'photo (1).jpg')], 'BOUNDARY');

        self::assertStringContainsString(
            'Content-Disposition: form-data; name="file"; filename="photo (1).jpg"',
            (string) $stream
        );
    }
}
