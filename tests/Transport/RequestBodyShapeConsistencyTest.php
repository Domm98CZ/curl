<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Transport\BodyShape;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use Domm98CZ\Curl\Transport\RequestBodyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * The replayability verdict is only as good as the agreement between the two callers of
 * shapeForSize(): the classifier decides whether a retry may resend the body, the mapper decides
 * what actually goes out. Both must read the shape off the same reported size, however untruthful
 * that size is - a mapper that ever fell back to reading the stream would turn a Replayable verdict
 * into a lie. The bodies below deliberately contradict their own getSize() to pin that agreement,
 * not to bless what a contradicting stream does to the payload.
 */
final class RequestBodyShapeConsistencyTest extends TestCase
{
    #[DataProvider('reportedSizeProvider')]
    public function testTheMapperSendsTheShapeTheClassifierDerivedFromTheSameReportedSize(string $method, ?int $reportedSize): void
    {
        $request = (new Request($method, 'https://example.com/'))->withBody($this->bodyReporting($reportedSize));

        $curlOptions = (new CurlOptionsMapper())->map($request, new RequestOptions());

        self::assertSame(RequestBodyPolicy::shapeForSize($reportedSize), self::shapeSentBy($curlOptions));
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function reportedSizeProvider(): iterable
    {
        // POST is covered on its own because an empty POST still carries a (zero-length) POSTFIELDS buffer
        foreach (['PUT', 'POST'] as $method) {
            yield $method . ' reported empty while the stream still holds bytes' => [$method, 0];
            yield $method . ' reported short' => [$method, 7];
            yield $method . ' reported exactly at the buffering limit' => [$method, CurlOptionsMapper::REPLAYABLE_BODY_LIMIT];
            yield $method . ' reported one byte over the buffering limit' => [$method, CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1];
            yield $method . ' reported unknown' => [$method, null];
        }
    }

    private function bodyReporting(?int $reportedSize): StreamInterface
    {
        $payload = 'twenty five bytes of body';

        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn($reportedSize);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getContents')->willReturn($payload);
        $body->method('read')->willReturn($payload);
        $body->method('eof')->willReturn(false);

        return $body;
    }

    /** @param array<int, mixed> $curlOptions */
    private static function shapeSentBy(array $curlOptions): BodyShape
    {
        // a zero-length buffer puts no body bytes on the wire, which is what None means
        $buffered = array_key_exists(CURLOPT_POSTFIELDS, $curlOptions) && $curlOptions[CURLOPT_POSTFIELDS] !== '';
        $streamed = array_key_exists(CURLOPT_READFUNCTION, $curlOptions);
        self::assertFalse($buffered && $streamed, 'The mapper sent the body through both shapes at once.');

        if ($streamed) {
            return BodyShape::Streamed;
        }

        return $buffered ? BodyShape::Buffered : BodyShape::None;
    }
}
