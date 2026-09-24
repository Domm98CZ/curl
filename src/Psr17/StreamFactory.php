<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr17;

use Domm98CZ\Curl\Psr7\Stream;
use InvalidArgumentException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use ValueError;

final class StreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to open "php://temp" stream.');
        }
        fwrite($resource, $content);
        rewind($resource);
        return new Stream($resource);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if ($mode === '' || !in_array($mode[0], ['r', 'w', 'a', 'x', 'c'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid file mode "%s".', $mode));
        }

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            try {
                $resource = fopen($filename, $mode);
            } catch (ValueError) {
                $resource = false;
            }
        } finally {
            restore_error_handler();
        }
        if ($resource === false) {
            throw new RuntimeException(sprintf('Unable to open file "%s" with mode "%s".', $filename, $mode));
        }
        return new Stream($resource);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
