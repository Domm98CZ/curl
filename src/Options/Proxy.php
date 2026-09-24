<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class Proxy implements CurlOptionInterface
{
    public function __construct(private readonly string $url)
    {
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_PROXY => $this->url];
    }
}
