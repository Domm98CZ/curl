<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr17;

use Domm98CZ\Curl\Psr17\RequestFactory;
use Domm98CZ\Curl\Psr7\Uri;
use Interop\Http\Factory\RequestFactoryTestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriInterface;

final class RequestFactoryConformanceTest extends RequestFactoryTestCase
{
    protected function createRequestFactory(): RequestFactoryInterface
    {
        return new RequestFactory();
    }

    protected function createUri($uri): UriInterface
    {
        return new Uri($uri);
    }
}
