<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Psr17;

use Domm98CZ\Curl\Psr7\Request;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

final class RequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }
}
