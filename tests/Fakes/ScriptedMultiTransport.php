<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

// Each added transfer completes after the number of ticks scripted for it, in add order, so a test
// can observe when a caller starts the next transfer relative to the ones already in flight.
final class ScriptedMultiTransport implements MultiTransportInterface
{
    /** @var list<int> */
    private array $ticksToComplete;

    private int $nextId = 1;

    public int $ticks = 0;

    /** @var array<int, int> handle id => remaining ticks */
    private array $remaining = [];

    /** @var array<int, RawResponse> */
    private array $done = [];

    /** @var list<int> tick count at which each transfer was added */
    public array $addedAtTick = [];

    /** @var list<int> */
    public array $activeAtTick = [];

    public function __construct(int ...$ticksToComplete)
    {
        $this->ticksToComplete = array_values($ticksToComplete);
    }

    public function add(RequestInterface $request, OptionsInterface $options): int
    {
        $index = $this->nextId - 1;
        if (!isset($this->ticksToComplete[$index])) {
            throw new RuntimeException('ScriptedMultiTransport has no script for another transfer.');
        }
        $id = $this->nextId++;
        $this->remaining[$id] = $this->ticksToComplete[$index];
        $this->addedAtTick[] = $this->ticks;

        return $id;
    }

    public function tick(): void
    {
        $this->activeAtTick[] = count($this->remaining);
        $this->ticks++;
        foreach ($this->remaining as $id => $left) {
            if ($left <= 1) {
                unset($this->remaining[$id]);
                $this->done[$id] = new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
                continue;
            }
            $this->remaining[$id] = $left - 1;
        }
    }

    public function isDone(int $id): bool
    {
        if (!isset($this->remaining[$id]) && !isset($this->done[$id])) {
            throw new RuntimeException(sprintf('Handle %d is unknown or its result was already consumed.', $id));
        }

        return isset($this->done[$id]);
    }

    public function takeResult(int $id): RawResponse
    {
        if (!isset($this->done[$id])) {
            throw new RuntimeException(sprintf('Handle %d has not finished yet.', $id));
        }
        $raw = $this->done[$id];
        unset($this->done[$id]);

        return $raw;
    }

    public function release(int $id): void
    {
        unset($this->remaining[$id], $this->done[$id]);
    }
}
