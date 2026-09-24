<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr7;

use Domm98CZ\Curl\Psr17\StreamFactory;
use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\Psr7\Uri;
use Http\Psr7Test\ResponseIntegrationTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class ResponseConformanceTest extends ResponseIntegrationTest
{
    public function createSubject(): ResponseInterface
    {
        return new Response();
    }

    protected function buildUri($uri): UriInterface
    {
        return new Uri($uri);
    }

    protected function buildStream($data): StreamInterface
    {
        if (is_resource($data)) {
            return new Stream($data);
        }

        return (new StreamFactory())->createStream($data);
    }

    public static function getInvalidHeaderArguments()
    {
        // Replacing a header's values with an empty set leaves the header with no value at all, which
        // this library normalizes to a removal instead of rejecting as an invalid argument.
        foreach (parent::getInvalidHeaderArguments() as $arguments) {
            if ($arguments !== ['foo', []]) {
                yield $arguments;
            }
        }
    }

    public static function getInvalidStatusCodeArguments()
    {
        // withStatus() validates the 100-999 range rather than 100-599, so 600 is a status this
        // library accepts instead of refusing.
        foreach (parent::getInvalidStatusCodeArguments() as $name => $arguments) {
            if ($arguments !== [600]) {
                yield $name => $arguments;
            }
        }
    }
}
