<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\BodyReplay;
use Psr\Http\Message\RequestInterface;

interface CurlOptionsMapperInterface
{
    /** @return array<int, mixed> CURLOPT_* => value */
    public function map(RequestInterface $request, OptionsInterface $options): array;

    /** Whether mapping the same request twice would put the same body on the wire; Unknown when the mapper cannot tell. */
    public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay;
}
