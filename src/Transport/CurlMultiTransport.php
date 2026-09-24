<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use CurlHandle;
use CurlMultiHandle;
use Domm98CZ\Curl\Contract\CurlOptionsMapperInterface;
use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class CurlMultiTransport implements MultiTransportInterface
{
    private readonly CurlMultiHandle $multiHandle;

    /** @var array<int, array{easyHandle: ?CurlHandle, request: RequestInterface, options: OptionsInterface, headerBuffer: HeaderBuffer, bodyResource: resource, buffered: bool, maximumResponseSize: ?int, responseSizeExceeded: \Closure(): bool, callbackFailure: \Closure(): ?\Throwable, done: bool, failure: ?\Throwable, rawResponse: ?RawResponse}> */
    private array $handles = [];

    /** @var array<int, int> */
    private array $handleIds = [];

    private int $nextHandleId = 1;

    public function __construct(private readonly CurlOptionsMapperInterface $mapper = new CurlOptionsMapper())
    {
        $this->multiHandle = curl_multi_init();
    }

    public function __destruct()
    {
        try {
            foreach (array_keys($this->handles) as $id) {
                try {
                    $this->discard($id);
                } catch (\Throwable) {
                }
            }
            curl_multi_close($this->multiHandle);
        } catch (\Throwable) {
        }
    }

    public function add(RequestInterface $request, OptionsInterface $options): int
    {
        $easyHandle = curl_init();
        if (!$easyHandle instanceof CurlHandle) {
            throw new RequestException('curl_init() failed to produce a handle.', $request);
        }
        $id = $this->nextHandleId++;
        if (array_key_exists($id, $this->handles)) {
            throw new ClientException(sprintf('Handle %d was already issued by this transport.', $id));
        }

        $curlOptions = $this->mapper->map($request, $options);
        if (array_key_exists(CURLOPT_WRITEFUNCTION, $curlOptions)
            || array_key_exists(CURLOPT_HEADERFUNCTION, $curlOptions)
        ) {
            throw new RequestException(
                'CURLOPT_WRITEFUNCTION and CURLOPT_HEADERFUNCTION are owned by the library; use a StreamHandler instead.',
                $request,
            );
        }
        $maximumResponseSize = ResponseSizeLimit::resolve($curlOptions, $request);
        $streamHandler = $options->getStreamHandler();
        $headerBuffer = new HeaderBuffer();
        $bodyResource = fopen('php://temp', 'r+');
        if ($bodyResource === false) {
            throw new RequestException('Unable to open "php://temp" stream.', $request);
        }

        $callbacks = DeliveryCallbacks::install(
            $curlOptions,
            $streamHandler,
            $headerBuffer,
            $bodyResource,
            $maximumResponseSize,
        );
        self::quarantineCallbacks($curlOptions, $callbacks, $request);
        // Read back through closures rather than the object itself: the entry outlives this scope and
        // a caller holding it must not be able to write the state the callbacks own.
        $responseSizeExceededProbe = static function () use ($callbacks): bool {
            return $callbacks->responseSizeExceeded;
        };
        $callbackFailureProbe = static function () use ($callbacks): ?\Throwable {
            return $callbacks->failure;
        };

        CurlOptionsApplier::apply($easyHandle, $curlOptions, $request);
        $this->handleIds[(int) $easyHandle] = $id;
        $this->handles[$id] = [
            'easyHandle' => $easyHandle,
            'request' => $request,
            'options' => $options,
            'headerBuffer' => $headerBuffer,
            'bodyResource' => $bodyResource,
            'buffered' => $streamHandler === null,
            'maximumResponseSize' => $maximumResponseSize,
            'responseSizeExceeded' => $responseSizeExceededProbe,
            'callbackFailure' => $callbackFailureProbe,
            'done' => false,
            'failure' => null,
            'rawResponse' => null,
        ];
        // The evidence above must exist before the attach, so a failed attach is
        // the one case where an entry is reachable by nobody: the caller never receives its id.
        // Discarding it here is not the forbidden "attached handle without a mapping" state — the
        // handle is not attached at all.
        try {
            $status = curl_multi_add_handle($this->multiHandle, $easyHandle);
        } catch (\Throwable $exception) {
            $this->discard($id);

            throw $exception;
        }
        if ($status !== CURLM_OK) {
            $this->discard($id);

            throw new RequestException(
                sprintf('curl_multi_add_handle() refused the handle with status %d.', $status),
                $request,
            );
        }

        return $id;
    }

    public function tick(): void
    {
        $stillRunning = null;
        try {
            do {
                $status = curl_multi_exec($this->multiHandle, $stillRunning);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        } catch (\Throwable $exception) {
            $finished = false;
            foreach ($this->handles as $id => $entry) {
                if ($entry['done'] || !$entry['easyHandle'] instanceof CurlHandle) {
                    continue;
                }

                if (($entry['callbackFailure'])() instanceof \Throwable) {
                    $this->finish($id);
                } else {
                    $this->finish($id, new NetworkException(
                        sprintf(
                            'A concurrent callback of type %s interrupted this transfer.',
                            get_debug_type($exception),
                        ),
                        $entry['request'],
                    ));
                }
                $finished = true;
            }
            if (!$finished) {
                throw $exception;
            }
        }

        foreach ($this->handles as $id => $entry) {
            if ($entry['done'] || !$entry['easyHandle'] instanceof CurlHandle) {
                continue;
            }
            if (($entry['callbackFailure'])() instanceof \Throwable) {
                $this->finish($id);
            }
        }

        curl_multi_select($this->multiHandle, 0.1);

        while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
            $id = $this->handleIds[(int) $info['handle']] ?? null;
            if ($id === null) {
                continue;
            }
            if (array_key_exists($id, $this->handles) && !$this->handles[$id]['done']) {
                $this->finish($id);
            }
        }
    }

    // A bare RuntimeException here is deliberate and matches the PoolInterface contract: an unknown
    // or already consumed id is unreachable through the library's own callers (AsyncClient issues
    // one id per promise and CurlPromise settles once), so it can only come from caller misuse and
    // must not be catchable as a transport failure via ClientExceptionInterface.
    public function isDone(int $id): bool
    {
        if (!array_key_exists($id, $this->handles)) {
            throw new RuntimeException(sprintf('Handle %d is unknown or its result was already consumed.', $id));
        }

        return $this->handles[$id]['done'];
    }

    public function takeResult(int $id): RawResponse
    {
        if (!array_key_exists($id, $this->handles)) {
            throw new RuntimeException(sprintf('Handle %d is unknown or its result was already consumed.', $id));
        }
        if (!$this->handles[$id]['done']) {
            throw new RuntimeException(sprintf('Handle %d has not finished yet.', $id));
        }

        $entry = $this->handles[$id];
        unset($this->handles[$id]);

        if ($entry['failure'] instanceof \Throwable) {
            throw $entry['failure'];
        }

        $raw = $entry['rawResponse'];
        if (!$raw instanceof RawResponse) {
            throw new ClientException(sprintf('Handle %d finished without a result.', $id));
        }

        return $raw;
    }

    public function release(int $id): void
    {
        try {
            $this->discard($id);
        } catch (\Throwable) {
        }
    }

    private function finish(int $id, ?\Throwable $failure = null): void
    {
        $entry = $this->handles[$id];
        $handle = $entry['easyHandle'];
        if (!$handle instanceof CurlHandle) {
            return;
        }
        $failure ??= ($entry['callbackFailure'])();
        $curlInfo = [];
        $errno = 0;
        $curlError = '';

        try {
            $reportedInfo = curl_getinfo($handle);
            if (!is_array($reportedInfo)) {
                throw new ClientException('curl_getinfo() failed to report transfer information.');
            }
            $curlInfo = $reportedInfo;
            $errno = curl_errno($handle);
            $curlError = $errno !== 0 ? curl_error($handle) : '';
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        }

        try {
            curl_multi_remove_handle($this->multiHandle, $handle);
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        }

        unset($this->handleIds[(int) $handle]);
        $entry['easyHandle'] = null;

        try {
            $entry['options']->getTransferInfoCollector()?->collect($curlInfo);
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure === null) {
            try {
                $verdict = ResponseSizeLimit::verdict(
                    $entry['maximumResponseSize'],
                    ($entry['responseSizeExceeded'])(),
                    $errno,
                    $curlError,
                );

                rewind($entry['bodyResource']);
                $entry['rawResponse'] = new RawResponse(
                    $verdict['errno'],
                    $verdict['error'],
                    $entry['headerBuffer']->raw,
                    new Stream($entry['bodyResource']),
                    $curlInfo,
                    $entry['buffered'],
                    $verdict['exceeded'],
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
        }

        if ($failure !== null) {
            try {
                if (is_resource($entry['bodyResource'])) {
                    fclose($entry['bodyResource']);
                }
            } catch (\Throwable) {
            }
        }

        $entry['failure'] = $failure;
        $entry['done'] = true;

        $this->handles[$id] = $entry;
    }

    private function discard(int $id): void
    {
        if (!array_key_exists($id, $this->handles)) {
            return;
        }

        $entry = $this->handles[$id];
        unset($this->handles[$id]);
        $handle = $entry['easyHandle'];
        if ($handle instanceof CurlHandle) {
            try {
                curl_multi_remove_handle($this->multiHandle, $handle);
            } catch (\Throwable) {
            }
            unset($this->handleIds[(int) $handle]);
            $entry['easyHandle'] = null;
        }
        try {
            if (is_resource($entry['bodyResource'])) {
                fclose($entry['bodyResource']);
            }
        } catch (\Throwable) {
        }
    }

    /** @param array<int, mixed> $curlOptions */
    private static function quarantineCallbacks(
        array &$curlOptions,
        DeliveryCallbacks $callbacks,
        RequestInterface $request,
    ): void {
        foreach (CurlOptionsApplier::userCallbackOptions() as $constant => $failureResult) {
            if (!defined($constant)) {
                continue;
            }
            $option = constant($constant);
            if (!is_int($option)) {
                continue;
            }
            if (!array_key_exists($option, $curlOptions)) {
                continue;
            }
            CurlOptionsApplier::assertCallableCallback($option, $curlOptions[$option], $request);
            $callback = $curlOptions[$option];
            if ($constant === 'CURLOPT_READFUNCTION') {
                $curlOptions[$option] = static function ($ch, $fd, int $length) use ($callbacks, $callback, $request) {
                    try {
                        $result = $callback($ch, $fd, $length);
                        return is_string($result) ? $result : '';
                    } catch (RequestException $exception) {
                        $callbacks->failure ??= $exception;
                    } catch (RuntimeException $exception) {
                        $callbacks->failure ??= new RequestException('Unable to read the request body.', $request, $exception);
                    } catch (\Throwable $exception) {
                        $callbacks->failure ??= $exception;
                    }

                    return '';
                };
                continue;
            }

            $curlOptions[$option] = static function (...$arguments) use ($callbacks, $callback, $failureResult): mixed {
                try {
                    return $callback(...$arguments);
                } catch (\Throwable $exception) {
                    $callbacks->failure ??= $exception;

                    return $failureResult;
                }
            };
        }
    }
}
