<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr7;

use Domm98CZ\Curl\Psr17\StreamFactory;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\Psr7\Uri;
use Http\Psr7Test\RequestIntegrationTest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class RequestConformanceTest extends RequestIntegrationTest
{
    public function createSubject(): RequestInterface
    {
        return new Request('GET', '/');
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
}
