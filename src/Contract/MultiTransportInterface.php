<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;

interface MultiTransportInterface
{
    /** The returned handle is unique for the transport's lifetime and is never issued again. */
    public function add(RequestInterface $request, OptionsInterface $options): int;

    /** Implementations must release the processor while idle by blocking with a short upper bound. */
    public function tick(): void;

    /** The handle remains known until takeResult() consumes it; callers must memoize terminal state. @throws \RuntimeException for an unknown or consumed handle */
    public function isDone(int $id): bool;

    /** The terminal result is single-use. @throws \Throwable when the handle terminated with a stored failure */
    public function takeResult(int $id): RawResponse;

    /** Releases an abandoned handle; unknown, released, consumed, and otherwise stale handles are always ignored. */
    public function release(int $id): void;
}
