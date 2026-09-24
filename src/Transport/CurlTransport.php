<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use CurlHandle;
use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\CurlOptionsMapperInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\TransportInterface;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\RequestInterface;

final class CurlTransport implements TransportInterface
{
    public function __construct(private readonly CurlOptionsMapperInterface $mapper = new CurlOptionsMapper())
    {
    }

    public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
    {
        return $this->mapper->replayabilityOf($request, $options);
    }

    public function execute(RequestInterface $request, OptionsInterface $options): RawResponse
    {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new RequestException('curl_init() failed to produce a handle.', $request);
        }

        $streamHandler = $options->getStreamHandler();
        $headerBuffer = new HeaderBuffer();
        $bodyResource = fopen('php://temp', 'r+');
        if ($bodyResource === false) {
            throw new RequestException('Unable to open "php://temp" stream.', $request);
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
        $callbacks = DeliveryCallbacks::install(
            $curlOptions,
            $streamHandler,
            $headerBuffer,
            $bodyResource,
            $maximumResponseSize,
        );

        CurlOptionsApplier::apply($handle, $curlOptions, $request);
        $info = null;
        $transferException = null;
        try {
            try {
                curl_exec($handle);
            } catch (RequestException $exception) {
                throw $exception;
            } catch (\RuntimeException $exception) {
                throw new RequestException('Unable to read the request body.', $request, $exception);
            }
            $callbackFailure = $callbacks->failure;
            if ($callbackFailure instanceof \Throwable) {
                throw $callbackFailure;
            }
        } catch (\Throwable $exception) {
            $transferException = $exception;
            throw $exception;
        } finally {
            try {
                $collector = $options->getTransferInfoCollector();
                if ($collector !== null) {
                    $info = curl_getinfo($handle);
                    $collector->collect($info);
                }
            } catch (\Throwable $collectorException) {
                if ($transferException === null) {
                    throw $collectorException;
                }
            }
        }

        $errno = curl_errno($handle);
        $verdict = ResponseSizeLimit::verdict(
            $maximumResponseSize,
            $callbacks->responseSizeExceeded,
            $errno,
            $errno !== 0 ? curl_error($handle) : '',
        );
        $info ??= curl_getinfo($handle);

        rewind($bodyResource);

        return new RawResponse(
            $verdict['errno'],
            $verdict['error'],
            $headerBuffer->raw,
            new Stream($bodyResource),
            $info,
            $streamHandler === null,
            $verdict['exceeded'],
        );
    }
}
