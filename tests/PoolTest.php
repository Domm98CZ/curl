<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\FakeMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\OptionsInjectingMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\ScriptedMultiTransport;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PoolTest extends TestCase
{
    private static TestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8106);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private function rawResponse(int $statusCode, string $body = ''): RawResponse
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);

        return new RawResponse(0, '', sprintf("HTTP/1.1 %d X\r\n\r\n", $statusCode), new Stream($resource), []);
    }

    private function livePool(array $optionsByPath = []): Pool
    {
        return new Pool(new OptionsInjectingMultiTransport(
            new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0),
            $optionsByPath,
        ));
    }

    public function testSendReturnsResponsesInTheSameKeysAsRequests(): void
    {
        $pool = new Pool(new FakeMultiTransport($this->rawResponse(200), $this->rawResponse(201)));

        $requests = ['a' => new Request('GET', '/a'), 'b' => new Request('GET', '/b')];
        $results = $pool->send($requests);

        self::assertInstanceOf(ResponseInterface::class, $results['a']);
        self::assertSame(200, $results['a']->getStatusCode());
        self::assertInstanceOf(ResponseInterface::class, $results['b']);
        self::assertSame(201, $results['b']->getStatusCode());
    }

    public function testFailedRequestYieldsTheExceptionAtItsKeyInsteadOfThrowing(): void
    {
        $failure = new RawResponse(CURLE_COULDNT_CONNECT, 'refused', '', new Stream(fopen('php://temp', 'r+')), []);
        $pool = new Pool(new FakeMultiTransport($this->rawResponse(200), $failure));

        $results = $pool->send([new Request('GET', '/ok'), new Request('GET', '/broken')]);

        self::assertInstanceOf(ResponseInterface::class, $results[0]);
        self::assertInstanceOf(NetworkException::class, $results[1]);
    }

    public function testAllRequestsAreSentEvenWithAConcurrencyLimit(): void
    {
        $transport = new FakeMultiTransport($this->rawResponse(200), $this->rawResponse(200), $this->rawResponse(200));
        $pool = new Pool($transport);

        $requests = [new Request('GET', '/1'), new Request('GET', '/2'), new Request('GET', '/3')];
        $results = $pool->send($requests, concurrency: 1);

        self::assertSame(3, $transport->addCalls);
        self::assertCount(3, $results);
    }

    public function testConcurrencyMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Pool(new FakeMultiTransport()))->send([], concurrency: 0);
    }

    public function testSharedOptionsReachEveryTransferOfTheBatch(): void
    {
        $transport = new FakeMultiTransport($this->rawResponse(200), $this->rawResponse(200));
        $shared = new RequestOptions([new Timeout(15)]);

        (new Pool($transport))->send([new Request('GET', '/1'), new Request('GET', '/2')], options: $shared);

        self::assertSame([$shared, $shared], $transport->optionsSeen);
    }

    public function testWithoutSharedOptionsEveryTransferGetsEmptyOptions(): void
    {
        $transport = new FakeMultiTransport($this->rawResponse(200));

        (new Pool($transport))->send([new Request('GET', '/1')]);

        self::assertCount(1, $transport->optionsSeen);
        self::assertSame([], $transport->optionsSeen[0]->all());
        self::assertNull($transport->optionsSeen[0]->getStreamHandler());
    }

    #[DataProvider('unshareableOptionsProvider')]
    public function testSharedOptionsCarryingPerTransferStateAreRefusedBeforeAnythingIsSent(RequestOptions $options): void
    {
        $transport = new FakeMultiTransport($this->rawResponse(200));

        try {
            (new Pool($transport))->send([new Request('GET', '/1')], options: $options);
            self::fail('Expected the shared options to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot carry a stream handler or a transfer info collector', $exception->getMessage());
        }

        self::assertSame(0, $transport->addCalls);
    }

    /** @return iterable<string, array{RequestOptions}> */
    public static function unshareableOptionsProvider(): iterable
    {
        yield 'stream handler' => [new RequestOptions([], null, new HttpStreamHandler(onChunk: static function (string $_): void {
        }))];
        yield 'transfer info collector' => [new RequestOptions([], new TransferInfoCollector())];
    }

    // A freed slot is refilled as soon as its transfer finishes, so one slow transfer never holds
    // back the others: with durations 3,1,1,1 and two slots the third starts after the first tick.
    public function testAFinishedTransferFreesItsSlotWhileOthersAreStillRunning(): void
    {
        $transport = new ScriptedMultiTransport(3, 1, 1, 1);
        $pool = new Pool($transport);

        $results = $pool->send([
            new Request('GET', '/slow'),
            new Request('GET', '/1'),
            new Request('GET', '/2'),
            new Request('GET', '/3'),
        ], concurrency: 2);

        self::assertCount(4, $results);
        self::assertSame([0, 0, 1, 2], $transport->addedAtTick);
        self::assertSame(3, $transport->ticks);
        self::assertSame(2, max($transport->activeAtTick));
    }

    public function testWithoutAConcurrencyLimitEverythingIsInFlightAtOnce(): void
    {
        $transport = new ScriptedMultiTransport(1, 1, 1);

        (new Pool($transport))->send([new Request('GET', '/1'), new Request('GET', '/2'), new Request('GET', '/3')]);

        self::assertSame([0, 0, 0], $transport->addedAtTick);
        self::assertSame(1, $transport->ticks);
    }

    public function testNonHttpSchemesAreReportedUnderTheirKeyWithoutReachingTheTransport(): void
    {
        foreach (['file:///etc/hostname', 'gopher://example.com/x', 'dict://example.com/x', 'ftp://example.com/x'] as $uri) {
            $transport = new FakeMultiTransport();
            $pool = new Pool($transport);

            $results = $pool->send([new Request('GET', $uri)]);

            self::assertInstanceOf(ClientExceptionInterface::class, $results[0]);
            self::assertStringContainsString((string) parse_url($uri, PHP_URL_SCHEME), $results[0]->getMessage());
            self::assertSame(0, $transport->addCalls);
        }
    }

    public function testMixedBatchPreservesOrderAndContinuesAfterAnUnsupportedScheme(): void
    {
        foreach ([null, 1] as $concurrency) {
            $transport = new FakeMultiTransport($this->rawResponse(200), $this->rawResponse(202));
            $pool = new Pool($transport);

            $results = $pool->send([
                'first' => new Request('GET', '/first'),
                'invalid' => new Request('GET', 'file:///etc/hostname'),
                'last' => new Request('GET', '/last'),
            ], $concurrency);

            self::assertSame(['first', 'invalid', 'last'], array_keys($results));
            self::assertInstanceOf(ResponseInterface::class, $results['first']);
            self::assertSame(200, $results['first']->getStatusCode());
            self::assertInstanceOf(ClientExceptionInterface::class, $results['invalid']);
            self::assertInstanceOf(ResponseInterface::class, $results['last']);
            self::assertSame(202, $results['last']->getStatusCode());
            self::assertSame(2, $transport->addCalls);
        }
    }

    public function testAcceptsAnyIterableNotJustArrays(): void
    {
        $pool = new Pool(new FakeMultiTransport($this->rawResponse(200)));

        $generator = (function () {
            yield new Request('GET', '/only');
        })();

        $results = $pool->send($generator);
        self::assertCount(1, $results);
    }

    public function testCallbackFailureIsReturnedAtItsKeyAndTheWholeBatchCompletes(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $pool = $this->livePool(['/failing' => new RequestOptions([], null, $handler)]);

        $results = $pool->send([
            'first' => new Request('GET', self::$server->baseUrl . '/ok'),
            'failing' => new Request('GET', self::$server->baseUrl . '/failing'),
            'last' => new Request('GET', self::$server->baseUrl . '/status/202'),
        ]);

        self::assertSame(['first', 'failing', 'last'], array_keys($results));
        self::assertInstanceOf(ResponseInterface::class, $results['first']);
        self::assertSame('ok', (string) $results['first']->getBody());
        self::assertSame($failure, $results['failing']);
        self::assertInstanceOf(ResponseInterface::class, $results['last']);
        self::assertSame('status-202', (string) $results['last']->getBody());
    }

    public function testInvalidCallbackOptionIsReturnedAtItsBatchKey(): void
    {
        $pool = $this->livePool([
            '/invalid-callback' => new RequestOptions([new RawCurlOption(CURLOPT_READFUNCTION, 'not callable')]),
        ]);

        $results = $pool->send([
            'first' => new Request('GET', self::$server->baseUrl . '/ok'),
            'invalid' => new Request('GET', self::$server->baseUrl . '/invalid-callback'),
            'last' => new Request('GET', self::$server->baseUrl . '/status/202'),
        ]);

        self::assertSame(['first', 'invalid', 'last'], array_keys($results));
        self::assertInstanceOf(ResponseInterface::class, $results['first']);
        self::assertInstanceOf(RequestException::class, $results['invalid']);
        self::assertInstanceOf(\TypeError::class, $results['invalid']->getPrevious());
        self::assertInstanceOf(ResponseInterface::class, $results['last']);
        self::assertSame(202, $results['last']->getStatusCode());
    }

    public function testLibraryOwnedCallbackOptionIsReturnedAtItsBatchKey(): void
    {
        $pool = $this->livePool([
            '/invalid-delivery-callback' => new RequestOptions([new RawCurlOption(CURLOPT_WRITEFUNCTION, 'neni callable')]),
        ]);

        $results = $pool->send([
            'first' => new Request('GET', self::$server->baseUrl . '/ok'),
            'invalid' => new Request('GET', self::$server->baseUrl . '/invalid-delivery-callback'),
            'last' => new Request('GET', self::$server->baseUrl . '/status/202'),
        ]);

        self::assertSame(['first', 'invalid', 'last'], array_keys($results));
        self::assertInstanceOf(ResponseInterface::class, $results['first']);
        self::assertInstanceOf(RequestException::class, $results['invalid']);
        self::assertInstanceOf(ResponseInterface::class, $results['last']);
        self::assertSame(202, $results['last']->getStatusCode());
    }

    public function testErrorFromConsumerCallbackEscapesAndReleasesInFlightSiblings(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Descriptor accounting requires /proc/self/fd.');
        }

        $failure = new \TypeError('consumer callback failed');
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $transport = new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0);
        $baseline = self::openDescriptorCount();
        $pool = new Pool(new OptionsInjectingMultiTransport($transport, [
            '/failing' => new RequestOptions([], null, $handler),
        ]));

        try {
            $pool->send([
                new Request('GET', self::$server->baseUrl . '/failing'),
                new Request('GET', self::$server->baseUrl . '/slow'),
                new Request('GET', self::$server->baseUrl . '/status/202'),
            ]);
            self::fail('Expected the consumer Error to escape Pool::send().');
        } catch (\TypeError $exception) {
            self::assertSame($failure, $exception);
        }

        gc_collect_cycles();
        self::assertLessThanOrEqual($baseline, self::awaitDescriptorCountAtMost($baseline));
        $id = $transport->add(new Request('GET', self::$server->baseUrl . '/ok'), new RequestOptions());
        while (!$transport->isDone($id)) {
            $transport->tick();
        }
        self::assertSame('ok', (string) $transport->takeResult($id)->body);
    }

    private static function openDescriptorCount(): int
    {
        return count(glob('/proc/self/fd/*') ?: []);
    }

    private static function awaitDescriptorCountAtMost(int $maximum): int
    {
        $deadline = microtime(true) + 1.0;
        $iterations = 0;
        do {
            $count = self::openDescriptorCount();
            if ($count <= $maximum) {
                return $count;
            }
            usleep(1_000);
            $iterations++;
        } while ($iterations < 1_000 && microtime(true) < $deadline);

        return self::openDescriptorCount();
    }
}
