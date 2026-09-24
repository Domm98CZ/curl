<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Psr\Http\Message\StreamInterface;

/**
 * Records every StreamInterface call so a test can assert which parts of a body the mapper
 * inspected, not merely which curl options came out of it.
 */
final class RecordingStream implements StreamInterface
{
    /** @var string[] */
    public array $calls = [];

    public function __construct(private readonly StreamInterface $inner)
    {
    }

    public function __toString(): string
    {
        $this->calls[] = '__toString';
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->calls[] = 'close';
        $this->inner->close();
    }

    public function detach()
    {
        $this->calls[] = 'detach';
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        $this->calls[] = 'getSize';
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        $this->calls[] = 'tell';
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        $this->calls[] = 'eof';
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        $this->calls[] = 'isSeekable';
        return $this->inner->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->calls[] = 'seek';
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->calls[] = 'rewind';
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        $this->calls[] = 'isWritable';
        return $this->inner->isWritable();
    }

    public function write($string): int
    {
        $this->calls[] = 'write';
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        $this->calls[] = 'isReadable';
        return $this->inner->isReadable();
    }

    public function read($length): string
    {
        $this->calls[] = 'read';
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        $this->calls[] = 'getContents';
        return $this->inner->getContents();
    }

    public function getMetadata($key = null)
    {
        $this->calls[] = 'getMetadata';
        return $this->inner->getMetadata($key);
    }
}
