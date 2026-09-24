<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class FakeMultiTransport implements MultiTransportInterface
{
    private int $nextId = 1;

    /** @var RawResponse[] */
    private array $queue;

    /** @var array<int, RawResponse> */
    private array $pending = [];

    /** @var array<int, RawResponse> */
    private array $done = [];

    private ?\Throwable $nextTickFailure = null;

    public int $addCalls = 0;
    public int $tickCalls = 0;

    /** @var list<OptionsInterface> */
    public array $optionsSeen = [];

    public function __construct(RawResponse ...$responsesInAddOrder)
    {
        $this->queue = $responsesInAddOrder;
    }

    public function add(RequestInterface $request, OptionsInterface $options): int
    {
        $this->addCalls++;
        $this->optionsSeen[] = $options;
        if ($this->queue === []) {
            throw new RuntimeException('FakeMultiTransport cannot add a request without a queued response.');
        }

        $id = $this->nextId++;
        $this->pending[$id] = array_shift($this->queue);
        return $id;
    }

    public function tick(): void
    {
        $this->tickCalls++;
        if ($this->nextTickFailure instanceof \Throwable) {
            $failure = $this->nextTickFailure;
            $this->nextTickFailure = null;
            throw $failure;
        }

        foreach ($this->pending as $id => $raw) {
            $this->done[$id] = $raw;
            unset($this->pending[$id]);
        }
    }

    public function isDone(int $id): bool
    {
        if (!isset($this->pending[$id]) && !isset($this->done[$id])) {
            throw new RuntimeException(sprintf('Handle %d is unknown or its result was already consumed.', $id));
        }

        return isset($this->done[$id]);
    }

    public function takeResult(int $id): RawResponse
    {
        if (!isset($this->pending[$id]) && !isset($this->done[$id])) {
            throw new RuntimeException(sprintf('Handle %d is unknown or its result was already consumed.', $id));
        }
        if (isset($this->pending[$id])) {
            throw new RuntimeException(sprintf('Handle %d has not finished yet.', $id));
        }
        $raw = $this->done[$id];
        unset($this->done[$id]);
        return $raw;
    }

    public function release(int $id): void
    {
        $raw = $this->pending[$id] ?? $this->done[$id] ?? null;
        unset($this->pending[$id], $this->done[$id]);
        try {
            $raw?->body->close();
        } catch (\Throwable) {
        }
    }

    public function failNextTickWith(\Throwable $failure): void
    {
        $this->nextTickFailure = $failure;
    }
}
