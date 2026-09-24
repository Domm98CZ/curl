<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface CurlOptionInterface
{
    /** @return array<int, mixed> CURLOPT_* => value; must be free of side effects, the mapper may call it more than once */
    public function toCurlOptions(): array;
}
