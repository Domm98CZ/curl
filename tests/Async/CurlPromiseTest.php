<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Async;

use Domm98CZ\Curl\Async\CurlPromise;
use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\FakeMultiTransport;
use Http\Promise\Promise;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

final class CurlPromiseTest extends TestCase
{
    private function fulfilledPromise(int $statusCode = 200, string $body = 'a'): Promise
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);
        $raw = new RawResponse(0, '', sprintf("HTTP/1.1 %d X\r\n\r\n", $statusCode), new Stream($resource), []);

        return (new AsyncClient(new FakeMultiTransport($raw)))->sendAsync(new Request('GET', '/'));
    }

    private function rejectedPromise(): Promise
    {
        $raw = new RawResponse(CURLE_COULDNT_CONNECT, 'refused', '', new Stream(fopen('php://temp', 'r+')), []);

        return (new AsyncClient(new FakeMultiTransport($raw)))->sendAsync(new Request('GET', '/'));
    }

    public function testThenReturnsADifferentPromise(): void
    {
        $promise = $this->fulfilledPromise();

        self::assertNotSame($promise, $promise->then(static fn ($response) => $response));
    }

    public function testTransformingCallbackValueIsCarriedByTheDerivedPromise(): void
    {
        $derived = $this->fulfilledPromise(201)->then(static fn (ResponseInterface $r): string => 'code-' . $r->getStatusCode());

        self::assertSame(Promise::FULFILLED, $derived->getState());
        self::assertSame('code-201', $derived->wait());
    }

    public function testThenWithoutCallbacksKeepsTheOriginalResponse(): void
    {
        $promise = $this->fulfilledPromise(204);
        $derived = $promise->then();

        self::assertSame(Promise::FULFILLED, $derived->getState());
        self::assertSame(204, $derived->wait()->getStatusCode());
    }

    public function testASideEffectOnlyCallbackYieldsAPromiseCarryingNull(): void
    {
        $seen = null;
        $derived = $this->fulfilledPromise(200)->then(function (ResponseInterface $r) use (&$seen): void {
            $seen = $r->getStatusCode();
        });

        self::assertSame(200, $seen);
        self::assertNull($derived->wait());
    }

    public function testARejectionHandlerReturningAValueRecoversThePromise(): void
    {
        $derived = $this->rejectedPromise()->then(null, static fn (ClientExceptionInterface $e): string => 'fallback');

        self::assertSame(Promise::FULFILLED, $derived->getState());
        self::assertSame('fallback', $derived->wait());
    }

    public function testWithoutARejectionHandlerTheOriginalReasonIsPreserved(): void
    {
        $promise = $this->rejectedPromise();
        $reason = null;
        try {
            $promise->wait();
        } catch (NetworkException $exception) {
            $reason = $exception;
        }

        $derived = $promise->then(static fn ($response) => $response);
        self::assertSame(Promise::REJECTED, $derived->getState());

        try {
            $derived->wait();
            self::fail('Expected the derived promise to rethrow the original reason.');
        } catch (NetworkException $exception) {
            self::assertSame($reason, $exception);
        }
    }

    public function testAnExceptionThrownByTheCallbackRejectsTheDerivedPromise(): void
    {
        $failure = new \RuntimeException('callback blew up');
        $derived = $this->fulfilledPromise()->then(static function () use ($failure): void {
            throw $failure;
        });

        self::assertSame(Promise::REJECTED, $derived->getState());

        try {
            $derived->wait();
            self::fail('Expected the derived promise to carry the callback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAnErrorFromTheCallbackIsNotDisguisedAsARejection(): void
    {
        $this->expectException(\TypeError::class);

        $this->fulfilledPromise()->then(static function (int $notAResponse): void {
        });
    }

    public function testRepeatedWaitOnTheSamePromiseYieldsTheSameResult(): void
    {
        $promise = $this->fulfilledPromise(202);

        $first = $promise->wait();
        $second = $promise->wait();

        self::assertSame($first, $second);
        self::assertSame(202, $second->getStatusCode());
    }

    public function testRepeatedWaitOnARejectedPromiseRethrowsTheSameReason(): void
    {
        $promise = $this->rejectedPromise();

        $first = null;
        try {
            $promise->wait();
        } catch (ClientExceptionInterface $exception) {
            $first = $exception;
        }

        try {
            $promise->wait();
            self::fail('Expected the rejected promise to rethrow on every wait().');
        } catch (ClientExceptionInterface $exception) {
            self::assertSame($first, $exception);
        }
    }

    public function testRepeatedWaitOnAPromiseWhoseTickThrowsRethrowsTheSameReasonWithoutTickingAgain(): void
    {
        $failure = new \RuntimeException('tick failed');
        $raw = new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
        $transport = new FakeMultiTransport($raw);
        $transport->failNextTickWith($failure);
        $promise = (new AsyncClient(new BoundedMultiTransport($transport)))
            ->sendAsync(new Request('GET', '/'));

        try {
            $promise->wait();
            self::fail('Expected the first wait to throw the tick failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $transport->tickCalls);

        try {
            $promise->wait();
            self::fail('Expected the second wait to rethrow the tick failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $transport->tickCalls);
    }

    public function testThenWithoutARejectionHandlerPreservesAThrowableFromTick(): void
    {
        $failure = new \RuntimeException('tick failed');
        $raw = new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
        $transport = new FakeMultiTransport($raw);
        $transport->failNextTickWith($failure);
        $derived = (new AsyncClient(new BoundedMultiTransport($transport)))
            ->sendAsync(new Request('GET', '/'))
            ->then();

        self::assertSame(Promise::REJECTED, $derived->getState());
        try {
            $derived->wait();
            self::fail('Expected the derived promise to preserve the tick failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(1, $transport->tickCalls);
    }

    public function testChainedThenCallsCompose(): void
    {
        $derived = $this->fulfilledPromise(200)
            ->then(static fn (ResponseInterface $r): int => $r->getStatusCode())
            ->then(static fn (int $code): string => 'status:' . $code);

        self::assertSame('status:200', $derived->wait());
    }

    public function testThenCalledTwiceOnTheSamePromiseYieldsIndependentDerivatives(): void
    {
        $promise = $this->fulfilledPromise(200);

        $first = $promise->then(static fn (): string => 'first');
        $second = $promise->then(static fn (): string => 'second');

        self::assertNotSame($first, $second);
        self::assertSame('first', $first->wait());
        self::assertSame('second', $second->wait());
    }

    public function testConstructorRemainsCompatibleWithThreeClosures(): void
    {
        $resource = fopen('php://temp', 'r+');
        $raw = new RawResponse(0, '', "HTTP/1.1 204 No Content\r\n\r\n", new Stream($resource), []);
        $transport = new BoundedMultiTransport(new FakeMultiTransport($raw));
        $id = $transport->add(new Request('GET', '/'), new RequestOptions());
        $promise = new CurlPromise(
            tick: static fn () => $transport->tick(),
            isDone: static fn (): bool => $transport->isDone($id),
            resolve: static function () use ($transport, $id): ResponseInterface {
                $transport->takeResult($id);

                return new Response(204);
            },
        );

        self::assertSame(204, $promise->wait()->getStatusCode());
    }
}
