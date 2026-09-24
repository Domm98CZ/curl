<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface OptionsInterface
{
    /** @return CurlOptionInterface[] */
    public function all(): array;

    public function withOption(CurlOptionInterface $option): static;

    public function getTransferInfoCollector(): ?TransferInfoCollectorInterface;

    public function getStreamHandler(): ?StreamHandlerInterface;
}
