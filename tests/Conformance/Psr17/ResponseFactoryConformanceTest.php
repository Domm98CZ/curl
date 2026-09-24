<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr17;

use Domm98CZ\Curl\Psr17\ResponseFactory;
use Interop\Http\Factory\ResponseFactoryTestCase;
use Psr\Http\Message\ResponseFactoryInterface;

final class ResponseFactoryConformanceTest extends ResponseFactoryTestCase
{
    protected function createResponseFactory(): ResponseFactoryInterface
    {
        return new ResponseFactory();
    }
}
