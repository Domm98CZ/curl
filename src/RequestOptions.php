<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Contract\TransferInfoCollectorInterface;
use TypeError;

final class RequestOptions implements OptionsInterface
{
    /** @var list<CurlOptionInterface> */
    private array $options = [];

    /** @param CurlOptionInterface[] $options */
    public function __construct(
        array $options = [],
        private readonly ?TransferInfoCollectorInterface $transferInfo = null,
        private readonly ?StreamHandlerInterface $streamHandler = null,
    ) {
        foreach ($options as $option) {
            // the native array type cannot express the element type, and without this the mismatch
            // would surface only inside the mapper as an undefined-method Error outside PSR-18
            // @phpstan-ignore instanceof.alwaysTrue
            if (!$option instanceof CurlOptionInterface) {
                throw new TypeError(sprintf(
                    '%s::__construct(): Argument #1 ($options) must be a list of %s, %s given.',
                    self::class,
                    CurlOptionInterface::class,
                    get_debug_type($option)
                ));
            }
            $this->options[] = $option;
        }
    }

    public function all(): array
    {
        return $this->options;
    }

    public function withOption(CurlOptionInterface $option): static
    {
        $new = clone $this;
        $new->options[] = $option;
        return $new;
    }

    public function getTransferInfoCollector(): ?TransferInfoCollectorInterface
    {
        return $this->transferInfo;
    }

    public function getStreamHandler(): ?StreamHandlerInterface
    {
        return $this->streamHandler;
    }
}
