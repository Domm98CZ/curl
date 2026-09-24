<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Streaming;

use Domm98CZ\Curl\Contract\RetryAwareInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Domm98CZ\Curl\Streaming\FileSink;
use Domm98CZ\Curl\Tests\Fakes\ShortWriteStreamWrapper;
use PHPUnit\Framework\TestCase;

final class FileSinkTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/curl-file-sink-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        ShortWriteStreamWrapper::unregister();
    }

    /** @return list<string> */
    private function partFiles(): array
    {
        return glob($this->directory . '/*.part') ?: [];
    }

    public function testIsAStreamHandlerThatCanBeReset(): void
    {
        $sink = new FileSink($this->directory . '/target');

        self::assertInstanceOf(StreamHandlerInterface::class, $sink);
        self::assertInstanceOf(RetryAwareInterface::class, $sink);
        $sink->discard();
    }

    public function testTheTargetAppearsOnlyOnCommit(): void
    {
        $path = $this->directory . '/target.bin';
        $sink = new FileSink($path);
        $sink->onHeaderLine("HTTP/1.1 200 OK\r\n");
        $sink->onChunk('first ');
        $sink->onChunk('second');

        self::assertFileDoesNotExist($path);
        self::assertCount(1, $this->partFiles());

        $sink->commit();

        self::assertSame('first second', file_get_contents($path));
        self::assertSame([], $this->partFiles());
    }

    public function testCommitReplacesAnExistingTarget(): void
    {
        $path = $this->directory . '/target.bin';
        file_put_contents($path, 'stale');
        $sink = new FileSink($path);
        $sink->onChunk('fresh');

        $sink->commit();

        self::assertSame('fresh', file_get_contents($path));
    }

    public function testDiscardRemovesThePartFileAndLeavesTheTargetAlone(): void
    {
        $path = $this->directory . '/target.bin';
        file_put_contents($path, 'previous download');
        $sink = new FileSink($path);
        $sink->onChunk('half of a new');

        $sink->discard();

        self::assertSame([], $this->partFiles());
        self::assertSame('previous download', file_get_contents($path));
    }

    public function testResetForRetryStartsTheFileOver(): void
    {
        $path = $this->directory . '/target.bin';
        $sink = new FileSink($path);
        $sink->onChunk('bytes of a failed first attempt');

        self::assertTrue($sink->resetForRetry());
        $sink->onChunk('second attempt');
        $sink->commit();

        self::assertSame('second attempt', file_get_contents($path));
    }

    public function testRefusesAMissingDirectoryBeforeAnythingIsSent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('part file');
        new FileSink($this->directory . '/missing/target.bin');
    }

    public function testAShortWriteIsALocalBufferFailure(): void
    {
        ShortWriteStreamWrapper::register(withheld: 1);
        $path = $this->directory . '/target.bin';
        $sink = new FileSink($path);
        $handle = new \ReflectionProperty($sink, 'handle');
        fclose($handle->getValue($sink));
        $handle->setValue($sink, fopen(ShortWriteStreamWrapper::SCHEME . '://target', 'w'));

        try {
            $sink->onChunk('payload');
            self::fail('Expected the short write to be reported.');
        } catch (ResponseBufferException $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertStringContainsString('6 of 7 bytes', $exception->getMessage());
            self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
        } finally {
            $sink->discard();
        }
    }

    public function testASinkIsSingleUse(): void
    {
        $sink = new FileSink($this->directory . '/target.bin');
        $sink->commit();

        $this->expectException(\LogicException::class);
        $sink->onChunk('late');
    }
}
