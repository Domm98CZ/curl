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
    public function testTheMapperSendsTheShapeTheClassifierDerivedFromTheSameReportedSize(?int $reportedSize): void
    {
        $request = (new Request('PUT', 'https://example.com/'))->withBody($this->bodyReporting($reportedSize));

        $curlOptions = (new CurlOptionsMapper())->map($request, new RequestOptions());

        self::assertSame(RequestBodyPolicy::shapeForSize($reportedSize), self::shapeSentBy($curlOptions));
    }

    /** @return iterable<string, array{?int}> */
    public static function reportedSizeProvider(): iterable
    {
        yield 'reported empty while the stream still holds bytes' => [0];
        yield 'reported short' => [7];
        yield 'reported exactly at the buffering limit' => [CurlOptionsMapper::REPLAYABLE_BODY_LIMIT];
        yield 'reported one byte over the buffering limit' => [CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1];
        yield 'reported unknown' => [null];
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
        $buffered = array_key_exists(CURLOPT_POSTFIELDS, $curlOptions);
        $streamed = array_key_exists(CURLOPT_READFUNCTION, $curlOptions);
        self::assertFalse($buffered && $streamed, 'The mapper sent the body through both shapes at once.');

        if ($streamed) {
            return BodyShape::Streamed;
        }

        return $buffered ? BodyShape::Buffered : BodyShape::None;
    }
}
