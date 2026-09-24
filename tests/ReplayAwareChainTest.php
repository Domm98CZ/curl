<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Contract\CurlOptionsMapperInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\ReplayAwareInterface;
use Domm98CZ\Curl\Contract\TransportInterface;
use Domm98CZ\Curl\Middleware\RetryClient;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Tests\Fakes\FakeReplayAwareClient;
use Domm98CZ\Curl\Tests\Fakes\FakeSleeper;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class ReplayAwareChainTest extends TestCase
{
    private function nonSeekableBody(?int $size): StreamInterface
    {
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn($size);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(true);

        return $body;
    }

    private function abstainingTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            public function execute(RequestInterface $request, OptionsInterface $options): RawResponse
            {
                return new RawResponse(0, '', "HTTP/1.1 200 X\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
            }

            public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
            {
                return BodyReplay::Unknown;
            }
        };
    }

    public function testCurlTransportAnswersForItsOwnMapping(): void
    {
        $transport = new CurlTransport();

        $replayable = (new Request('HEAD', 'https://example.com/'))->withBody($this->nonSeekableBody(7));
        $refused = (new Request('PUT', 'https://example.com/'))->withBody($this->nonSeekableBody(7));

        self::assertSame(BodyReplay::Replayable, $transport->replayabilityOf($replayable, new RequestOptions()));
        self::assertSame(BodyReplay::NotReplayable, $transport->replayabilityOf($refused, new RequestOptions()));
    }

    public function testCurlTransportForwardsTheVerdictOfAnInjectedMapper(): void
    {
        $mapper = new class implements CurlOptionsMapperInterface {
            public function map(RequestInterface $request, OptionsInterface $options): array
            {
                return [CURLOPT_URL => (string) $request->getUri()];
            }

            public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
            {
                return BodyReplay::Unknown;
            }
        };
        $transport = new CurlTransport($mapper);

        $request = new Request('GET', 'https://example.com/');

        self::assertSame(BodyReplay::Unknown, $transport->replayabilityOf($request, new RequestOptions()));
    }

    public function testClientDelegatesToItsTransport(): void
    {
        $client = new Client(new CurlTransport());
        self::assertInstanceOf(ReplayAwareInterface::class, $client);

        $request = (new Request('PUT', 'https://example.com/'))->withBody($this->nonSeekableBody(7));

        self::assertSame(BodyReplay::NotReplayable, $client->replayabilityOf($request));
        self::assertSame(
            BodyReplay::Replayable,
            $client->replayabilityOf((new Request('PUT', 'https://example.com/'))->withBody($this->nonSeekableBody(0)))
        );
    }

    public function testClientCarriesTheOptionsIntoTheVerdict(): void
    {
        $client = new Client(new CurlTransport());
        $request = new Request('GET', 'https://example.com/');

        self::assertSame(BodyReplay::Replayable, $client->replayabilityOf($request));
        self::assertSame(
            BodyReplay::NotReplayable,
            $client->replayabilityOf($request, new RequestOptions([new RawCurlOption(CURLOPT_UPLOAD, true)]))
        );
    }

    public function testClientOverAnAbstainingTransportCannotAnswer(): void
    {
        $client = new Client($this->abstainingTransport());

        $request = (new Request('PUT', 'https://example.com/'))->withBody($this->nonSeekableBody(7));

        self::assertSame(BodyReplay::Unknown, $client->replayabilityOf($request));
    }

    private function retryClientOver(ClientInterface $inner): RetryClient
    {
        return new RetryClient(
            $inner,
            new MaxAttemptsRetryPolicy(3, [503], false),
            new ExponentialBackoff(1, 1, jitter: false),
            new FakeSleeper()
        );
    }

    public function testRetryClientForwardsTheVerdictOfItsInnerClient(): void
    {
        $inner = new FakeReplayAwareClient(BodyReplay::NotReplayable);
        $middleware = $this->retryClientOver($inner);
        self::assertInstanceOf(ReplayAwareInterface::class, $middleware);

        $request = new Request('GET', 'https://example.com/');
        $options = new RequestOptions();

        self::assertSame(BodyReplay::NotReplayable, $middleware->replayabilityOf($request, $options));
        self::assertCount(1, $inner->verdictQueries);
        self::assertSame($options, $inner->verdictQueries[0]['options']);
    }

    public function testRetryClientOverAReplayBlindInnerCannotAnswer(): void
    {
        $inner = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('not reached');
            }
        };

        self::assertSame(
            BodyReplay::Unknown,
            $this->retryClientOver($inner)->replayabilityOf(new Request('GET', 'https://example.com/'))
        );
    }

    public function testStackedMiddlewareStillReachesTheTransportVerdict(): void
    {
        $stacked = $this->retryClientOver($this->retryClientOver(new Client(new CurlTransport())));

        $refused = (new Request('PUT', 'https://example.com/'))->withBody($this->nonSeekableBody(7));

        self::assertSame(BodyReplay::NotReplayable, $stacked->replayabilityOf($refused));
        self::assertSame(
            BodyReplay::Replayable,
            $stacked->replayabilityOf(new Request('GET', 'https://example.com/'))
        );
    }
}
