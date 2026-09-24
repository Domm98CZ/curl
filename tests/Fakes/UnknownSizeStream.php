<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Psr\Http\Message\StreamInterface;

/**
 * A readable body whose length cannot be determined up front, the way a pipe or a socket behaves.
 */
final class UnknownSizeStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $inner)
    {
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write($string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read($length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata($key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
