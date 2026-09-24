<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;

interface AsyncClientInterface
{
    public function sendAsync(RequestInterface $request, ?OptionsInterface $options = null): Promise;
}
