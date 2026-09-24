<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use CurlHandle;
use Domm98CZ\Curl\Exceptions\RequestException;
use Psr\Http\Message\RequestInterface;

/** @internal */
final class CurlOptionsApplier
{
    /** @return array<string, int|string> */
    public static function userCallbackOptions(): array
    {
        return [
            'CURLOPT_READFUNCTION' => '',
            'CURLOPT_PROGRESSFUNCTION' => 1,
            'CURLOPT_XFERINFOFUNCTION' => 1,
            'CURLOPT_DEBUGFUNCTION' => 1,
            'CURLOPT_FNMATCH_FUNCTION' => 1,
            'CURLOPT_PREREQFUNCTION' => 1,
            'CURLOPT_SSH_HOSTKEYFUNCTION' => 1,
        ];
    }

    // Apply options individually because curl_setopt_array() silently skips the rest after a rejection.
    /** @param array<int, mixed> $curlOptions */
    public static function apply(CurlHandle $handle, array $curlOptions, RequestInterface $request): void
    {
        foreach ($curlOptions as $option => $value) {
            self::assertCallableCallback($option, $value, $request);

            try {
                $accepted = curl_setopt($handle, $option, $value);
            } catch (\ValueError | \TypeError $error) {
                throw self::rejectedOption($error, $option, $request);
            }

            if ($accepted === false) {
                throw new RequestException(
                    sprintf('libcurl rejected option %d; the request was not sent.', $option),
                    $request
                );
            }
        }
    }

    public static function assertCallableCallback(int $option, mixed $value, RequestInterface $request): void
    {
        if (!self::isCallbackOption($option) || is_callable($value)) {
            return;
        }

        throw self::rejectedOption(
            new \TypeError(sprintf('Callback option %d must be callable.', $option)),
            $option,
            $request,
        );
    }

    private static function isCallbackOption(int $option): bool
    {
        if ($option === CURLOPT_WRITEFUNCTION || $option === CURLOPT_HEADERFUNCTION) {
            return true;
        }

        foreach (self::userCallbackOptions() as $constant => $_) {
            if (defined($constant) && $option === constant($constant)) {
                return true;
            }
        }

        return false;
    }

    private static function rejectedOption(\ValueError | \TypeError $error, int $option, RequestInterface $request): RequestException
    {
        // Invalid options are permanent configuration faults, so retries must not replay them.
        return new RequestException(
            $error instanceof \TypeError
                ? sprintf('PHP rejected option %d before libcurl received it; the request was not sent.', $option)
                : sprintf('libcurl rejected option %d; the request was not sent.', $option),
            $request,
            $error,
        );
    }
}
