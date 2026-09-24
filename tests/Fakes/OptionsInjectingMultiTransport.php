<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;

// Pool attaches no per-request options by design; this stands between it and a real transport so an
// integration test can still exercise a transfer that needs some.
final class OptionsInjectingMultiTransport implements MultiTransportInterface
{
    /** @param array<string, OptionsInterface> $optionsByPath */
    public function __construct(
        private readonly MultiTransportInterface $inner,
        private readonly array $optionsByPath = [],
        private readonly ?OptionsInterface $defaultOptions = null,
    ) {
    }

    public function add(RequestInterface $request, OptionsInterface $options): int
    {
        $injected = $this->optionsByPath[$request->getUri()->getPath()] ?? $this->defaultOptions ?? $options;

        return $this->inner->add($request, $injected);
    }

    public function tick(): void
    {
        $this->inner->tick();
    }

    public function isDone(int $id): bool
    {
        return $this->inner->isDone($id);
    }

    public function takeResult(int $id): RawResponse
    {
        return $this->inner->takeResult($id);
    }

    public function release(int $id): void
    {
        $this->inner->release($id);
    }
}
