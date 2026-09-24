<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Throwable;

/**
 * The CURLOPT_HEADERFUNCTION/CURLOPT_WRITEFUNCTION pair the library owns on every transfer, plus
 * the state those two share. The state is an object rather than the by-reference locals each
 * transport used to keep, because that is the only thing the two paths ever disagreed on: the
 * blocking transport reads it back in the same scope, the multi transport reads it back per handle
 * long after add() has returned.
 *
 * @internal
 */
final class DeliveryCallbacks
{
    public ?Throwable $failure = null;

    public bool $responseSizeExceeded = false;

    public int $receivedBytes = 0;

    private function __construct()
    {
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param resource $bodyResource
     */
    public static function install(
        array &$curlOptions,
        ?StreamHandlerInterface $streamHandler,
        HeaderBuffer $headerBuffer,
        $bodyResource,
        ?int $maximumResponseSize,
    ): self {
        $state = new self();

        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use ($state, $headerBuffer, $streamHandler, $maximumResponseSize): int {
            $lineLength = strlen($line);
            if ($maximumResponseSize !== null) {
                $state->receivedBytes += $lineLength;
                if ($state->receivedBytes > $maximumResponseSize) {
                    $state->responseSizeExceeded = true;
                    return 0;
                }
            }
            $headerBuffer->raw .= $line;
            try {
                $streamHandler?->onHeaderLine($line);
            } catch (Throwable $exception) {
                $state->failure ??= $exception;
                return 0;
            }
            return $lineLength;
        };
        $curlOptions[CURLOPT_WRITEFUNCTION] = $streamHandler !== null
            ? static function ($ch, string $chunk) use ($state, $streamHandler, $maximumResponseSize): int {
                if ($maximumResponseSize !== null) {
                    $state->receivedBytes += strlen($chunk);
                    if ($state->receivedBytes > $maximumResponseSize) {
                        $state->responseSizeExceeded = true;
                        return 0;
                    }
                }
                try {
                    $streamHandler->onChunk($chunk);
                } catch (Throwable $exception) {
                    $state->failure ??= $exception;
                    return 0;
                }
                return strlen($chunk);
            }
            : static function ($ch, string $chunk) use ($state, $bodyResource, $maximumResponseSize): int {
                if ($maximumResponseSize !== null) {
                    $state->receivedBytes += strlen($chunk);
                    if ($state->receivedBytes > $maximumResponseSize) {
                        $state->responseSizeExceeded = true;
                        return 0;
                    }
                }
                $length = strlen($chunk);
                try {
                    $written = fwrite($bodyResource, $chunk);
                } catch (Throwable $exception) {
                    // Wrapped here rather than in the transports: the same slot also carries a
                    // consumer StreamHandler's exception, whose identity is promised to survive, and
                    // no consumer code runs in this branch.
                    $state->failure ??= ResponseBufferException::forThrownWrite($exception, $length);

                    return 0;
                }
                // A short write is as fatal to the body as a refused one, and libcurl reports both
                // the same way, so neither may pass as a legitimate byte count.
                if ($written === false || $written < $length) {
                    $state->failure ??= ResponseBufferException::forRefusedWrite($written, $length);

                    return 0;
                }

                return $written;
            };

        return $state;
    }
}
