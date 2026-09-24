<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Async;

use Closure;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Http\Promise\RejectedPromise;
use Psr\Http\Message\ResponseInterface;

final class CurlPromise implements Promise
{
    private string $state = Promise::PENDING;
    private ResponseInterface|\Throwable|null $result = null;

    /**
     * @param Closure(): void $tick
     * @param Closure(): bool $isDone
     * @param Closure(): ResponseInterface $resolve
     * @param (Closure(): void)|null $release
     */
    public function __construct(
        private readonly Closure $tick,
        private readonly Closure $isDone,
        private readonly Closure $resolve,
        private readonly ?Closure $release = null,
    ) {
    }

    public function __destruct()
    {
        if ($this->state !== Promise::PENDING || $this->release === null) {
            return;
        }

        try {
            ($this->release)();
        } catch (\Throwable) {
        }
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null)
    {
        $this->settle();
        // Only \Exception is caught, matching Http\Promise\FulfilledPromise: an \Error from a callback
        // is a programming mistake at the call site, not a rejected transfer, and must not be wrapped.
        try {
            if ($this->state === Promise::FULFILLED) {
                return new FulfilledPromise($onFulfilled === null ? $this->result : $onFulfilled($this->result));
            }
            if ($onRejected !== null) {
                return new FulfilledPromise($onRejected($this->result));
            }
        } catch (\Exception $exception) {
            return new RejectedPromise($exception);
        }

        $reason = $this->result;
        if (!$reason instanceof \Throwable) {
            throw new \LogicException('Rejected promise did not carry a Throwable.');
        }
        return new RejectedPromise($reason);
    }

    public function getState()
    {
        return $this->state;
    }

    public function wait($unwrap = true)
    {
        $this->settle();
        if ($unwrap && $this->state === Promise::REJECTED) {
            $exception = $this->result;
            if (!$exception instanceof \Throwable) {
                throw new \LogicException('Rejected promise did not carry a Throwable.');
            }
            throw $exception;
        }
        return $unwrap ? $this->result : null;
    }

    private function settle(): void
    {
        if ($this->state !== Promise::PENDING) {
            return;
        }
        try {
            while (!($this->isDone)()) {
                ($this->tick)();
            }
            $this->result = ($this->resolve)();
            $this->state = Promise::FULFILLED;
        } catch (\Throwable $exception) {
            $this->result = $exception;
            $this->state = Promise::REJECTED;
        }
    }
}
