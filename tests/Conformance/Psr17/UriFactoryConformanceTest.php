<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr17;

use Domm98CZ\Curl\Psr17\UriFactory;
use Interop\Http\Factory\UriFactoryTestCase;
use Psr\Http\Message\UriFactoryInterface;

final class UriFactoryConformanceTest extends UriFactoryTestCase
{
    protected function createUriFactory(): UriFactoryInterface
    {
        return new UriFactory();
    }
}
