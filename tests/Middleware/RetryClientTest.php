<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Middleware;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Middleware\RetryClient;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fakes\FakeReplayAwareClient;
use Domm98CZ\Curl\Tests\Fakes\FakeSleeper;
use Domm98CZ\Curl\Tests\Fakes\FakeTransport;
use Domm98CZ\Curl\TransferInfoCollector;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryClientTest extends TestCase
{
    /** @param array<string, mixed> $info */
    private function rawResponse(int $statusCode, string $body = '', array $info = []): RawResponse
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);
        return new RawResponse(0, '', sprintf("HTTP/1.1 %d X\r\n\r\n", $statusCode), new Stream($resource), $info);
    }

    /** @param array<string, string> $headers */
    private function rawResponseWithHeaders(int $statusCode, array $headers): RawResponse
    {
        $block = sprintf("HTTP/1.1 %d X\r\n", $statusCode);
        foreach ($headers as $name => $value) {
            $block .= sprintf("%s: %s\r\n", $name, $value);
        }
        return new RawResponse(0, '', $block . "\r\n", new Stream(fopen('php://temp', 'r+')), []);
    }

    private function networkFailureRawResponse(): RawResponse
    {
        return new RawResponse(CURLE_COULDNT_CONNECT, 'refused', '', new Stream(fopen('php://temp', 'r+')), []);
    }

    private function retryClient(
        FakeTransport $transport,
        FakeSleeper $sleeper,
        int $maxAttempts = 3,
        bool $retryNonIdempotentMethods = false,
    ): RetryClient
    {
        return new RetryClient(
            new Client($transport),
            new MaxAttemptsRetryPolicy($maxAttempts, [503], $retryNonIdempotentMethods),
            new ExponentialBackoff(1, 1, jitter: false),
            $sleeper
        );
    }

    public function testWithDefaultsRetriesARetryableStatusOverTheGivenClient(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));

        $response = RetryClient::withDefaults(new Client($transport))->sendRequest(new Request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
    }

    public function testReturnsImmediatelyOnFirstTrySuccess(): void
    {
        $transport = new FakeTransport($this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $sleeper->sleptFor);
    }

    public function testRetriesOnNetworkFailureThenSucceeds(): void
    {
        $transport = new FakeTransport($this->networkFailureRawResponse(), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sleeper->sleptFor);
    }

    public function testRetriesOnRetryableStatusCodeThenSucceeds(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testResponseRetryWithAStreamHandlerWithoutResetClosureFailsClosed(): void
    {
        $received = '';
        $handler = new HttpStreamHandler(static function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });
        $transport = new FakeTransport($this->rawResponse(503, 'status-503'), $this->rawResponse(200, 'final'));
        $sleeper = new FakeSleeper();

        try {
            $this->retryClient($transport, $sleeper, retryNonIdempotentMethods: true)->send(
                new Request('POST', '/'),
                new RequestOptions([], null, $handler),
            );
            self::fail('Expected a handler without a reset closure to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('HTTP response status 503', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeper->sleptFor);
        self::assertSame('status-503', $received);
    }

    public function testResponseRetryWithAnApprovingResetClosureYieldsOnlyTheLastAttemptBody(): void
    {
        $received = '';
        $handler = new HttpStreamHandler(
            static function (string $chunk) use (&$received): void {
                $received .= $chunk;
            },
            null,
            new \Domm98CZ\Curl\Transport\HttpResponseParser(),
            static function () use (&$received): bool {
                $received = '';
                return true;
            },
        );
        $transport = new FakeTransport($this->rawResponse(503, 'status-503'), $this->rawResponse(200, 'final'));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper, retryNonIdempotentMethods: true)->send(
            new Request('POST', '/'),
            new RequestOptions([], null, $handler),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
        self::assertSame('final', $received);
    }

    public function testResponseRetryWithoutCollaboratorsKeepsExistingBehaviour(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200, 'final'));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper, retryNonIdempotentMethods: true)->send(
            new Request('POST', '/'),
            new RequestOptions(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('final', (string) $response->getBody());
        self::assertCount(2, $transport->calls);
        self::assertSame([1], $sleeper->sleptFor);
    }

    public function testExceptionRetryWithAStreamHandlerWithoutResetClosureCarriesTheExceptionCause(): void
    {
        $handler = new HttpStreamHandler(static function (string $chunk): void {
        });
        $transport = new FakeTransport($this->networkFailureRawResponse(), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        try {
            $this->retryClient($transport, $sleeper, retryNonIdempotentMethods: true)->send(
                new Request('POST', '/'),
                new RequestOptions([], null, $handler),
            );
            self::fail('Expected the stream handler to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertInstanceOf(NetworkException::class, $exception->getPrevious());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testRetryWithTransferInfoCollectorKeepsOnlyTheLastAttemptInfo(): void
    {
        $collector = new TransferInfoCollector();
        $transport = new FakeTransport(
            $this->rawResponse(503, info: ['total_time' => 0.1]),
            $this->rawResponse(200, info: ['total_time' => 0.2]),
        );
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper, retryNonIdempotentMethods: true)->send(
            new Request('POST', '/'),
            new RequestOptions([], $collector),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200.0, $collector->get()->totalTimeMs);
        self::assertCount(2, $transport->calls);
    }

    public function testRetryWithANonRetryAwareStreamHandlerFailsClosed(): void
    {
        $handler = new class implements StreamHandlerInterface {
            public function onHeaderLine(string $line): void
            {
            }

            public function onChunk(string $chunk): void
            {
            }
        };
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->expectException(ClientException::class);
        try {
            $this->retryClient($transport, $sleeper)->send(
                new Request('GET', '/'),
                new RequestOptions([], null, $handler),
            );
        } finally {
            self::assertCount(1, $transport->calls);
            self::assertSame([], $sleeper->sleptFor);
        }
    }

    public function testExhaustingRetriesOnStatusCodeReturnsLastResponseNotAnException(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(503));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper, maxAttempts: 2)->sendRequest(new Request('GET', '/'));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testExhaustingRetriesOnNetworkFailureRethrowsTheException(): void
    {
        $transport = new FakeTransport($this->networkFailureRawResponse(), $this->networkFailureRawResponse());
        $sleeper = new FakeSleeper();

        $this->expectException(\Domm98CZ\Curl\Exceptions\NetworkException::class);
        $this->retryClient($transport, $sleeper, maxAttempts: 2)->sendRequest(new Request('GET', '/'));
    }

    public function testRewindsSeekableRequestBodyBeforeEachRetry(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'payload');
        $body = new Stream($resource);
        $request = (new Request('PUT', '/'))->withBody($body);

        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->retryClient($transport, $sleeper, maxAttempts: 2)->sendRequest($request);

        self::assertSame(0, $body->tell());
    }

    public function testResponseRetryWithANonSeekableRequestBodyFailsClosed(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200, 'final'));
        $sleeper = new FakeSleeper();
        $request = (new Request('PUT', '/'))->withBody($this->nonSeekableBody(7));

        try {
            $this->retryClient($transport, $sleeper)->sendRequest($request);
            self::fail('Expected a non-seekable request body to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertSame(
                'Cannot retry after HTTP response status 503 because the request body '
                    . 'or its per-request options cannot reset safely.',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testExceptionRetryWithANonSeekableRequestBodyCarriesTheExceptionCause(): void
    {
        $transport = new FakeTransport($this->networkFailureRawResponse(), $this->rawResponse(200));
        $sleeper = new FakeSleeper();
        $request = (new Request('PUT', '/'))->withBody($this->nonSeekableBody(null));

        try {
            $this->retryClient($transport, $sleeper)->sendRequest($request);
            self::fail('Expected a non-seekable request body to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertStringContainsString(
                'because the request body or its per-request options cannot reset safely.',
                $exception->getMessage()
            );
            self::assertInstanceOf(NetworkException::class, $exception->getPrevious());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testANonSeekableEmptyRequestBodyStillRetries(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200, 'final'));
        $sleeper = new FakeSleeper();
        $request = (new Request('PUT', '/'))->withBody($this->nonSeekableBody(0));

        $response = $this->retryClient($transport, $sleeper)->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
    }

    public function testAMethodThatCarriesNoRequestBodyRetriesDespiteANonSeekableBody(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();
        $request = (new Request('HEAD', '/'))->withBody($this->nonSeekableBody(7));

        $response = $this->retryClient($transport, $sleeper)->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
    }

    public function testARawCurlOptionOutsideTheBodyFamilyLeavesTheRetryAlone(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->send(
            new Request('GET', '/'),
            new RequestOptions([new RawCurlOption(CURLOPT_REFERER, 'https://example.com')]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
    }

    public function testARawCurlOptionWritingABodyKeyRefusesTheRetry(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        try {
            $this->retryClient($transport, $sleeper)->send(
                new Request('GET', '/'),
                new RequestOptions([new RawCurlOption(CURLOPT_POSTFIELDS, 'smuggled')]),
            );
            self::fail('Expected a raw body option to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('HTTP response status 503', $exception->getMessage());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testAnUploadSmuggledThroughRawOptionsRefusesTheRetry(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->expectException(ClientException::class);
        try {
            $this->retryClient($transport, $sleeper)->send(
                new Request('GET', '/'),
                new RequestOptions([
                    new RawCurlOption(CURLOPT_READFUNCTION, static fn (): string => 'chunk'),
                    new RawCurlOption(CURLOPT_UPLOAD, true),
                ]),
            );
        } finally {
            self::assertCount(1, $transport->calls);
        }
    }

    public function testAVettedCurlOptionLeavesTheRetryAlone(): void
    {
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->send(
            new Request('GET', '/'),
            new RequestOptions([new Timeout(5), new ConnectTimeout(1)]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $transport->calls);
    }

    public function testTheRequestBodyIsRefusedBeforeTheCollaboratorsAreConsulted(): void
    {
        $handler = new class implements StreamHandlerInterface {
            public function onHeaderLine(string $line): void
            {
            }

            public function onChunk(string $chunk): void
            {
            }
        };
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();
        $request = (new Request('PUT', '/'))->withBody($this->nonSeekableBody(7));

        try {
            $this->retryClient($transport, $sleeper)->send($request, new RequestOptions([], null, $handler));
            self::fail('Expected the request body to refuse the retry.');
        } catch (ClientException $exception) {
            self::assertSame(
                'Cannot retry after HTTP response status 503 because the request body '
                    . 'or its per-request options cannot reset safely.',
                $exception->getMessage()
            );
        }
    }

    public function testASeekableBodyThatFailsToRewindRefusesTheRetryAsAClientException(): void
    {
        $failure = new \RuntimeException('the underlying resource was detached');
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('getSize')->willReturn(7);
        $body->method('isSeekable')->willReturn(true);
        $body->method('rewind')->willThrowException($failure);
        $transport = new FakeTransport($this->rawResponse(503), $this->rawResponse(200));
        $sleeper = new FakeSleeper();
        $request = (new Request('GET', '/'))->withBody($body);

        try {
            $this->retryClient($transport, $sleeper)->sendRequest($request);
            self::fail('Expected the failing rewind to refuse the retry.');
        } catch (ClientExceptionInterface $exception) {
            self::assertInstanceOf(ClientException::class, $exception);
            self::assertStringContainsString('request body', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertCount(1, $transport->calls);
    }

    private function retryClientOver(
        \Psr\Http\Client\ClientInterface $inner,
        FakeSleeper $sleeper,
        int $maxAttempts = 3,
    ): RetryClient {
        return new RetryClient(
            $inner,
            new MaxAttemptsRetryPolicy($maxAttempts, [503], false),
            new ExponentialBackoff(1, 1, jitter: false),
            $sleeper
        );
    }

    public function testARefusingVerdictOverridesASeekableRequestBody(): void
    {
        $inner = new FakeReplayAwareClient(
            BodyReplay::NotReplayable,
            new Response(503),
            new Response(200),
        );
        $sleeper = new FakeSleeper();
        $request = (new Request('GET', '/'))->withBody($this->seekableBody('payload'));

        try {
            $this->retryClientOver($inner, $sleeper)->sendRequest($request);
            self::fail('Expected the refusing verdict to stop the retry.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('HTTP response status 503', $exception->getMessage());
        }

        self::assertCount(1, $inner->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testAnApprovingVerdictOverridesTheNonSeekableHeuristic(): void
    {
        $inner = new FakeReplayAwareClient(
            BodyReplay::Replayable,
            new Response(503),
            new Response(200),
        );
        $sleeper = new FakeSleeper();
        $request = (new Request('GET', '/'))->withBody($this->nonSeekableBody(7));

        $response = $this->retryClientOver($inner, $sleeper)->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $inner->calls);
    }

    public function testAnAbstainingInnerFallsBackToTheSeekabilityHeuristic(): void
    {
        $sleeper = new FakeSleeper();
        $refused = new FakeReplayAwareClient(BodyReplay::Unknown, new Response(503), new Response(200));

        try {
            $this->retryClientOver($refused, $sleeper)
                ->sendRequest((new Request('GET', '/'))->withBody($this->nonSeekableBody(7)));
            self::fail('Expected the fallback heuristic to stop the retry.');
        } catch (ClientException $exception) {
            self::assertSame(
                'Cannot retry after HTTP response status 503 because the request body cannot reset safely.',
                $exception->getMessage()
            );
        }
        self::assertCount(1, $refused->calls);

        $allowed = new FakeReplayAwareClient(BodyReplay::Unknown, new Response(503), new Response(200));
        $response = $this->retryClientOver($allowed, $sleeper)
            ->sendRequest((new Request('GET', '/'))->withBody($this->seekableBody('payload')));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $allowed->calls);
    }

    public function testAnAbstainingInnerRefusesANonSeekableBodyThatReportsZeroSize(): void
    {
        $inner = new FakeReplayAwareClient(BodyReplay::Unknown, new Response(503), new Response(200));
        $sleeper = new FakeSleeper();

        try {
            $this->retryClientOver($inner, $sleeper)
                ->sendRequest((new Request('GET', '/'))->withBody($this->nonSeekableBody(0)));
            self::fail('Expected the fallback heuristic to stop the retry.');
        } catch (ClientException $exception) {
            self::assertSame(
                'Cannot retry after HTTP response status 503 because the request body cannot reset safely.',
                $exception->getMessage()
            );
        }

        self::assertCount(1, $inner->calls);
        self::assertSame([], $sleeper->sleptFor);
    }

    public function testAnAbstainingInnerStillRetriesARequestWithNoBodyOfItsOwn(): void
    {
        $sleeper = new FakeSleeper();

        foreach (['GET', 'HEAD', 'DELETE'] as $method) {
            $inner = new FakeReplayAwareClient(BodyReplay::Unknown, new Response(503), new Response(200));

            $response = $this->retryClientOver($inner, $sleeper)->sendRequest(new Request($method, '/'));

            self::assertSame(200, $response->getStatusCode(), $method);
            self::assertCount(2, $inner->calls, $method);
        }
    }

    public function testTheVerdictIsNotConsultedBeforeTheFirstAttempt(): void
    {
        $inner = new FakeReplayAwareClient(BodyReplay::NotReplayable, new Response(200));

        $response = $this->retryClientOver($inner, new FakeSleeper())->sendRequest(new Request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $inner->verdictQueries);
    }

    public function testTheVerdictIsAskedWithThePerRequestOptions(): void
    {
        $inner = new FakeReplayAwareClient(BodyReplay::Replayable, new Response(503), new Response(200));
        $options = new RequestOptions();

        $this->retryClientOver($inner, new FakeSleeper())->send(new Request('GET', '/'), $options);

        self::assertCount(1, $inner->verdictQueries);
        self::assertSame($options, $inner->verdictQueries[0]['options']);
    }

    private function seekableBody(string $payload): \Psr\Http\Message\StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $payload);
        rewind($resource);

        return new Stream($resource);
    }

    private function nonSeekableBody(?int $size): \Psr\Http\Message\StreamInterface
    {
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('getSize')->willReturn($size);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(true);

        return $body;
    }

    public function testDoesNotReplayANonIdempotentRequest(): void
    {
        $transport = new FakeTransport($this->rawResponse(503));
        $sleeper = new FakeSleeper();

        $response = $this->retryClient($transport, $sleeper)->sendRequest(new Request('POST', '/'));

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $transport->calls);
        self::assertCount(0, $sleeper->sleptFor);
    }

    public function testHonoursRetryAfterInSecondsInsteadOfTheBackoffDelay(): void
    {
        $transport = new FakeTransport($this->rawResponseWithHeaders(503, ['Retry-After' => '3']), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertSame([3_000_000], $sleeper->sleptFor);
    }

    public function testHonoursRetryAfterAsAnHttpDate(): void
    {
        $when = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);
        $transport = new FakeTransport($this->rawResponseWithHeaders(503, ['Retry-After' => $when]), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertCount(1, $sleeper->sleptFor);
        self::assertGreaterThanOrEqual(1_000_000, $sleeper->sleptFor[0]);
        self::assertLessThanOrEqual(2_000_000, $sleeper->sleptFor[0]);
    }

    public function testIgnoresAnAbsurdRetryAfterAndFallsBackToTheBackoff(): void
    {
        $transport = new FakeTransport($this->rawResponseWithHeaders(503, ['Retry-After' => '86400']), $this->rawResponse(200));
        $sleeper = new FakeSleeper();

        $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', '/'));

        self::assertSame([1], $sleeper->sleptFor);
    }

    public function testDoesNotRetryARequestLevelFailure(): void
    {
        $raw = new RawResponse(CURLE_URL_MALFORMAT, 'malformed', '', new Stream(fopen('php://temp', 'r+')), []);
        $transport = new FakeTransport($raw);
        $sleeper = new FakeSleeper();

        $this->expectException(\Domm98CZ\Curl\Exceptions\RequestException::class);
        try {
            $this->retryClient($transport, $sleeper)->sendRequest(new Request('GET', 'https://example.com'));
        } finally {
            self::assertCount(1, $transport->calls);
        }
    }

    public function testCarriesPerRequestOptionsThroughToTheDecoratedClient(): void
    {
        $transport = new FakeTransport($this->rawResponse(200));
        $sleeper = new FakeSleeper();
        $options = new RequestOptions();

        $this->retryClient($transport, $sleeper)->send(new Request('GET', '/'), $options);

        self::assertSame($options, $transport->calls[0]['options']);
    }

    public function testRejectsPerRequestOptionsWhenTheDecoratedClientCannotCarryThem(): void
    {
        $inner = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('not reached');
            }
        };

        $client = new RetryClient($inner, new MaxAttemptsRetryPolicy(1), new ExponentialBackoff(1, 1, jitter: false), new FakeSleeper());

        $this->expectException(ClientException::class);
        $client->send(new Request('GET', '/'), new RequestOptions());
    }
}
