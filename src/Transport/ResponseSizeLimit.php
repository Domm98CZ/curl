<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use Domm98CZ\Curl\Exceptions\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * Reads the response ceiling back out of the mapped curl options so the library can count decoded
 * bytes itself. The write callback receives bytes libcurl has already decoded, so that counter is
 * the check after decompression; CURLOPT_MAXFILESIZE only pre-flights the advertised wire size and
 * cannot stop a compressed bomb, so reading it back here does not duplicate libcurl.
 *
 * @internal
 */
final class ResponseSizeLimit
{
    /** @param array<int, mixed> $curlOptions */
    public static function resolve(array $curlOptions, RequestInterface $request): ?int
    {
        $limit = null;
        // CURLOPT_MAXFILESIZE_LARGE has no PHP binding before 8.2, so both keys are resolved by name.
        foreach (['CURLOPT_MAXFILESIZE', 'CURLOPT_MAXFILESIZE_LARGE'] as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $key = constant($constant);
            // An absent key, null or false is a deliberate "no ceiling"; a present value that cannot
            // be read as a number is a configuration error, and staying quiet about it would let
            // libcurl coerce it to 0 and switch both the pre-flight and this counter off unnoticed.
            if (!array_key_exists($key, $curlOptions)) {
                continue;
            }
            $value = $curlOptions[$key];
            if ($value === null || $value === false) {
                continue;
            }
            // A ceiling coming from env/JSON/YAML config arrives as a numeric string or float, and
            // libcurl accepts both for its own pre-flight, so those stay valid here as well.
            if (!is_numeric($value)) {
                throw new RequestException(sprintf(
                    'Option %s must be numeric to cap the response size, got %s; the request was not sent.',
                    $constant,
                    self::renderRefusedLimit($value)
                ), $request);
            }
            $bytes = (int) $value;
            // A numeric value below one byte truncates to zero, which is neither a ceiling nor one of
            // the values that mean "no ceiling"; accepting it would drop the counter without a word.
            if ($bytes === 0 && (float) $value !== 0.0) {
                throw new RequestException(sprintf(
                    'Option %s must be a positive whole number of bytes to cap the response size, got %s; '
                    . 'the request was not sent.',
                    $constant,
                    self::renderRefusedLimit($value)
                ), $request);
            }
            if ($bytes > 0 && ($limit === null || $bytes < $limit)) {
                $limit = $bytes;
            }
        }

        return $limit;
    }

    // Both transports reach the same verdict from the same three inputs, so it lives here rather than
    // in each of them: the two copies this replaces had already drifted apart once.
    /** @return array{errno: int, error: string, exceeded: bool} */
    public static function verdict(?int $limit, bool $callbackAborted, int $errno, string $curlError): array
    {
        // Fail closed: a truncated body must never reach the consumer as a successful response, not
        // even if libcurl were to report no error after the write callback aborted the transfer.
        if ($callbackAborted && $errno === 0) {
            $errno = CURLE_WRITE_ERROR;
        }

        if ($limit !== null && ($callbackAborted || $errno === CURLE_FILESIZE_EXCEEDED)) {
            return [
                'errno' => $errno,
                'error' => sprintf('Maximum response size of %d bytes exceeded.', $limit),
                'exceeded' => true,
            ];
        }

        return ['errno' => $errno, 'error' => $curlError, 'exceeded' => false];
    }

    // Such a value comes from config and can be long or carry CR/LF, so only a bounded single-line
    // preview reaches the message; a raw one could forge a second log record.
    private static function renderRefusedLimit(mixed $value): string
    {
        $rendered = is_scalar($value) ? var_export($value, true) : get_debug_type($value);
        $rendered = strtr($rendered, ["\r" => ' ', "\n" => ' ']);

        return strlen($rendered) > 64 ? substr($rendered, 0, 64) . '... (truncated)' : $rendered;
    }
}
