<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Transport\ResponseSizeLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseSizeLimitTest extends TestCase
{
    private const LIMIT = 1_048_576;

    #[DataProvider('nonNumericLimitProvider')]
    public function testAPresentButNonNumericLimitIsRefusedInsteadOfDroppingTheCeiling(
        int $option,
        string $constant,
        mixed $value,
    ): void {
        try {
            ResponseSizeLimit::resolve([$option => $value], new Request('GET', '/'));
            self::fail('Expected an unreadable size ceiling to be refused.');
        } catch (RequestException $exception) {
            self::assertStringContainsString(
                sprintf('Option %s must be numeric to cap the response size', $constant),
                $exception->getMessage()
            );
            self::assertStringContainsString('the request was not sent.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, mixed}> */
    public static function nonNumericLimitProvider(): iterable
    {
        foreach (self::limitKeys() as $keyLabel => [$option, $constant]) {
            foreach ([
                'empty string' => '',
                'hex string' => '0x100000',
                'suffixed string' => '10M',
                'true' => true,
            ] as $label => $value) {
                yield $keyLabel . '/' . $label => [$option, $constant, $value];
            }
        }
    }

    public function testARefusedLimitIsRenderedOnASingleLineAndBounded(): void
    {
        $forged = "10M\r\nX-Forged-Log-Record: 1" . str_repeat('a', 200);

        try {
            ResponseSizeLimit::resolve([CURLOPT_MAXFILESIZE => $forged], new Request('GET', '/'));
            self::fail('Expected a non-numeric ceiling to be refused.');
        } catch (RequestException $exception) {
            $message = $exception->getMessage();

            // The refused value comes from config and reaches a log verbatim, so a raw CR/LF in it
            // could forge a second record and an unbounded one could flood it.
            self::assertStringNotContainsString("\r", $message);
            self::assertStringNotContainsString("\n", $message);
            self::assertStringContainsString('... (truncated)', $message);
        }
    }

    #[DataProvider('subByteLimitProvider')]
    public function testANumericLimitTruncatingToZeroIsRefusedInsteadOfDroppingTheCeiling(
        int $option,
        string $constant,
        mixed $value,
    ): void {
        try {
            ResponseSizeLimit::resolve([$option => $value], new Request('GET', '/'));
            self::fail('Expected a sub-byte size ceiling to be refused.');
        } catch (RequestException $exception) {
            self::assertStringContainsString(
                sprintf('Option %s must be a positive whole number of bytes to cap the response size', $constant),
                $exception->getMessage()
            );
            self::assertStringContainsString('the request was not sent.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, mixed}> */
    public static function subByteLimitProvider(): iterable
    {
        foreach (self::limitKeys() as $keyLabel => [$option, $constant]) {
            foreach ([
                'half a byte string' => '0.5',
                'half a byte float' => 0.5,
                'just under one byte' => '0.9',
                'negative exponent string' => '1e-3',
            ] as $label => $value) {
                yield $keyLabel . '/' . $label => [$option, $constant, $value];
            }
        }
    }

    #[DataProvider('disabledLimitProvider')]
    public function testAnExplicitlyDisabledCeilingIsNotAConfigurationError(mixed $value): void
    {
        self::assertNull(
            ResponseSizeLimit::resolve([CURLOPT_MAXFILESIZE => $value], new Request('GET', '/'))
        );
    }

    public function testAnAbsentCeilingKeyIsNotAConfigurationError(): void
    {
        self::assertNull(ResponseSizeLimit::resolve([], new Request('GET', '/')));
        self::assertNull(ResponseSizeLimit::resolve([CURLOPT_TIMEOUT => 5], new Request('GET', '/')));
    }

    /** @return iterable<string, array{mixed}> */
    public static function disabledLimitProvider(): iterable
    {
        yield 'zero int' => [0];
        yield 'zero string' => ['0'];
        yield 'zero float' => [0.0];
        // Numerically exact zeros, so they stay a disable rather than a sub-byte configuration error.
        yield 'signed zero string' => ['+0'];
        yield 'padded zero string' => [' 0 '];
        yield 'false' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('competingLimitKeyProvider')]
    public function testTheLowerOfBothLimitKeysWins(int $plainKeyBytes, int $largeKeyBytes): void
    {
        if (!defined('CURLOPT_MAXFILESIZE_LARGE')) {
            self::markTestSkipped('CURLOPT_MAXFILESIZE_LARGE has no PHP binding before 8.2.');
        }

        $resolved = ResponseSizeLimit::resolve(
            [
                CURLOPT_MAXFILESIZE => $plainKeyBytes,
                (int) constant('CURLOPT_MAXFILESIZE_LARGE') => $largeKeyBytes,
            ],
            new Request('GET', '/')
        );

        self::assertSame(self::LIMIT, $resolved);
    }

    /** @return iterable<string, array{int, int}> */
    public static function competingLimitKeyProvider(): iterable
    {
        yield 'plain key is lower' => [self::LIMIT, 64 * 1024 * 1024];
        yield 'large key is lower' => [64 * 1024 * 1024, self::LIMIT];
    }

    // The verdict is what both transports hand to RawResponse, so its own cases live here rather than
    // only behind a real transfer, where the fail-closed branch is unreachable through libcurl.
    #[DataProvider('verdictProvider')]
    public function testTheVerdictBothTransportsShare(
        ?int $limit,
        bool $callbackAborted,
        int $errno,
        string $curlError,
        int $expectedErrno,
        string $expectedError,
        bool $expectedExceeded,
    ): void {
        $verdict = ResponseSizeLimit::verdict($limit, $callbackAborted, $errno, $curlError);

        self::assertSame($expectedErrno, $verdict['errno']);
        self::assertSame($expectedError, $verdict['error']);
        self::assertSame($expectedExceeded, $verdict['exceeded']);
    }

    /** @return iterable<string, array{?int, bool, int, string, int, string, bool}> */
    public static function verdictProvider(): iterable
    {
        $message = sprintf('Maximum response size of %d bytes exceeded.', self::LIMIT);

        yield 'clean transfer' => [self::LIMIT, false, 0, '', 0, '', false];
        yield 'unrelated failure keeps its own errno and message' =>
            [self::LIMIT, false, CURLE_COULDNT_CONNECT, 'connect refused', CURLE_COULDNT_CONNECT, 'connect refused', false];
        yield 'callback abort' => [self::LIMIT, true, CURLE_WRITE_ERROR, 'write error', CURLE_WRITE_ERROR, $message, true];
        // Fail closed: libcurl reporting success after the write callback aborted must not surface a
        // truncated body as a 200, so the verdict supplies the errno libcurl withheld.
        yield 'callback abort libcurl did not report' => [self::LIMIT, true, 0, '', CURLE_WRITE_ERROR, $message, true];
        yield 'pre-flight rejection' =>
            [self::LIMIT, false, CURLE_FILESIZE_EXCEEDED, 'Maximum file size exceeded', CURLE_FILESIZE_EXCEEDED, $message, true];
        // Without a ceiling of our own an errno 63 belongs to whoever set the raw curl option, so it
        // must stay a plain transport error instead of being claimed as a library verdict.
        yield 'pre-flight rejection without a resolved ceiling' =>
            [null, false, CURLE_FILESIZE_EXCEEDED, 'Maximum file size exceeded', CURLE_FILESIZE_EXCEEDED, 'Maximum file size exceeded', false];
    }

    /** @return iterable<string, array{int, string}> */
    private static function limitKeys(): iterable
    {
        yield 'plain key' => [CURLOPT_MAXFILESIZE, 'CURLOPT_MAXFILESIZE'];
        // CURLOPT_MAXFILESIZE_LARGE has no PHP binding before 8.2; dereferencing it bare would abort
        // the whole run during data-provider collection instead of skipping the case.
        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            yield 'large key' => [(int) constant('CURLOPT_MAXFILESIZE_LARGE'), 'CURLOPT_MAXFILESIZE_LARGE'];
        }
    }
}
