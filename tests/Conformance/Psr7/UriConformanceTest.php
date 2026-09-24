<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr7;

use Domm98CZ\Curl\Psr7\Uri;
use Http\Psr7Test\UriIntegrationTest;
use Psr\Http\Message\UriInterface;

final class UriConformanceTest extends UriIntegrationTest
{
    public function createUri($uri): UriInterface
    {
        return new Uri($uri);
    }
}
