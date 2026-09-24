<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\BodyReplay;
use Psr\Http\Message\RequestInterface;

// Opt-in and deliberately separate from HttpClientInterface: a client that cannot see how the
// request will be mapped must stay implementable without having to guess a verdict.
interface ReplayAwareInterface
{
    public function replayabilityOf(RequestInterface $request, ?OptionsInterface $options = null): BodyReplay;
}
