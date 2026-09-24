<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Encoding;
use Domm98CZ\Curl\Options\FreshConnect;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Options\Proxy;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

final class RequestBodyPolicyReplayabilityTest extends TestCase
{
    private function nonSeekableBody(?int $size): StreamInterface
    {
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn($size);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(true);

        return $body;
    }

    private function seekableBody(string $payload): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $payload);
        rewind($resource);

        return new Stream($resource);
    }

    private function requestWithBody(string $method, StreamInterface $body): RequestInterface
    {
        return (new Request($method, 'https://example.com/'))->withBody($body);
    }

    private function verdict(RequestInterface $request, ?OptionsInterface $options = null): BodyReplay
    {
        return (new CurlOptionsMapper())->replayabilityOf($request, $options ?? new RequestOptions());
    }

    public function testAMethodThatCarriesNoBodyIsReplayableEvenWithANonSeekableBody(): void
    {
        $request = $this->requestWithBody('HEAD', $this->nonSeekableBody(7));

        self::assertSame(BodyReplay::Replayable, $this->verdict($request));
    }

    public function testAnEmptyNonSeekableBodyIsReplayable(): void
    {
        $request = $this->requestWithBody('PUT', $this->nonSeekableBody(0));

        self::assertSame(BodyReplay::Replayable, $this->verdict($request));
    }

    public function testASeekableBodyIsReplayable(): void
    {
        $request = $this->requestWithBody('PUT', $this->seekableBody('payload'));

        self::assertSame(BodyReplay::Replayable, $this->verdict($request));
    }

    public function testASeekableBodyOverTheBufferingLimitIsStillReplayable(): void
    {
        $request = $this->requestWithBody('PUT', $this->seekableBody(str_repeat('x', 1_048_577)));

        self::assertSame(BodyReplay::Replayable, $this->verdict($request));
    }

    public function testANonSeekableBodyIsNotReplayable(): void
    {
        $request = $this->requestWithBody('PUT', $this->nonSeekableBody(7));

        self::assertSame(BodyReplay::NotReplayable, $this->verdict($request));
    }

    public function testANonSeekableBodyOfUnknownSizeIsNotReplayable(): void
    {
        $request = $this->requestWithBody('PUT', $this->nonSeekableBody(null));

        self::assertSame(BodyReplay::NotReplayable, $this->verdict($request));
    }

    public function testEveryShippedOptionLeavesTheVerdictIntact(): void
    {
        $request = new Request('GET', 'https://example.com/');
        $options = new RequestOptions([
            new ConnectTimeout(1),
            new Encoding(),
            new FreshConnect(),
            new HttpVersion('2'),
            new MaxResponseSize(4096),
            new Proxy('http://proxy.example:8080'),
            new RedirectPolicy(),
            new SslVerification(),
            new Timeout(5),
        ]);

        self::assertSame(BodyReplay::Replayable, $this->verdict($request, $options));
    }

    public function testARawCurlOptionOutsideTheBodyFamilyLeavesTheVerdictIntact(): void
    {
        $request = new Request('GET', 'https://example.com/');
        $options = new RequestOptions([new RawCurlOption(CURLOPT_REFERER, 'https://example.com')]);

        self::assertSame(BodyReplay::Replayable, $this->verdict($request, $options));
    }

    public function testAThirdPartyOptionIsJudgedByTheKeysItWrites(): void
    {
        $request = new Request('GET', 'https://example.com/');
        $harmless = new class implements CurlOptionInterface {
            public function toCurlOptions(): array
            {
                return [CURLOPT_REFERER => 'https://example.com'];
            }
        };
        $smuggling = new class implements CurlOptionInterface {
            public function toCurlOptions(): array
            {
                return [CURLOPT_REFERER => 'https://example.com', CURLOPT_POSTFIELDS => 'payload'];
            }
        };

        self::assertSame(BodyReplay::Replayable, $this->verdict($request, new RequestOptions([$harmless])));
        self::assertSame(BodyReplay::NotReplayable, $this->verdict($request, new RequestOptions([$smuggling])));
    }

    public function testABodylessRequestSmugglingAnUploadThroughRawOptionsIsNotReplayable(): void
    {
        $request = new Request('GET', 'https://example.com/');
        $options = new RequestOptions([
            new RawCurlOption(CURLOPT_READFUNCTION, static fn (): string => 'chunk'),
            new RawCurlOption(CURLOPT_UPLOAD, true),
        ]);

        self::assertSame(BodyReplay::Replayable, $this->verdict($request));
        self::assertSame(BodyReplay::NotReplayable, $this->verdict($request, $options));
    }

    #[DataProvider('bodyFamilyKeyProvider')]
    public function testEveryKeyOfTheBodyFamilyDegradesTheVerdict(int $key): void
    {
        $request = new Request('GET', 'https://example.com/');
        $options = new RequestOptions([new Timeout(5), new RawCurlOption($key, 'smuggled'), new ConnectTimeout(1)]);

        self::assertSame(BodyReplay::NotReplayable, $this->verdict($request, $options));
    }

    /** @return iterable<string, array{int}> */
    public static function bodyFamilyKeyProvider(): iterable
    {
        // CURLOPT_INFILESIZE_LARGE has no PHP binding on every supported version, so the family is
        // resolved by name; a member that is missing here cannot be smuggled through it either.
        foreach ([
            'CURLOPT_POSTFIELDS', 'CURLOPT_INFILE', 'CURLOPT_INFILESIZE', 'CURLOPT_INFILESIZE_LARGE',
            'CURLOPT_READDATA', 'CURLOPT_READFUNCTION', 'CURLOPT_UPLOAD', 'CURLOPT_PUT', 'CURLOPT_POST',
            'CURLOPT_CUSTOMREQUEST', 'CURLOPT_NOBODY', 'CURLOPT_HTTPGET',
        ] as $name) {
            if (defined($name)) {
                yield $name => [(int) constant($name)];
            }
        }
    }

    public function testTheVerdictNeverConsumesTheBody(): void
    {
        $body = $this->seekableBody('payload');
        $body->seek(3);
        $request = $this->requestWithBody('PUT', $body);

        $this->verdict($request);

        self::assertSame(3, $body->tell());
    }
}
