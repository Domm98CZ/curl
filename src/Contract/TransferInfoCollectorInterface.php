<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\TransferInfo;

interface TransferInfoCollectorInterface
{
    /** @param array<string, mixed> $curlInfo raw curl_getinfo() output */
    public function collect(array $curlInfo): void;

    public function get(): TransferInfo;
}
