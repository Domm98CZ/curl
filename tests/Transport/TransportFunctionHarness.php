<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use PHPUnit\Framework\Attributes\After;

// The namespaced fwrite()/curl_multi_add_handle() at the bottom of this file shadow the global
// ones for every call made from src/Transport/*.php, for the whole life of the PHP process. That
// reach is why nothing here holds mutable state: interception is inert unless a test owns a live
// TransportFunctionInterceptor, only one can exist at a time, and the trait below releases it
// after the test even when the test body throws.
final class TransportFunctionHarness
{
    private static ?TransportFunctionInterceptor $active = null;

    public static function activate(): TransportFunctionInterceptor
    {
        if (self::$active !== null) {
            throw new \LogicException(
                'A transport function interceptor is still active; a previous test leaked it instead of releasing it.'
            );
        }

        return self::$active = new TransportFunctionInterceptor();
    }

    public static function release(): void
    {
        self::$active = null;
    }

    /** @param resource $stream */
    public static function write($stream, string $data, ?int $length): int|false
    {
        $failure = self::$active?->takeWriteFailure($stream);
        if ($failure !== null) {
            throw $failure;
        }
        $refused = self::$active?->takeWriteResult($stream);
        if ($refused !== null) {
            return $refused;
        }

        return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
    }

    public static function addHandle(\CurlMultiHandle $multiHandle, \CurlHandle $easyHandle): int
    {
        $interceptor = self::$active;
        if ($interceptor === null) {
            return \curl_multi_add_handle($multiHandle, $easyHandle);
        }

        $interceptor->notifyMultiAdd($easyHandle);

        return $interceptor->takeMultiAddStatus() ?? \curl_multi_add_handle($multiHandle, $easyHandle);
    }
}

final class TransportFunctionInterceptor
{
    private const ANY_RESOURCE = -1;

    private ?int $failingResourceId = null;
    private ?\Throwable $writeFailure = null;
    private ?int $refusingResourceId = null;
    private int|false|null $writeResult = null;
    private ?\Closure $multiAddObserver = null;
    private ?int $multiAddStatus = null;

    /** @param resource $resource */
    public function failWritesTo($resource, \Throwable $failure): self
    {
        $this->failingResourceId = (int) $resource;
        $this->writeFailure = $failure;

        return $this;
    }

    // Unscoped trap: the next write from anywhere in the process fails, so arm it only through
    // whileArmed(), which disarms it again before control leaves the guarded operation.
    public function failNextWrite(\Throwable $failure): self
    {
        $this->failingResourceId = self::ANY_RESOURCE;
        $this->writeFailure = $failure;

        return $this;
    }

    // A refused write reports itself by return value only, the way a real one does — no exception and
    // no warning. Unscoped like failNextWrite(), so arm it only through whileArmed().
    public function refuseNextWrite(int|false $result): self
    {
        $this->refusingResourceId = self::ANY_RESOURCE;
        $this->writeResult = $result;

        return $this;
    }

    /** @param resource $resource */
    public function refuseWritesTo($resource, int|false $result): self
    {
        $this->refusingResourceId = (int) $resource;
        $this->writeResult = $result;

        return $this;
    }

    /** @param \Closure(\CurlHandle): void $observer */
    public function observeMultiAdd(\Closure $observer): self
    {
        $this->multiAddObserver = $observer;

        return $this;
    }

    public function refuseNextMultiAdd(int $status): self
    {
        $this->multiAddStatus = $status;

        return $this;
    }

    /**
     * @template TResult
     * @param \Closure(): TResult $operation
     * @return TResult
     */
    public function whileArmed(\Closure $operation): mixed
    {
        try {
            return $operation();
        } finally {
            $this->disarm();
        }
    }

    public function disarm(): void
    {
        $this->failingResourceId = null;
        $this->writeFailure = null;
        $this->refusingResourceId = null;
        $this->writeResult = null;
        $this->multiAddObserver = null;
        $this->multiAddStatus = null;
    }

    /** @param resource $stream */
    public function takeWriteFailure($stream): ?\Throwable
    {
        $failure = $this->writeFailure;
        if ($failure === null) {
            return null;
        }
        if ($this->failingResourceId !== self::ANY_RESOURCE && $this->failingResourceId !== (int) $stream) {
            return null;
        }
        $this->failingResourceId = null;
        $this->writeFailure = null;

        return $failure;
    }

    /** @param resource $stream */
    public function takeWriteResult($stream): int|false|null
    {
        $result = $this->writeResult;
        if ($result === null) {
            return null;
        }
        if ($this->refusingResourceId !== self::ANY_RESOURCE && $this->refusingResourceId !== (int) $stream) {
            return null;
        }
        $this->refusingResourceId = null;
        $this->writeResult = null;

        return $result;
    }

    public function notifyMultiAdd(\CurlHandle $easyHandle): void
    {
        $observer = $this->multiAddObserver;
        if ($observer === null) {
            return;
        }

        $observer($easyHandle);
    }

    public function takeMultiAddStatus(): ?int
    {
        $status = $this->multiAddStatus;
        $this->multiAddStatus = null;

        return $status;
    }
}

trait InterceptsTransportFunctions
{
    private ?TransportFunctionInterceptor $transportFunctions = null;

    protected function interceptTransportFunctions(): TransportFunctionInterceptor
    {
        return $this->transportFunctions ??= TransportFunctionHarness::activate();
    }

    // #[After] runs after a failing or erroring test too, which finally-in-the-test-body does not
    // guarantee once a test is edited; the process-wide shims must never outlive their owner.
    #[After]
    protected function releaseTransportFunctions(): void
    {
        $this->transportFunctions?->disarm();
        $this->transportFunctions = null;
        TransportFunctionHarness::release();
    }
}

/** @param resource $stream */
function fwrite($stream, string $data, ?int $length = null): int|false
{
    return TransportFunctionHarness::write($stream, $data, $length);
}

function curl_multi_add_handle(\CurlMultiHandle $multiHandle, \CurlHandle $easyHandle): int
{
    return TransportFunctionHarness::addHandle($multiHandle, $easyHandle);
}
