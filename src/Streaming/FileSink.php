<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Streaming;

use Domm98CZ\Curl\Contract\RetryAwareInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use LogicException;
use RuntimeException;

/**
 * Writes a response body into a part file next to the target and moves it into place on commit(),
 * so the target path never holds a partially transferred file. Single-use: one sink, one download.
 */
final class FileSink implements StreamHandlerInterface, RetryAwareInterface
{
    /** @var resource|null */
    private $handle;

    private readonly string $partPath;

    public function __construct(private readonly string $path)
    {
        $this->partPath = $path . '.' . bin2hex(random_bytes(6)) . '.part';
        $handle = self::quietly(fn () => fopen($this->partPath, 'xb'));
        if (!is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to create a part file next to "%s".', $path));
        }
        $this->handle = $handle;
    }

    public function onHeaderLine(string $line): void
    {
    }

    public function onChunk(string $chunk): void
    {
        $length = strlen($chunk);
        $written = fwrite($this->openHandle(), $chunk);
        if ($written === false || $written < $length) {
            throw ResponseBufferException::forSinkWrite($this->path, $written, $length);
        }
    }

    public function resetForRetry(): bool
    {
        $handle = $this->openHandle();

        return ftruncate($handle, 0) && rewind($handle);
    }

    public function commit(): void
    {
        $handle = $this->openHandle();
        $this->handle = null;
        $flushed = fflush($handle) && fclose($handle);
        if (!$flushed || self::quietly(fn () => rename($this->partPath, $this->path)) !== true) {
            self::quietly(fn () => unlink($this->partPath));
            throw new RuntimeException(sprintf('Unable to move the downloaded file into place at "%s".', $this->path));
        }
    }

    public function discard(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->partPath)) {
            self::quietly(fn () => unlink($this->partPath));
        }
    }

    /** @return resource */
    private function openHandle()
    {
        if ($this->handle === null) {
            throw new LogicException(sprintf('The download to "%s" was already committed or discarded; use a new FileSink.', $this->path));
        }

        return $this->handle;
    }

    // Filesystem failures are reported through return values here; the warning would otherwise be
    // turned into an exception by a framework error handler before this class could name the path.
    private static function quietly(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
