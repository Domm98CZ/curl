<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class StreamTest extends TestCase
{
    private function streamWith(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);
        return new Stream($resource);
    }

    public function testToStringReturnsFullContentsAndRewinds(): void
    {
        $stream = $this->streamWith('hello world');
        self::assertSame('hello world', (string) $stream);
        self::assertSame('hello world', (string) $stream);
    }


    public function testWriteToReadOnlyStreamThrowsWithoutEmittingNotice(): void
    {
        // php://temp opened read-only fails writes without any diagnostics of its own, so only a real
        // file proves the pre-check is what suppresses the notice
        $path = tempnam(sys_get_temp_dir(), 'curltest');
        self::assertIsString($path, 'tempnam() must return a valid path.');
        $resource = fopen($path, 'r');
        self::assertIsResource($resource, 'fopen() must return a valid resource.');
        $stream = new Stream($resource);

        try {
            $this->assertStreamOperationFailsWithoutDiagnostics(
                static fn (): int => $stream->write('content'),
                'Unable to write to stream.'
            );
        } finally {
            $stream->close();
            unlink($path);
        }
    }

    public function testReadFromWriteOnlyStreamThrowsWithoutEmittingNotice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'curltest');
        self::assertIsString($path, 'tempnam() must return a valid path.');
        $resource = fopen($path, 'w');
        self::assertIsResource($resource, 'fopen() must return a valid resource.');
        $stream = new Stream($resource);

        try {
            $this->assertStreamOperationFailsWithoutDiagnostics(
                static fn (): string => $stream->read(1),
                'Unable to read from stream.'
            );
        } finally {
            $stream->close();
            unlink($path);
        }
    }

    public function testGetContentsFromWriteOnlyStreamThrowsWithoutEmittingNotice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'curltest');
        self::assertIsString($path, 'tempnam() must return a valid path.');
        $resource = fopen($path, 'w');
        self::assertIsResource($resource, 'fopen() must return a valid resource.');
        $stream = new Stream($resource);

        try {
            $this->assertStreamOperationFailsWithoutDiagnostics(
                static fn (): string => $stream->getContents(),
                'Unable to read stream contents.'
            );
        } finally {
            $stream->close();
            unlink($path);
        }
    }

    public function testDetachLeavesStreamUnusable(): void
    {
        $stream = $this->streamWith('x');
        $resource = $stream->detach();
        self::assertIsResource($resource);
        self::assertNull($stream->getSize());
        self::assertSame('', (string) $stream);
    }

    public function testGetMetadata(): void
    {
        $stream = $this->streamWith('x');
        $all = $stream->getMetadata();
        self::assertIsArray($all);
        self::assertArrayHasKey('seekable', $all);
        self::assertTrue($stream->getMetadata('seekable'));
        self::assertNull($stream->getMetadata('does-not-exist'));
    }

    public function testEofWithNonSeekableResourcePipe(): void
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('cat', $spec, $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('proc_open not available');
        }
        try {
            $stream = new Stream($pipes[1]);
            self::assertFalse($stream->eof());
            $written = fwrite($pipes[0], 'AAAAABBBBB');
            self::assertGreaterThan(0, $written);
            fclose($pipes[0]);
            $content = '';
            while (!$stream->eof() && strlen($content) < 1000) {
                $chunk = $stream->read(2);
                $content .= $chunk;
            }
            self::assertSame('AAAAABBBBB', $content);
            self::assertTrue($stream->eof());
        } finally {
            proc_close($process);
        }
    }

    private function assertStreamOperationFailsWithoutDiagnostics(callable $operation, string $message): void
    {
        $diagnostics = [];
        $streamSourceFile = (new ReflectionClass(Stream::class))->getFileName();
        set_error_handler(static function (int $severity, string $error, string $file) use (&$diagnostics, $streamSourceFile): bool {
            if ($file === $streamSourceFile) {
                $diagnostics[] = $error;
            }
            return true;
        });
        $exception = null;
        try {
            try {
                $operation();
            } finally {
                restore_error_handler();
            }
        } catch (RuntimeException $runtimeException) {
            $exception = $runtimeException;
        }
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame($message, $exception->getMessage());
        self::assertSame([], $diagnostics);
    }
}
