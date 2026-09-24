<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\RawResponse;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class BoundedMultiTransport implements MultiTransportInterface
{
    /** @var array<int, int> */
    private array $tickCalls = [];

    /** @var array<int, ?float> */
    private array $startedAt = [];

    public function __construct(
        private readonly MultiTransportInterface $transport,
        private readonly int $maxTickCalls = 1_000,
        private readonly float $deadlineSeconds = 3.0,
    ) {
        if ($this->maxTickCalls < 1) {
            throw new InvalidArgumentException('The tick limit must be positive.');
        }
        if ($this->deadlineSeconds <= 0.0) {
            throw new InvalidArgumentException('The deadline must be positive.');
        }
    }

    public function add(RequestInterface $request, OptionsInterface $options): int
    {
        $id = $this->transport->add($request, $options);
        $this->tickCalls[$id] = 0;
        $this->startedAt[$id] = null;

        return $id;
    }

    public function tick(): void
    {
        foreach (array_keys($this->tickCalls) as $id) {
            if ($this->transport->isDone($id)) {
                $this->forget($id);
                continue;
            }

            $this->startedAt[$id] ??= microtime(true);
            $this->tickCalls[$id]++;

            $this->assertTickLimit($id);
            $this->assertDeadline($id);
        }
        $this->transport->tick();
        foreach (array_keys($this->tickCalls) as $id) {
            $this->assertDeadline($id);
        }
    }

    public function isDone(int $id): bool
    {
        $done = $this->transport->isDone($id);
        if ($done) {
            $this->forget($id);
        }

        return $done;
    }

    public function takeResult(int $id): RawResponse
    {
        $this->forget($id);

        return $this->transport->takeResult($id);
    }

    public function release(int $id): void
    {
        $this->forget($id);
        try {
            $this->transport->release($id);
        } catch (\Throwable) {
        }
    }

    private function assertTickLimit(int $id): void
    {
        if ($this->tickCalls[$id] <= $this->maxTickCalls) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Async wait for handle %d exceeded the %d tick limit at iteration %d after %.3f seconds.',
            $id,
            $this->maxTickCalls,
            $this->tickCalls[$id],
            $this->elapsedSeconds($id),
        ));
    }

    private function assertDeadline(int $id): void
    {
        $elapsedSeconds = $this->elapsedSeconds($id);
        if ($elapsedSeconds <= $this->deadlineSeconds) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Async wait for handle %d exceeded the %.3f second deadline at iteration %d after %.3f seconds.',
            $id,
            $this->deadlineSeconds,
            $this->tickCalls[$id],
            $elapsedSeconds,
        ));
    }

    private function elapsedSeconds(int $id): float
    {
        return microtime(true) - ($this->startedAt[$id] ?? microtime(true));
    }

    private function forget(int $id): void
    {
        unset($this->tickCalls[$id], $this->startedAt[$id]);
    }
}
