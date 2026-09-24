<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr17;

use Domm98CZ\Curl\Psr17\StreamFactory;
use Interop\Http\Factory\StreamFactoryTestCase;
use Psr\Http\Message\StreamFactoryInterface;

final class StreamFactoryConformanceTest extends StreamFactoryTestCase
{
    protected function createStreamFactory(): StreamFactoryInterface
    {
        return new StreamFactory();
    }
}
