<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\PoolInterface;
use Domm98CZ\Curl\Contract\ResponseParserInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use Domm98CZ\Curl\Transport\HttpResponseParser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Pool implements PoolInterface
{
    public function __construct(
        private readonly MultiTransportInterface $multiTransport = new CurlMultiTransport(),
        private readonly ResponseParserInterface $responseParser = new HttpResponseParser(),
    ) {
    }

    public function send(iterable $requests, ?int $concurrency = null, ?OptionsInterface $options = null): array
    {
        if ($concurrency !== null && $concurrency < 1) {
            throw new \InvalidArgumentException('concurrency must be a positive integer or null.');
        }
        // both collaborators are per-transfer state: chunks of concurrent bodies would interleave in one
        // handler and a collector accepts exactly one transfer, so sharing them cannot mean anything
        if ($options?->getStreamHandler() !== null || $options?->getTransferInfoCollector() !== null) {
            throw new \InvalidArgumentException(
                'Options shared by a Pool batch cannot carry a stream handler or a transfer info collector; '
                . 'use AsyncClient::sendAsync() with per-request options instead.'
            );
        }
        $shared = $options ?? new RequestOptions();
        $requests = is_array($requests) ? $requests : iterator_to_array($requests);
        $keys = array_keys($requests);
        $next = 0;

        $results = [];
        /** @var array<int, int|string> $inFlight handle id => request key */
        $inFlight = [];
        try {
            while ($next < count($keys) || $inFlight !== []) {
                while ($next < count($keys) && ($concurrency === null || count($inFlight) < $concurrency)) {
                    $key = $keys[$next++];
                    try {
                        HttpSchemeGuard::assert($requests[$key]);
                        $inFlight[$this->multiTransport->add($requests[$key], $shared)] = $key;
                    } catch (\Exception $exception) {
                        $results[$key] = $exception;
                    }
                }
                if ($inFlight === []) {
                    continue;
                }

                $this->multiTransport->tick();
                foreach ($inFlight as $id => $key) {
                    if (!$this->multiTransport->isDone($id)) {
                        continue;
                    }
                    unset($inFlight[$id]);
                    try {
                        $results[$key] = $this->resolve($this->multiTransport->takeResult($id), $requests[$key]);
                    } catch (\Exception $exception) {
                        $results[$key] = $exception;
                    }
                }
            }
        } finally {
            // Only an Error escapes the loops above; releasing lets the transport drop in-flight siblings.
            foreach (array_keys($inFlight) as $id) {
                $this->multiTransport->release($id);
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    private function resolve(RawResponse $raw, RequestInterface $request): ResponseInterface
    {
        if ($raw->isTransportError()) {
            throw ClientException::forTransportError($raw, $request);
        }

        return $this->responseParser->parse($raw);
    }
}
