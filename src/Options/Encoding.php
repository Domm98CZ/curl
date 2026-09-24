<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class Encoding implements CurlOptionInterface
{
    /** @param string $encoding '' negotiates and auto-decodes every encoding curl supports (gzip, deflate, br, ...) */
    public function __construct(private readonly string $encoding = '')
    {
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_ENCODING => $this->encoding];
    }
}
