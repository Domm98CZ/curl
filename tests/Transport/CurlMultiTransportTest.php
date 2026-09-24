<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

require_once __DIR__ . '/TransportFunctionHarness.php';

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\CurlOptionsMapperInterface;
use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\OptionsInjectingMultiTransport;
use Domm98CZ\Curl\Tests\Fixtures\ExpectContinue;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use Domm98CZ\Curl\Transport\InterceptsTransportFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class CurlMultiTransportTest extends TestCase
{
    use InterceptsTransportFunctions;

    private static TestServer $server;
    private MultiTransportInterface $transport;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8100);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->transport = new BoundedMultiTransport(new CurlMultiTransport());
    }

    public function testSingleRequestCompletes(): void
    {
        $id = $this->transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());

        while (!$this->transport->isDone($id)) {
            $this->transport->tick();
        }

        $raw = $this->transport->takeResult($id);
        self::assertFalse($raw->isTransportError());
        self::assertSame('ok', (string) $raw->body);
    }

    public function testTwoRequestsRunConcurrentlyOnOneMultiHandle(): void
    {
        $idA = $this->transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        $idB = $this->transport->add(new Request('GET', self::$server->baseUrl . '/status/201'), new RequestOptions());

        while (!$this->transport->isDone($idA) || !$this->transport->isDone($idB)) {
            $this->transport->tick();
        }

        self::assertSame('ok', (string) $this->transport->takeResult($idA)->body);
        self::assertSame('status-201', (string) $this->transport->takeResult($idB)->body);
    }

    public function testHandleIdsAreNeverReissuedAfterResultConsumption(): void
    {
        $requestA = new Request('GET', self::$server->baseUrl . '/ok');
        $requestB = new Request('GET', self::$server->baseUrl . '/status/201');
        $optionsA = new RequestOptions();
        $optionsB = new RequestOptions();

        $idA = $this->transport->add($requestA, $optionsA);
        while (!$this->transport->isDone($idA)) {
            $this->transport->tick();
        }
        $this->transport->takeResult($idA);
        $idB = $this->transport->add($requestB, $optionsB);

        self::assertNotSame($idA, $idB);
        $this->transport->release($idB);
    }

    public function testDuplicateHandleIdGuardRejectsAReflectedCounterCollision(): void
    {
        $transport = new CurlMultiTransport();
        $issuedId = $transport->add(
            new Request('GET', self::$server->baseUrl . '/slow'),
            new RequestOptions(),
        );
        $nextHandleId = new \ReflectionProperty($transport, 'nextHandleId');
        $nextHandleId->setValue($transport, $issuedId);
        $caught = null;

        try {
            $transport->add(
                new Request('GET', self::$server->baseUrl . '/ok'),
                new RequestOptions(),
            );
        } catch (ClientException $exception) {
            $caught = $exception;
        } finally {
            $transport->release($issuedId);
        }

        self::assertInstanceOf(ClientException::class, $caught);
        self::assertStringContainsString('was already issued', $caught->getMessage());
    }

    public function testHandleEvidenceExistsBeforeTheEasyHandleIsAttached(): void
    {
        $transport = new CurlMultiTransport();
        $registeredAtAttach = false;
        $interceptor = $this->interceptTransportFunctions()->observeMultiAdd(
            static function (\CurlHandle $easyHandle) use ($transport, &$registeredAtAttach): void {
                $handleIds = (new \ReflectionProperty($transport, 'handleIds'))->getValue($transport);
                $handles = (new \ReflectionProperty($transport, 'handles'))->getValue($transport);
                $id = is_array($handleIds) ? ($handleIds[(int) $easyHandle] ?? null) : null;
                $registeredAtAttach = is_int($id)
                    && is_array($handles)
                    && isset($handles[$id])
                    && ($handles[$id]['easyHandle'] ?? null) === $easyHandle;
            },
        );

        $id = $interceptor->whileArmed(static fn (): int => $transport->add(
            new Request('GET', self::$server->baseUrl . '/slow'),
            new RequestOptions(),
        ));

        self::assertTrue($registeredAtAttach);
        $transport->release($id);
    }

    public function testEvidenceIsDiscardedWhenAttachingTheHandleThrows(): void
    {
        $transport = new CurlMultiTransport();
        $failure = new RuntimeException('attach failed');
        $easyHandleId = null;
        $interceptor = $this->interceptTransportFunctions()->observeMultiAdd(
            static function (\CurlHandle $easyHandle) use ($failure, &$easyHandleId): void {
                $easyHandleId = (int) $easyHandle;
                throw $failure;
            },
        );
        $caught = null;

        try {
            $interceptor->whileArmed(static fn (): int => $transport->add(
                new Request('GET', self::$server->baseUrl . '/slow'),
                new RequestOptions(),
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);
        self::assertIsInt($easyHandleId);
        self::assertSame([], (new \ReflectionProperty($transport, 'handleIds'))->getValue($transport));
        self::assertSame([], (new \ReflectionProperty($transport, 'handles'))->getValue($transport));
    }

    public function testEvidenceIsDiscardedWhenAttachingTheHandleIsRefused(): void
    {
        $transport = new CurlMultiTransport();
        $interceptor = $this->interceptTransportFunctions()->refuseNextMultiAdd(CURLM_BAD_HANDLE);
        $caught = null;

        try {
            $interceptor->whileArmed(static fn (): int => $transport->add(
                new Request('GET', self::$server->baseUrl . '/slow'),
                new RequestOptions(),
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RequestException::class, $caught);
        self::assertStringContainsString('curl_multi_add_handle()', $caught->getMessage());
        self::assertSame([], (new \ReflectionProperty($transport, 'handleIds'))->getValue($transport));
        self::assertSame([], (new \ReflectionProperty($transport, 'handles'))->getValue($transport));
    }

    public function testFinishedResultSurvivesAnotherAddBeforeConsumption(): void
    {
        $requestA = new Request('GET', self::$server->baseUrl . '/ok');
        $requestB = new Request('GET', self::$server->baseUrl . '/status/201');
        $optionsA = new RequestOptions();
        $optionsB = new RequestOptions();

        $idA = $this->transport->add($requestA, $optionsA);
        while (!$this->transport->isDone($idA)) {
            $this->transport->tick();
        }
        $idB = $this->transport->add($requestB, $optionsB);
        $resultA = $this->transport->takeResult($idA);

        self::assertSame('ok', (string) $resultA->body);
        self::assertSame(200, $resultA->info['http_code'] ?? null);
        self::assertStringContainsString("X-Test: 1\r\n", $resultA->headerRaw);
        $this->transport->release($idB);
    }

    public function testUnconsumedResultSurvivesManySubsequentTransfers(): void
    {
        $parkedRequest = new Request('GET', self::$server->baseUrl . '/ok');
        $parkedOptions = new RequestOptions();
        $laterRequests = [];
        $laterOptions = [];
        for ($i = 0; $i < 20; $i++) {
            $laterRequests[] = new Request('GET', self::$server->baseUrl . '/status/201');
            $laterOptions[] = new RequestOptions();
        }

        $parkedId = $this->transport->add($parkedRequest, $parkedOptions);
        while (!$this->transport->isDone($parkedId)) {
            $this->transport->tick();
        }
        foreach ($laterRequests as $index => $request) {
            $id = $this->transport->add($request, $laterOptions[$index]);
            while (!$this->transport->isDone($id)) {
                $this->transport->tick();
            }
            self::assertSame('status-201', (string) $this->transport->takeResult($id)->body);
        }

        self::assertSame('ok', (string) $this->transport->takeResult($parkedId)->body);
    }

    public function testReleaseWithConsumedIdDoesNotAffectRunningTransfer(): void
    {
        $requestA = new Request('GET', self::$server->baseUrl . '/ok');
        $requestB = new Request('GET', self::$server->baseUrl . '/slow');
        $optionsA = new RequestOptions();
        $optionsB = new RequestOptions();

        $idA = $this->transport->add($requestA, $optionsA);
        while (!$this->transport->isDone($idA)) {
            $this->transport->tick();
        }
        $this->transport->takeResult($idA);
        $idB = $this->transport->add($requestB, $optionsB);
        $this->transport->release($idA);
        while (!$this->transport->isDone($idB)) {
            $this->transport->tick();
        }

        self::assertSame('slow-ok', (string) $this->transport->takeResult($idB)->body);
    }

    public function testTakeResultRemovesTheHandleFromTracking(): void
    {
        $id = $this->transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        while (!$this->transport->isDone($id)) {
            $this->transport->tick();
        }
        $this->transport->takeResult($id);

        $this->expectException(\RuntimeException::class);
        $this->transport->takeResult($id);
    }

    public function testIsDoneRejectsAnUnknownHandle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->transport->isDone(42);
    }

    public function testIsDoneRejectsAConsumedHandle(): void
    {
        $id = $this->transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        while (!$this->transport->isDone($id)) {
            $this->transport->tick();
        }
        $this->transport->takeResult($id);

        $this->expectException(RuntimeException::class);
        $this->transport->isDone($id);
    }

    public function testAnUnknownOrConsumedHandleIsCallerMisuseAndNotATransportFailure(): void
    {
        $consumedId = $this->transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        while (!$this->transport->isDone($consumedId)) {
            $this->transport->tick();
        }
        $this->transport->takeResult($consumedId);
        $rejections = [
            'isDone(unknown)' => fn (): bool => $this->transport->isDone(987_654),
            'takeResult(unknown)' => fn (): RawResponse => $this->transport->takeResult(987_654),
            'isDone(consumed)' => fn (): bool => $this->transport->isDone($consumedId),
            'takeResult(consumed)' => fn (): RawResponse => $this->transport->takeResult($consumedId),
        ];

        foreach ($rejections as $label => $call) {
            try {
                $call();
                self::fail(sprintf('Expected %s to reject the handle.', $label));
            } catch (RuntimeException $exception) {
                // PoolInterface promises ClientExceptionInterface for transport and protocol
                // failures only; these four are unreachable without caller misuse and must stay
                // outside that hierarchy so a PSR-18 consumer cannot swallow them as a failed request.
                self::assertSame(RuntimeException::class, $exception::class, $label);
                self::assertNotInstanceOf(ClientExceptionInterface::class, $exception, $label);
            }
        }
    }

    public function testAsyncReadFailureIsWrappedAsARequestException(): void
    {
        $failure = new RuntimeException('read failed');
        $collector = new TransferInfoCollector();
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willThrowException($failure);
        $request = (new Request('POST', self::$server->baseUrl . '/echo'))
            ->withBody($body);
        $client = new AsyncClient($this->transport);
        $failingPromise = $client->sendAsync($request, new RequestOptions([ExpectContinue::doNotWait()], $collector));
        $survivingPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $failingPromise->wait();
            self::fail('Expected reading the request body to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertGreaterThanOrEqual(0.0, $collector->get()->totalTimeMs);
        self::assertSame('status-201', (string) $survivingPromise->wait()->getBody());
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    public function testBufferedWriteFailureRejectsOnlyItsOwnTransferAndKeepsItsCause(): void
    {
        $failure = new RuntimeException('buffered write failed');
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner, deadlineSeconds: 10.0);
        $failingId = $transport->add(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions(),
        );
        $healthyId = $transport->add(
            new Request('GET', self::$server->baseUrl . '/status/201'),
            new RequestOptions(),
        );
        $handles = (new \ReflectionProperty($inner, 'handles'))->getValue($inner);
        self::assertIsArray($handles);
        $failingEntry = $handles[$failingId] ?? null;
        self::assertIsArray($failingEntry);
        $bodyResource = $failingEntry['bodyResource'] ?? null;
        self::assertIsResource($bodyResource);
        $interceptor = $this->interceptTransportFunctions()->failWritesTo($bodyResource, $failure);

        $interceptor->whileArmed(static function () use ($transport, $failingId, $healthyId): void {
            while (!$transport->isDone($failingId) || !$transport->isDone($healthyId)) {
                $transport->tick();
            }
        });

        $caught = null;
        try {
            $transport->takeResult($failingId);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ResponseBufferException::class, $caught);
        self::assertInstanceOf(ClientExceptionInterface::class, $caught);
        self::assertSame($failure, $caught->getPrevious());
        self::assertSame('status-201', (string) $transport->takeResult($healthyId)->body);
    }

    public function testAsyncReadFailureDoesNotRejectAHealthySiblingWaitedFirst(): void
    {
        $failure = new RuntimeException('read failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willThrowException($failure);
        $request = (new Request('POST', self::$server->baseUrl . '/echo'))
            ->withBody($body);
        $client = new AsyncClient($this->transport);
        $failingPromise = $client->sendAsync($request, new RequestOptions([ExpectContinue::doNotWait()]));
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected reading the request body to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    #[DataProvider('concurrentReadFailureProvider')]
    public function testEveryConcurrentReadFailureRejectsItsOwnPromise(int $successfulReadsBeforeFailure): void
    {
        $client = new AsyncClient($this->transport);
        $failures = [];
        $promises = [];

        for ($i = 0; $i < 3; $i++) {
            $failure = new RuntimeException(sprintf('read failed for body %d', $i));
            $failures[] = $failure;
            $body = $this->createStub(StreamInterface::class);
            $body->method('getSize')->willReturn(null);
            $body->method('isSeekable')->willReturn(false);
            $body->method('eof')->willReturn(false);
            $reads = 0;
            $body->method('read')->willReturnCallback(
                static function (int $length) use (&$reads, $successfulReadsBeforeFailure, $failure): string {
                    if ($reads++ >= $successfulReadsBeforeFailure) {
                        throw $failure;
                    }

                    return str_repeat('x', min(4096, $length));
                }
            );
            $promises[] = $client->sendAsync(
                (new Request('POST', self::$server->baseUrl . '/echo'))->withBody($body),
                new RequestOptions([ExpectContinue::doNotWait()]),
            );
        }

        foreach ($promises as $index => $promise) {
            try {
                $response = $promise->wait();
                self::assertNotInstanceOf(ResponseInterface::class, $response);
                self::fail(sprintf('Expected request body %d to fail.', $index));
            } catch (RequestException $exception) {
                self::assertSame($failures[$index], $exception->getPrevious());
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function concurrentReadFailureProvider(): iterable
    {
        yield 'first read' => [0];
        yield 'second read after 4096 bytes' => [1];
    }

    #[DataProvider('transferCallbackOptionProvider')]
    public function testThrowingUserTransferCallbackIsQuarantined(int $callbackOption): void
    {
        $failure = new RuntimeException('user transfer callback failed');
        $transport = new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0);
        $client = new AsyncClient($transport);
        $failingPromise = RequestBuilder::get(self::$server->baseUrl . '/slow')
            ->withCurlOption(CURLOPT_NOPROGRESS, false)
            ->withCurlOption($callbackOption, static function () use ($failure): int {
                throw $failure;
            })
            ->sendAsync($client);
        $healthyA = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'));
        $healthyB = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $response = $failingPromise->wait();
            self::assertNotInstanceOf(ResponseInterface::class, $response);
            self::fail('Expected the transfer callback to reject its promise.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('ok', (string) $healthyA->wait()->getBody());
        self::assertSame('status-201', (string) $healthyB->wait()->getBody());
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    /** @return iterable<string, array{int}> */
    public static function transferCallbackOptionProvider(): iterable
    {
        foreach (['CURLOPT_PROGRESSFUNCTION', 'CURLOPT_XFERINFOFUNCTION'] as $constant) {
            if (defined($constant)) {
                yield $constant => [(int) constant($constant)];
            }
        }
    }

    public function testNonThrowingUserProgressCallbackRemainsTransparent(): void
    {
        $calls = 0;
        $transport = new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0);
        $client = new AsyncClient($transport);
        $progressPromise = RequestBuilder::get(self::$server->baseUrl . '/slow')
            ->withCurlOption(CURLOPT_NOPROGRESS, false)
            ->withCurlOption(CURLOPT_PROGRESSFUNCTION, static function () use (&$calls): int {
                $calls++;

                return 0;
            })
            ->sendAsync($client);
        $healthyA = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'));
        $healthyB = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/202'));

        self::assertSame('slow-ok', (string) $progressPromise->wait()->getBody());
        self::assertSame('ok', (string) $healthyA->wait()->getBody());
        self::assertSame('status-202', (string) $healthyB->wait()->getBody());
        self::assertGreaterThan(0, $calls);
    }

    public function testUserReadCallbackRemainsQuarantinedWithConcurrentTransfers(): void
    {
        $failure = new RuntimeException('user read callback failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0));
        $failing = RequestBuilder::post(self::$server->baseUrl . '/echo')
            ->withBody($body)
            ->withOption(ExpectContinue::doNotWait())
            ->withCurlOption(CURLOPT_READFUNCTION, static function () use ($failure): string {
                throw $failure;
            })
            ->sendAsync($client);
        $healthyA = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'));
        $healthyB = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/202'));

        try {
            $failing->wait();
            self::fail('Expected the read callback to reject its promise.');
        } catch (RequestException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame('ok', (string) $healthyA->wait()->getBody());
        self::assertSame('status-202', (string) $healthyB->wait()->getBody());
    }

    public function testSizedBodyReadFailureStopsTheTransferWithinTheDeadline(): void
    {
        $failure = new RuntimeException('sized body read failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 8_192);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $reads = 0;
        $body->method('read')->willReturnCallback(
            static function (int $length) use (&$reads, $failure): string {
                if ($reads++ === 0) {
                    return str_repeat('x', min(4_096, $length));
                }

                throw $failure;
            },
        );
        $request = (new Request('POST', self::$server->baseUrl . '/echo?known-length=1'))->withBody($body);
        $client = new AsyncClient(new BoundedMultiTransport(
            new CurlMultiTransport(),
            deadlineSeconds: 2.0,
        ));
        $failing = $client->sendAsync($request, new RequestOptions([ExpectContinue::doNotWait()]));
        $healthyA = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'));
        $healthyB = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/202'));
        $startedAt = microtime(true);

        try {
            $failing->wait();
            self::fail('Expected the sized request body read to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertLessThan(2.5, microtime(true) - $startedAt);
        self::assertSame('ok', (string) $healthyA->wait()->getBody());
        self::assertSame('status-202', (string) $healthyB->wait()->getBody());
    }

    #[DataProvider('invalidReadCallbackResultProvider')]
    public function testNonStringUserReadCallbackIsNormalizedToEofWithoutEscapingPool(\Closure $callback): void
    {
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 8_192);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $pool = new Pool(new OptionsInjectingMultiTransport(
            new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 2.0),
            ['/echo' => new RequestOptions([
                ExpectContinue::doNotWait(),
                new RawCurlOption(CURLOPT_READFUNCTION, $callback),
            ])],
        ));

        $results = $pool->send([
            'failing' => (new Request('POST', self::$server->baseUrl . '/echo?custom-read=1'))->withBody($body),
            'healthy-a' => new Request('GET', self::$server->baseUrl . '/ok'),
            'healthy-b' => new Request('GET', self::$server->baseUrl . '/status/202'),
        ]);

        self::assertInstanceOf(NetworkException::class, $results['failing']);
        self::assertSame(CURLE_READ_ERROR, $results['failing']->getCurlErrno());
        self::assertStringNotContainsString('CurlMultiTransport.php', $results['failing']->getMessage());
        self::assertInstanceOf(ResponseInterface::class, $results['healthy-a']);
        self::assertSame('ok', (string) $results['healthy-a']->getBody());
        self::assertInstanceOf(ResponseInterface::class, $results['healthy-b']);
        self::assertSame('status-202', (string) $results['healthy-b']->getBody());
    }

    /** @return iterable<string, array{\Closure}> */
    public static function invalidReadCallbackResultProvider(): iterable
    {
        yield 'integer' => [static fn () => 1];
        yield 'null' => [static fn () => null];
    }

    // An unknown-length body is sent chunked, so there is no announced size for libcurl to hold the
    // read callback to. A callback that ends early therefore terminates the chunked stream cleanly and
    // the truncation is indistinguishable from a body that was genuinely that short: the transfer
    // succeeds. That is a residual limitation of an unknown-length body, pinned here so it stays visible.
    #[DataProvider('invalidReadCallbackResultProvider')]
    public function testUnknownLengthBodyTruncatedByTheUserReadCallbackIsNotDetected(\Closure $callback): void
    {
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 5.0));

        $response = RequestBuilder::post(self::$server->baseUrl . '/echo-request?chunked-read=1')
            ->withBody($body)
            ->withOption(ExpectContinue::doNotWait())
            ->withCurlOption(CURLOPT_READFUNCTION, $callback)
            ->sendAsync($client)
            ->wait();

        $received = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('chunked', strtolower($received['transferEncoding']));
        self::assertSame('', $received['body']);
    }

    // Regression guard for SEC-216 on the multi path: a streamed POST whose body stops short of the
    // announced size used to keep its handle attached until the process was killed. The deadline and
    // the elapsed-time bound are part of the assertion, not decoration.
    public function testStreamedPostShorterThanItsAnnouncedSizeFailsFastInsteadOfHanging(): void
    {
        $reads = 0;
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 8_192);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnCallback(static function () use (&$reads): bool {
            return $reads > 0;
        });
        $body->method('read')->willReturnCallback(static function (int $length) use (&$reads): string {
            $reads++;
            return str_repeat('x', min(4_096, $length));
        });
        $request = (new Request('POST', self::$server->baseUrl . '/echo?short-sized=1'))->withBody($body);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 5.0));
        $promise = $client->sendAsync($request, new RequestOptions([ExpectContinue::doNotWait()]));
        $healthy = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'));
        $startedAt = microtime(true);

        try {
            $promise->wait();
            self::fail('Expected a body shorter than its announced size to fail the transfer.');
        } catch (NetworkException $exception) {
            self::assertSame(CURLE_READ_ERROR, $exception->getCurlErrno());
        }

        self::assertLessThan(3.0, microtime(true) - $startedAt);
        self::assertSame('ok', (string) $healthy->wait()->getBody());
    }

    public function testEscapedCallbackFailureFinishesAndSanitizesEveryAttachedTransfer(): void
    {
        $ownedFailure = new RuntimeException('owned callback failure');
        $requestA = new Request(
            'POST',
            str_replace('http://', 'http://alice:secret@', self::$server->baseUrl) . '/echo?token=request-a',
        );
        $requestB = new Request('GET', self::$server->baseUrl . '/slow?request=b');
        $foreignFailure = new class ('foreign request A token=request-a') extends RuntimeException {
        };
        $foreignType = get_debug_type($foreignFailure);
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $requestA = $requestA->withBody($body);
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner, deadlineSeconds: 2.0);
        $idA = $transport->add($requestA, new RequestOptions([ExpectContinue::doNotWait()]));
        $idB = $transport->add($requestB, new RequestOptions());

        $property = new \ReflectionProperty($inner, 'handles');
        $handles = $property->getValue($inner);
        self::assertIsArray($handles);
        $entryA = $handles[$idA] ?? null;
        self::assertIsArray($entryA);
        $handleA = $entryA['easyHandle'] ?? null;
        self::assertInstanceOf(\CurlHandle::class, $handleA);
        $recordedFailure = null;
        $entryA['callbackFailure'] = static function () use (&$recordedFailure): ?\Throwable {
            return $recordedFailure;
        };
        $handles[$idA] = $entryA;
        $property->setValue($inner, $handles);
        curl_setopt($handleA, CURLOPT_READFUNCTION, static function () use (&$recordedFailure, $ownedFailure, $foreignFailure): string {
            $recordedFailure = $ownedFailure;
            throw $foreignFailure;
        });

        while (!$transport->isDone($idA) || !$transport->isDone($idB)) {
            $transport->tick();
        }

        self::assertTrue($transport->isDone($idA));
        self::assertTrue($transport->isDone($idB));
        try {
            $transport->takeResult($idA);
            self::fail('Expected transfer A to preserve its recorded failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($ownedFailure, $exception);
        }
        try {
            $transport->takeResult($idB);
            self::fail('Expected transfer B to be interrupted.');
        } catch (NetworkException $exception) {
            self::assertSame($requestB, $exception->getRequest());
            self::assertSame((string) $requestB->getUri(), (string) $exception->getRequest()->getUri());
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString($foreignType, $exception->getMessage());
            self::assertStringNotContainsString('request-a', $exception->getMessage());
            self::assertStringNotContainsString('alice', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString("\0", $exception->getMessage());
            self::assertStringNotContainsString(basename(__FILE__), $exception->getMessage());
        }
    }

    #[DataProvider('tierTwoCallbackOptionProvider')]
    public function testTierTwoCallbackIsQuarantinedAcrossConcurrentTransfers(int $callbackOption): void
    {
        $requestA = new Request('GET', self::$server->baseUrl . '/slow?tier-two=a');
        $requestB = new Request('GET', self::$server->baseUrl . '/slow?tier-two=b');
        $uriA = (string) $requestA->getUri();
        $uriB = (string) $requestB->getUri();
        $failureA = new RuntimeException('tier two callback A failed');
        $failureB = new RuntimeException('tier two callback B failed');
        $mapper = new class($callbackOption, [$uriA => $failureA, $uriB => $failureB]) implements CurlOptionsMapperInterface {
            private readonly CurlOptionsMapper $mapper;

            /** @var array<string, \Closure> */
            private array $callbacks = [];

            /** @param array<string, \Throwable> $failures */
            public function __construct(
                private readonly int $callbackOption,
                private readonly array $failures,
            ) {
                $this->mapper = new CurlOptionsMapper();
            }

            public function map(RequestInterface $request, OptionsInterface $options): array
            {
                $uri = (string) $request->getUri();
                $failure = $this->failures[$uri];
                $this->callbacks[$uri] = static function () use ($failure): int {
                    throw $failure;
                };
                $curlOptions = $this->mapper->map($request, $options);
                $curlOptions[$this->callbackOption] =& $this->callbacks[$uri];

                return $curlOptions;
            }

            public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
            {
                return $this->mapper->replayabilityOf($request, $options);
            }

            public function callbackFor(string $uri): \Closure
            {
                return $this->callbacks[$uri];
            }
        };
        $transport = new BoundedMultiTransport(new CurlMultiTransport($mapper), deadlineSeconds: 2.0);
        $idA = $transport->add($requestA, new RequestOptions());
        $idB = $transport->add($requestB, new RequestOptions());

        self::assertSame(1, ($mapper->callbackFor($uriA))());
        self::assertSame(1, ($mapper->callbackFor($uriB))());
        while (!$transport->isDone($idA) || !$transport->isDone($idB)) {
            $transport->tick();
        }

        try {
            $transport->takeResult($idA);
            self::fail('Expected Tier 2 callback A to reject its transfer.');
        } catch (RuntimeException $exception) {
            self::assertSame($failureA, $exception);
        }
        try {
            $transport->takeResult($idB);
            self::fail('Expected Tier 2 callback B to reject its transfer.');
        } catch (RuntimeException $exception) {
            self::assertSame($failureB, $exception);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function tierTwoCallbackOptionProvider(): iterable
    {
        foreach ([
            'CURLOPT_DEBUGFUNCTION',
            'CURLOPT_FNMATCH_FUNCTION',
            'CURLOPT_PREREQFUNCTION',
            'CURLOPT_SSH_HOSTKEYFUNCTION',
        ] as $constant) {
            if (defined($constant)) {
                yield $constant => [(int) constant($constant)];
            }
        }
    }

    public function testFinishReleasesEasyHandleReferenceBeforeResultConsumption(): void
    {
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner, deadlineSeconds: 10.0);
        $id = $transport->add(
            new Request('GET', self::$server->baseUrl . '/ok?finished-handle=1'),
            new RequestOptions(),
        );
        $handlesProperty = new \ReflectionProperty($inner, 'handles');
        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        $entry = $handles[$id] ?? null;
        self::assertIsArray($entry);
        $handle = $entry['easyHandle'] ?? null;
        self::assertInstanceOf(\CurlHandle::class, $handle);
        $objectId = (int) $handle;

        while (!$transport->isDone($id)) {
            $transport->tick();
        }

        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        $entry = $handles[$id] ?? null;
        self::assertIsArray($entry);
        self::assertArrayHasKey('easyHandle', $entry);
        self::assertNull($entry['easyHandle']);
        $handleIdsProperty = new \ReflectionProperty($inner, 'handleIds');
        $handleIds = $handleIdsProperty->getValue($inner);
        self::assertIsArray($handleIds);
        self::assertArrayNotHasKey($objectId, $handleIds);
        self::assertSame('ok', (string) $transport->takeResult($id)->body);
    }

    public function testReleaseIsIdempotentAndIgnoresUnknownHandles(): void
    {
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner);
        $id = $transport->add(
            new Request('GET', self::$server->baseUrl . '/slow'),
            new RequestOptions(),
        );
        $handlesProperty = new \ReflectionProperty($inner, 'handles');
        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        $entry = $handles[$id] ?? null;
        self::assertIsArray($entry);
        $handle = $entry['easyHandle'] ?? null;
        self::assertInstanceOf(\CurlHandle::class, $handle);
        $objectId = (int) $handle;

        $transport->release(987_654);
        $transport->release($id);
        $transport->release($id);

        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        self::assertArrayNotHasKey($id, $handles);
        $handleIdsProperty = new \ReflectionProperty($inner, 'handleIds');
        $handleIds = $handleIdsProperty->getValue($inner);
        self::assertIsArray($handleIds);
        self::assertArrayNotHasKey($objectId, $handleIds);
        $client = new AsyncClient($transport);
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody(),
        );
    }

    public function testDroppingAnUnconsumedPromiseRemovesItsTransportState(): void
    {
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner, deadlineSeconds: 10.0);
        $client = new AsyncClient($transport);
        $promise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/slow'));
        $handlesProperty = new \ReflectionProperty($inner, 'handles');
        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        $ids = array_keys($handles);
        self::assertCount(1, $ids);
        $id = $ids[0];
        $entry = $handles[$id];
        $handle = $entry['easyHandle'] ?? null;
        self::assertInstanceOf(\CurlHandle::class, $handle);
        $objectId = (int) $handle;

        unset($promise);
        gc_collect_cycles();

        $handles = $handlesProperty->getValue($inner);
        self::assertIsArray($handles);
        self::assertArrayNotHasKey($id, $handles);
        $handleIdsProperty = new \ReflectionProperty($inner, 'handleIds');
        $handleIds = $handleIdsProperty->getValue($inner);
        self::assertIsArray($handleIds);
        self::assertArrayNotHasKey($objectId, $handleIds);
    }

    public function testMissingTerminalResultIsAClientException(): void
    {
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner);
        $id = $transport->add(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions(),
        );
        while (!$transport->isDone($id)) {
            $transport->tick();
        }

        $property = new \ReflectionProperty($inner, 'handles');
        $handles = $property->getValue($inner);
        self::assertIsArray($handles);
        $entry = $handles[$id] ?? null;
        self::assertIsArray($entry);
        $raw = $entry['rawResponse'] ?? null;
        self::assertInstanceOf(RawResponse::class, $raw);
        $body = $raw->body;
        $entry['rawResponse'] = null;
        $handles[$id] = $entry;
        $property->setValue($inner, $handles);

        try {
            $transport->takeResult($id);
            self::fail('Expected the missing terminal result invariant to fail.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('finished without a result', $exception->getMessage());
        } finally {
            $body->close();
        }
    }

    #[DataProvider('refusedBufferWriteResults')]
    public function testARefusedLocalResponseBufferReachesTheConsumerAsAClientException(int|false $result, string $expectedCount): void
    {
        $inner = new CurlMultiTransport();
        $transport = new BoundedMultiTransport($inner, deadlineSeconds: 10.0);
        $id = $transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        $handles = (new \ReflectionProperty($inner, 'handles'))->getValue($inner);
        self::assertIsArray($handles);
        $entry = $handles[$id] ?? null;
        self::assertIsArray($entry);
        $bodyResource = $entry['bodyResource'] ?? null;
        self::assertIsResource($bodyResource);
        // Scoped to this transfer's own buffer: an unscoped trap would also catch the sibling
        // bookkeeping writes the multi handle makes while draining.
        $interceptor = $this->interceptTransportFunctions()->refuseWritesTo($bodyResource, $result);

        $interceptor->whileArmed(static function () use ($transport, $id): void {
            while (!$transport->isDone($id)) {
                $transport->tick();
            }
        });

        $caught = null;
        try {
            $transport->takeResult($id);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ResponseBufferException::class, $caught);
        self::assertInstanceOf(ClientExceptionInterface::class, $caught);
        self::assertStringContainsString('buffer the response body locally', $caught->getMessage());
        self::assertStringContainsString($expectedCount, $caught->getMessage());
    }

    /** @return iterable<string, array{int|false, string}> */
    public static function refusedBufferWriteResults(): iterable
    {
        yield 'refused outright' => [false, 'none of'];
        yield 'no bytes accepted' => [0, '0 of'];
        yield 'short write' => [1, '1 of'];
    }
}
