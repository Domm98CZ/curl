<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class SslVerification implements CurlOptionInterface
{
    public function __construct(
        private readonly bool $verifyPeer = true,
        private readonly bool $verifyHost = true,
    ) {
    }

    public function toCurlOptions(): array
    {
        return [
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $this->verifyHost ? 2 : 0,
        ];
    }
}
