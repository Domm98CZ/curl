<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;

interface TransportInterface
{
    public function execute(RequestInterface $request, OptionsInterface $options): RawResponse;

    /** Whether a second execute() of the same request would put the same bytes on the wire; Unknown when the transport cannot tell. */
    public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay;
}
