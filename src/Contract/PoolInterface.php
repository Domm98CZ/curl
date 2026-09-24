<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface PoolInterface
{
    /**
     * @param iterable<RequestInterface> $requests
     * @param OptionsInterface|null $options shared by every request in the batch; a stream handler or a transfer info collector cannot be shared and is refused
     * @return array<int, ResponseInterface|\Exception> same order/keys as $requests; library transport and protocol failures always implement ClientExceptionInterface, while caller misuse may appear as RuntimeException and consumer callback exceptions retain their type
     */
    public function send(iterable $requests, ?int $concurrency = null, ?OptionsInterface $options = null): array;
}
