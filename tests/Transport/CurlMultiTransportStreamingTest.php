<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Contract\TransferInfoCollectorInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class CurlMultiTransportStreamingTest extends TestCase
{
    private static TestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8103);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testTheAsyncPathStreamsChunksToo(): void
    {
        $chunks = [];
        $statuses = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            onResponse: static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        );

        $response = (new AsyncClient(new CurlMultiTransport()))
            ->sendAsync(
                new Request('GET', self::$server->baseUrl . '/stream'),
                new RequestOptions([], null, $handler)
            )
            ->wait();

        self::assertSame([200], $statuses);
        self::assertGreaterThan(1, count($chunks));
        self::assertStringContainsString('data: chunk-4', implode('', $chunks));
        self::assertSame('', (string) $response->getBody());
    }

    public function testTheAsyncPathStillBuffersWhenNoHandlerIsAttached(): void
    {
        $response = (new AsyncClient(new CurlMultiTransport()))
            ->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))
            ->wait();

        self::assertSame('ok', (string) $response->getBody());
    }

    public function testConcurrentStreamsKeepChunksWithTheirOwnHandlers(): void
    {
        $firstChunks = [];
        $secondChunks = [];
        $firstHandler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$firstChunks): void {
                $firstChunks[] = $chunk;
            },
        );
        $secondHandler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$secondChunks): void {
                $secondChunks[] = $chunk;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));

        $first = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/stream?name=first'),
            new RequestOptions([], null, $firstHandler)
        );
        $second = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/stream?name=second'),
            new RequestOptions([], null, $secondHandler)
        );

        $first->wait();
        $second->wait();

        self::assertSame($this->streamPayload('first'), implode('', $firstChunks));
        self::assertSame($this->streamPayload('second'), implode('', $secondChunks));
    }

    public function testOnChunkFailureRejectsOnlyItsPromiseWhenWaitedFirst(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->with(self::isType('array'));
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failedPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector, $handler)
        );
        $survivingPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $failedPromise->wait();
            self::fail('Expected the chunk callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('status-201', (string) $survivingPromise->wait()->getBody());
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    public function testOnResponseFailureRejectsOnlyItsPromiseWhenWaitedFirst(): void
    {
        $failure = new RuntimeException('response callback failed');
        $collector = new TransferInfoCollector();
        $handler = new HttpStreamHandler(
            onChunk: static function (): void {
            },
            onResponse: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector, $handler)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $failingPromise->wait();
            self::fail('Expected the response callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertGreaterThanOrEqual(0.0, $collector->get()->totalTimeMs);
        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    public function testRepeatedWaitAfterAChunkCallbackFailureRethrowsTheSameException(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $promise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], null, $handler)
        );

        try {
            $promise->wait();
            self::fail('Expected the first wait to throw the chunk callback failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        try {
            $promise->wait();
            self::fail('Expected the second wait to rethrow the chunk callback failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAChunkCallbackFailureDoesNotRejectAHealthySiblingWaitedFirst(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->with(self::isType('array'));
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector, $handler)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected the chunk callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    public function testOnResponseFailureDoesNotRejectAHealthySiblingWaitedFirst(): void
    {
        $failure = new RuntimeException('response callback failed');
        $collector = new TransferInfoCollector();
        $handler = new HttpStreamHandler(
            onChunk: static function (): void {
            },
            onResponse: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector, $handler)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected the response callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertGreaterThanOrEqual(0.0, $collector->get()->totalTimeMs);
        self::assertSame(
            'ok',
            (string) $client->sendAsync(new Request('GET', self::$server->baseUrl . '/ok'))->wait()->getBody()
        );
    }

    public function testAHeaderResponseClientFailureRejectsOnlyItsPromiseWhenWaitedFirst(): void
    {
        $failure = new ClientException('invalid response status line');
        $handler = new HttpStreamHandler(
            onChunk: static function (): void {
            },
            onResponse: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], null, $handler)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $failingPromise->wait();
            self::fail('Expected the response callback to fail.');
        } catch (ClientException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());
    }

    public function testAHeaderResponseClientFailureDoesNotRejectAHealthySiblingWaitedFirst(): void
    {
        $failure = new ClientException('invalid response status line');
        $handler = new HttpStreamHandler(
            onChunk: static function (): void {
            },
            onResponse: static function () use ($failure): void {
                throw $failure;
            },
        );
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], null, $handler)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected the response callback to fail.');
        } catch (ClientException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testCollectorFailureRejectsOnlyItsPromiseWhenWaitedFirst(): void
    {
        $failure = new RuntimeException('collector failed');
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->willThrowException($failure);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        try {
            $failingPromise->wait();
            self::fail('Expected the collector to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected the collector failure to be memoized.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testCollectorFailureDoesNotRejectAHealthySiblingWaitedFirst(): void
    {
        $failure = new RuntimeException('collector failed');
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->willThrowException($failure);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport()));
        $failingPromise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector)
        );
        $healthyPromise = $client->sendAsync(new Request('GET', self::$server->baseUrl . '/status/201'));

        self::assertSame('status-201', (string) $healthyPromise->wait()->getBody());

        try {
            $failingPromise->wait();
            self::fail('Expected the collector to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testRepeatedCallbackFailuresDoNotLeakStreamResources(): void
    {
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 5.0));
        $failure = new RuntimeException('chunk callback failed');
        $streamCountBefore = count(get_resources('stream'));

        for ($iteration = 0; $iteration < 20; $iteration++) {
            $handler = new HttpStreamHandler(
                onChunk: static function () use ($failure): void {
                    throw $failure;
                },
            );
            $promise = $client->sendAsync(
                new Request('GET', self::$server->baseUrl . '/ok'),
                new RequestOptions([], null, $handler)
            );

            try {
                $promise->wait();
                self::fail('Expected the chunk callback to fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
        }

        unset($handler, $promise);
        gc_collect_cycles();

        self::assertSame($streamCountBefore, count(get_resources('stream')));
    }

    private function streamPayload(string $name): string
    {
        $payload = '';
        for ($i = 1; $i <= 4; $i++) {
            $payload .= "data: {$name}-{$i}\n\n";
        }

        return $payload;
    }
}
