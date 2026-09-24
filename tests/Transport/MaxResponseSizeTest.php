<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Exceptions\ResponseSizeException;
use Domm98CZ\Curl\Middleware\RetryClient;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\FakeSleeper;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaxResponseSizeTest extends TestCase
{
    private const LIMIT = 1_048_576;
    private const WRITE_CHUNK_TOLERANCE = 16_384;

    private static TestServer $server;
    private static TestServer $redirectSource;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8104);
        self::$redirectSource = new TestServer(8105);
        self::$server->start();
        self::$redirectSource->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        self::$redirectSource->stop();
    }

    #[DataProvider('transferProvider')]
    public function testCompressedResponseIsStoppedAfterDecompression(
        string $path,
        bool $async,
        bool $withStreamHandler,
    ): void {
        $receivedBytes = 0;
        $builder = RequestBuilder::get(self::$server->baseUrl . $path)
            ->withMaxResponseSize(self::LIMIT);

        if ($withStreamHandler) {
            $builder = $builder->withStreamHandler(new HttpStreamHandler(
                onChunk: static function (string $chunk) use (&$receivedBytes): void {
                    $receivedBytes += strlen($chunk);
                },
            ));
        }

        try {
            if ($async) {
                $builder->sendAsync(new AsyncClient(new CurlMultiTransport()))->wait();
            } else {
                $builder->send(new Client(new CurlTransport()));
            }
            self::fail('Expected the decompressed response to exceed its configured size limit.');
        } catch (ResponseSizeException $exception) {
            self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
            self::assertSame(
                sprintf('Maximum response size of %d bytes exceeded.', self::LIMIT),
                $exception->getMessage()
            );
        }

        if ($withStreamHandler) {
            self::assertGreaterThan(0, $receivedBytes);
            self::assertLessThanOrEqual(self::LIMIT + self::WRITE_CHUNK_TOLERANCE, $receivedBytes);
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function transferProvider(): iterable
    {
        foreach ([
            'content-length' => '/gzip-bomb-content-length',
            'chunked' => '/gzip-bomb-chunked',
        ] as $framing => $path) {
            foreach (['sync' => false, 'async' => true] as $mode => $async) {
                yield $framing . '/' . $mode . '/buffered' => [$path, $async, false];
                yield $framing . '/' . $mode . '/streamed' => [$path, $async, true];
            }
        }
    }

    public function testResponseSizeExceededIsNotRetried(): void
    {
        self::$server->clearRequests();
        $sleeper = new FakeSleeper();
        $client = new RetryClient(
            new Client(new CurlTransport()),
            new MaxAttemptsRetryPolicy(maxAttempts: 3),
            new ExponentialBackoff(1, 1, jitter: false),
            $sleeper,
        );

        try {
            RequestBuilder::get(self::$server->baseUrl . '/gzip-bomb-content-length')
                ->withMaxResponseSize(self::LIMIT)
                ->send($client);
            self::fail('Expected the decompressed response to exceed its configured size limit.');
        } catch (ResponseSizeException $exception) {
            self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
        } finally {
            $attempts = self::$server->requests();
            self::assertCount(1, $attempts);
            self::assertSame('GET /gzip-bomb-content-length HTTP/1.1', $attempts[0]);
            self::assertSame([], $sleeper->sleptFor);
        }
    }

    #[DataProvider('transportAndBufferingProvider')]
    public function testResponseHeadersCountTowardTheSharedLimitWithoutGrowingTheHeaderBufferPastIt(
        bool $async,
        bool $withStreamHandler,
    ): void {
        $limit = 512;
        $request = new Request(
            'GET',
            self::$server->baseUrl . '/response-size?headers=20&headerBytes=96&bodyBytes=2',
        );
        $handler = $withStreamHandler ? new class implements StreamHandlerInterface {
            public int $headerBytes = 0;

            public function onHeaderLine(string $line): void
            {
                $this->headerBytes += strlen($line);
            }

            public function onChunk(string $chunk): void
            {
            }
        } : null;
        $options = new RequestOptions([new MaxResponseSize($limit)], null, $handler);

        $raw = $this->executeRaw($request, $options, $async);

        self::assertSame(CURLE_WRITE_ERROR, $raw->errno);
        self::assertSame(sprintf('Maximum response size of %d bytes exceeded.', $limit), $raw->error);
        self::assertTrue($raw->responseSizeExceeded);
        self::assertLessThanOrEqual($limit, strlen($raw->headerRaw));
        if ($handler !== null) {
            self::assertSame(strlen($raw->headerRaw), $handler->headerBytes);
        }
    }

    #[DataProvider('transportAndBufferingProvider')]
    public function testResponseHeadersAndFinalBodyShareOneLimit(bool $async, bool $withStreamHandler): void
    {
        $bodyBytes = 256;
        $request = new Request(
            'GET',
            self::$server->baseUrl . '/response-size?headers=4&headerBytes=32&bodyBytes=' . $bodyBytes,
        );
        $baseline = $this->executeRaw($request, new RequestOptions(), false);
        $headerBytes = strlen($baseline->headerRaw);
        $limit = $headerBytes + 128;
        self::assertGreaterThan($bodyBytes, $limit);
        self::assertGreaterThan($limit, $headerBytes + $bodyBytes);
        $handler = $withStreamHandler ? new class implements StreamHandlerInterface {
            public int $headerBytes = 0;
            public int $bodyBytes = 0;

            public function onHeaderLine(string $line): void
            {
                $this->headerBytes += strlen($line);
            }

            public function onChunk(string $chunk): void
            {
                $this->bodyBytes += strlen($chunk);
            }
        } : null;
        $options = new RequestOptions([new MaxResponseSize($limit)], null, $handler);

        $raw = $this->executeRaw($request, $options, $async);

        self::assertSame(CURLE_WRITE_ERROR, $raw->errno);
        self::assertSame(sprintf('Maximum response size of %d bytes exceeded.', $limit), $raw->error);
        self::assertTrue($raw->responseSizeExceeded);
        self::assertSame($headerBytes, strlen($raw->headerRaw));
        if ($handler !== null) {
            self::assertSame($headerBytes, $handler->headerBytes);
            self::assertLessThan($bodyBytes, $handler->bodyBytes);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function transportAndBufferingProvider(): iterable
    {
        foreach (['sync' => false, 'async' => true] as $mode => $async) {
            yield $mode . '/buffered' => [$async, false];
            yield $mode . '/streamed' => [$async, true];
        }
    }

    // Both transports resolve the ceiling through the same Transport\ResponseSizeLimit, so the value
    // matrix is a unit concern; what stays here is that a coerced value still aborts a real transfer.
    #[DataProvider('rawLimitProvider')]
    public function testANonIntegerOrLargeLimitStillStopsADecompressedBomb(int $option, mixed $value): void
    {
        $builder = RequestBuilder::get(self::$server->baseUrl . '/gzip-bomb-content-length')
            ->withOption(new RawCurlOption($option, $value));

        try {
            $builder->send(new Client(new CurlTransport()));
            self::fail('Expected the decompressed response to exceed its configured size limit.');
        } catch (ResponseSizeException $exception) {
            self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
            self::assertSame(
                sprintf('Maximum response size of %d bytes exceeded.', self::LIMIT),
                $exception->getMessage()
            );
        }
    }

    /** @return iterable<string, int> */
    private static function limitKeys(): iterable
    {
        yield 'plain key' => CURLOPT_MAXFILESIZE;
        // CURLOPT_MAXFILESIZE_LARGE has no PHP binding before 8.2; dereferencing it bare would abort
        // the whole run during data-provider collection instead of skipping the case.
        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            yield 'large key' => (int) constant('CURLOPT_MAXFILESIZE_LARGE');
        }
    }

    /** @return iterable<string, array{int, mixed}> */
    public static function rawLimitProvider(): iterable
    {
        foreach (self::limitKeys() as $keyLabel => $option) {
            yield $keyLabel . '/int' => [$option, self::LIMIT];
            yield $keyLabel . '/numeric string' => [$option, (string) self::LIMIT];
        }

        yield 'plain key/float' => [CURLOPT_MAXFILESIZE, (float) self::LIMIT];
    }

    #[DataProvider('refusedLimitProvider')]
    public function testARefusedCeilingReachesTheCallerOfSendInsteadOfTheWire(mixed $value, string $reason): void
    {
        $builder = RequestBuilder::get(self::$server->baseUrl . '/ok')
            ->withOption(new RawCurlOption(CURLOPT_MAXFILESIZE, $value));

        try {
            $builder->send(new Client(new CurlTransport()));
            self::fail('Expected the unusable size ceiling to be refused.');
        } catch (RequestException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringContainsString('the request was not sent.', $exception->getMessage());
        }
    }

    #[DataProvider('refusedLimitProvider')]
    public function testARefusedCeilingIsThrownBySendAsyncInsteadOfRejectingThePromise(
        mixed $value,
        string $reason,
    ): void {
        $builder = RequestBuilder::get(self::$server->baseUrl . '/ok')
            ->withOption(new RawCurlOption(CURLOPT_MAXFILESIZE, $value));
        $promise = null;

        try {
            $promise = $builder->sendAsync(new AsyncClient(new CurlMultiTransport()));
            self::fail('Expected the unusable size ceiling to be refused.');
        } catch (RequestException $exception) {
            // AsyncClient::sendAsync() adds the handle outside the promise, so a caller that never
            // calls wait() must still see this refusal.
            self::assertNull($promise);
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringContainsString('the request was not sent.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refusedLimitProvider(): iterable
    {
        yield 'non-numeric' => ['10M', 'must be numeric to cap the response size'];
        yield 'sub-byte' => ['0.5', 'must be a positive whole number of bytes to cap the response size'];
    }

    #[DataProvider('clientModeProvider')]
    public function testAnUncompressedOversizedResponseIsRejectedByThePreFlight(bool $async): void
    {
        $this->assertTheCeilingAbortsTheTransfer(
            RequestBuilder::get(self::$server->baseUrl . '/large-plain')
                ->withMaxResponseSize(self::LIMIT),
            $async,
            CURLE_FILESIZE_EXCEEDED,
        );
    }

    #[DataProvider('preFlightMethodProvider')]
    public function testHeadIsRefusedByTheSameCeilingAsTheGetOnTheSameResource(string $method, bool $async): void
    {
        $this->assertTheCeilingAbortsTheTransfer(
            RequestBuilder::request($method, self::$server->baseUrl . '/large-plain')
                ->withMaxResponseSize(self::LIMIT),
            $async,
            CURLE_FILESIZE_EXCEEDED,
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function preFlightMethodProvider(): iterable
    {
        foreach (['HEAD', 'GET'] as $method) {
            foreach (['sync' => false, 'async' => true] as $mode => $async) {
                yield $method . '/' . $mode => [$method, $async];
            }
        }
    }

    #[DataProvider('clientModeProvider')]
    public function testACeilingStillAppliesWhenABodyTransferIsReEnabledAfterNoBody(bool $async): void
    {
        $this->assertTheCeilingAbortsTheTransfer(
            RequestBuilder::head(self::$server->baseUrl . '/gzip-bomb-content-length')
                ->withMaxResponseSize(self::LIMIT)
                ->withCurlOption(CURLOPT_HTTPGET, true),
            $async
        );
    }

    #[DataProvider('clientModeProvider')]
    public function testACeilingStillAppliesWhenARawUploadReEnablesTheBodyOfARawNoBodyRequest(bool $async): void
    {
        $this->assertTheCeilingAbortsTheTransfer(
            RequestBuilder::get(self::$server->baseUrl . '/gzip-bomb-content-length')
                ->withMaxResponseSize(self::LIMIT)
                ->withCurlOption(CURLOPT_NOBODY, true)
                ->withCurlOption(CURLOPT_UPLOAD, true)
                // Without a declared upload size libcurl waits out the Expect: 100-continue timeout
                // and reads the process stdin; the ceiling is what this case is about, not the body.
                ->withCurlOption(CURLOPT_INFILESIZE, 0),
            $async
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function clientModeProvider(): iterable
    {
        yield 'sync' => [false];
        yield 'async' => [true];
    }

    private function assertTheCeilingAbortsTheTransfer(
        RequestBuilder $builder,
        bool $async,
        int $expectedErrno = CURLE_WRITE_ERROR,
    ): void {
        try {
            if ($async) {
                $builder->sendAsync(new AsyncClient(new CurlMultiTransport()))->wait();
            } else {
                $builder->send(new Client(new CurlTransport()));
            }
            self::fail('Expected the transfer to be refused by its configured size limit.');
        } catch (ResponseSizeException $exception) {
            self::assertSame($expectedErrno, $exception->getCurlErrno());
            self::assertSame(
                sprintf('Maximum response size of %d bytes exceeded.', self::LIMIT),
                $exception->getMessage()
            );
        }
    }

    public function testABombServedAsTheFinalResponseBehindARedirectIsStopped(): void
    {
        $target = self::$server->baseUrl . '/gzip-bomb-content-length';
        $url = self::$redirectSource->baseUrl . '/redirect-to?url=' . rawurlencode($target);

        try {
            RequestBuilder::get($url)
                ->withMaxResponseSize(self::LIMIT)
                ->send(new Client(new CurlTransport()));
            self::fail('Expected the decompressed final response to exceed its configured size limit.');
        } catch (ResponseSizeException $exception) {
            self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
            self::assertSame(
                sprintf('Maximum response size of %d bytes exceeded.', self::LIMIT),
                $exception->getMessage()
            );
        }
    }

    private function executeRaw(Request $request, RequestOptions $options, bool $async): RawResponse
    {
        if (!$async) {
            return (new CurlTransport())->execute($request, $options);
        }

        $transport = new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0);
        $id = $transport->add($request, $options);
        while (!$transport->isDone($id)) {
            $transport->tick();
        }

        return $transport->takeResult($id);
    }
}
