<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\RetryAwareInterface;
use Domm98CZ\Curl\Contract\TransferInfoCollectorInterface;
use Domm98CZ\Curl\Exceptions\ClientException;

final class TransferInfoCollector implements TransferInfoCollectorInterface, RetryAwareInterface
{
    private ?TransferInfo $info = null;

    public function collect(array $curlInfo): void
    {
        if ($this->info !== null) {
            throw new ClientException('TransferInfoCollector already collected — use a fresh instance per request.');
        }
        $this->info = TransferInfo::fromCurlInfo($curlInfo);
    }

    public function get(): TransferInfo
    {
        if ($this->info === null) {
            throw new ClientException('No transfer has been collected yet.');
        }
        return $this->info;
    }

    public function resetForRetry(): bool
    {
        $this->info = null;
        return true;
    }
}
