<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr7;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    /** @param resource $resource */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new RuntimeException('Stream must be constructed with a valid resource.');
        }
        $this->resource = $resource;
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }
        try {
            $this->rewind();
            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        // fstat() reports size 0 for pipes and sockets; reporting that as a real size makes callers
        // treat a non-empty stream as empty, so an unknown size must stay unknown.
        if (!$this->isSeekable()) {
            return null;
        }
        $stats = fstat($this->resource);
        return $stats !== false ? $stats['size'] : null;
    }

    public function tell(): int
    {
        $resource = $this->assertOpen();
        $position = ftell($resource);
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }
        return $position;
    }

    public function eof(): bool
    {
        if ($this->resource === null) {
            return true;
        }
        if (feof($this->resource)) {
            return true;
        }
        if (!$this->isSeekable()) {
            return false;
        }
        $pos = ftell($this->resource);
        $size = $this->getSize();
        return $pos !== false && $size !== null && $pos >= $size;
    }

    public function isSeekable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        return (bool) $this->getMetadataArray($this->resource)['seekable'];
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $resource = $this->assertOpen();
        if (!$this->isSeekable() || fseek($resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek in stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        $mode = $this->getMetadataArray($this->resource)['mode'];
        return (bool) preg_match('/[waxc+]/', $mode);
    }

    public function write($string): int
    {
        $resource = $this->assertOpen();
        if (!$this->isWritable()) {
            throw new RuntimeException('Unable to write to stream.');
        }
        $written = fwrite($resource, $string);
        if ($written === false) {
            throw new RuntimeException('Unable to write to stream.');
        }
        return $written;
    }

    public function isReadable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        $mode = $this->getMetadataArray($this->resource)['mode'];
        return (bool) preg_match('/[r+]/', $mode);
    }

    public function read($length): string
    {
        $resource = $this->assertOpen();
        if ($length < 1) {
            throw new RuntimeException('Length must be a positive integer.');
        }
        if (!$this->isReadable()) {
            throw new RuntimeException('Unable to read from stream.');
        }
        $data = fread($resource, $length);
        if ($data === false) {
            throw new RuntimeException('Unable to read from stream.');
        }
        return $data;
    }

    public function getContents(): string
    {
        $resource = $this->assertOpen();
        if (!$this->isReadable()) {
            throw new RuntimeException('Unable to read stream contents.');
        }
        $contents = stream_get_contents($resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }
        return $contents;
    }

    public function getMetadata($key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }
        $metadata = $this->getMetadataArray($this->resource);
        if ($key === null) {
            return $metadata;
        }
        return $metadata[$key] ?? null;
    }

    /**
     * @param resource $resource
     * @return array<string, mixed>
     */
    private function getMetadataArray($resource): array
    {
        return stream_get_meta_data($resource);
    }

    /** @return resource */
    private function assertOpen()
    {
        if ($this->resource === null) {
            throw new RuntimeException('Stream is detached.');
        }
        return $this->resource;
    }
}
