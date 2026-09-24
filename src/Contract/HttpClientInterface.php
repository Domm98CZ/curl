<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface HttpClientInterface extends ClientInterface
{
    public function send(RequestInterface $request, ?OptionsInterface $options = null): ResponseInterface;
}
