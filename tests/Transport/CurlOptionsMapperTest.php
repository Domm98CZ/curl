<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Encoding;
use Domm98CZ\Curl\Options\FreshConnect;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Options\Proxy;
use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RedirectGuards;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fakes\RecordingStream;
use Domm98CZ\Curl\Tests\Fakes\UnvalidatedRequest;
use Domm98CZ\Curl\Tests\Fakes\UnvalidatedUri;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class CurlOptionsMapperTest extends TestCase
{
    private CurlOptionsMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CurlOptionsMapper();
    }

    public function testMapsUrlMethodAndBaseOptions(): void
    {
        $options = $this->mapper->map(new Request('GET', 'https://example.com/a'), new RequestOptions());

        self::assertSame('https://example.com/a', $options[CURLOPT_URL]);
        self::assertTrue($options[CURLOPT_HTTPGET]);
        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
    }

    public function testMapsAnExplicitRequestTargetWithoutChangingTheUrl(): void
    {
        $request = (new Request('OPTIONS', 'https://example.com/from-uri?query=1'))->withRequestTarget('*');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame('https://example.com/from-uri?query=1', $options[CURLOPT_URL]);
        self::assertSame('*', $options[CURLOPT_REQUEST_TARGET]);
        self::assertSame('OPTIONS', $options[CURLOPT_CUSTOMREQUEST]);
    }

    public function testDoesNotMapARequestTargetThatMatchesTheUriOriginForm(): void
    {
        $request = (new Request('GET', 'https://example.com/from-uri?query=1'))
            ->withRequestTarget('/from-uri?query=1');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertArrayNotHasKey(CURLOPT_REQUEST_TARGET, $options);
    }

    #[DataProvider('invalidTransportRequestTargetProvider')]
    public function testRejectsAnInvalidTargetFromAForeignRequestImplementation(string $target): void
    {
        $request = new UnvalidatedRequest(
            new Request('GET', 'https://example.com/from-uri'),
            requestTarget: $target,
        );

        try {
            $this->mapper->map($request, new RequestOptions());
            self::fail('Expected the invalid request target to be rejected.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTransportRequestTargetProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['/a b'];
        yield 'horizontal tab' => ["/a\tb"];
        yield 'CRLF' => ["/safe\r\nX-Injected: yes"];
        yield 'NUL' => ["/a\0b"];
        yield 'unit separator' => ["/a\x1Fb"];
        yield 'DEL' => ["/a\x7Fb"];
        yield 'high byte' => ["/a\x80b"];
        yield 'overlong UTF-8 CR' => ["/ok\xC0\x8D"];
        yield 'overlong UTF-8 LF' => ["/ok\xC0\x8A"];
        yield 'trailing newline' => ["/ok\n"];
    }

    public function testRejectsAnInvalidTargetEvenWhenItMatchesTheUriOriginForm(): void
    {
        $request = new Request('GET', new UnvalidatedUri('example.com', path: "/a\x01b"));
        self::assertSame("/a\x01b", $request->getRequestTarget());

        $this->expectException(RequestException::class);
        $this->mapper->map($request, new RequestOptions());
    }

    public function testAPinnedRequestTargetDisablesAutomaticRedirects(): void
    {
        $request = (new Request('GET', 'https://example.com/'))
            ->withRequestTarget('/v1/accounts?api_key=secret');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame('/v1/accounts?api_key=secret', $options[CURLOPT_REQUEST_TARGET]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testARequestTargetMatchingTheOriginFormKeepsAutomaticRedirectsEnabled(): void
    {
        $request = (new Request('GET', 'https://example.com/a'))->withRequestTarget('/a');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testDefaultsConnectTimeoutToThirtySeconds(): void
    {
        $options = $this->mapper->map(new Request('GET', 'ftp://example.com/file'), new RequestOptions());

        self::assertSame(30, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    public function testExplicitConnectTimeoutOverridesTheDefault(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new ConnectTimeout(0)])
        );

        self::assertSame(0, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    public function testTimeoutOptionMapsToCurlTimeout(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new Timeout(12)])
        );

        self::assertSame(12, $options[CURLOPT_TIMEOUT]);
    }

    public function testSslVerificationOptionMapsPeerAndHostChecks(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new SslVerification(false, true)])
        );

        self::assertSame(0, $options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testProxyOptionMapsToCurlProxy(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new Proxy('http://proxy.local:8080')])
        );

        self::assertSame('http://proxy.local:8080', $options[CURLOPT_PROXY]);
    }

    public function testFreshConnectOptionMapsToCurlFreshConnect(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new FreshConnect()])
        );

        self::assertTrue($options[CURLOPT_FRESH_CONNECT]);
    }

    public function testMapsHeadersAsColonSeparatedLines(): void
    {
        $request = new Request('GET', 'https://example.com', ['X-Test' => 'a', 'Accept' => 'application/json']);
        $options = $this->mapper->map($request, new RequestOptions());

        self::assertContains('X-Test: a', $options[CURLOPT_HTTPHEADER]);
        self::assertContains('Accept: application/json', $options[CURLOPT_HTTPHEADER]);
    }

    public function testMapsEmptyHeaderValuesUsingCurlSemicolonSyntax(): void
    {
        $request = new Request('GET', 'https://example.com', [
            'X-Empty' => '',
            'X-Value' => 'bar',
            'X-Mixed' => ['a', ''],
        ]);

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame(
            ['X-Empty;', 'X-Value: bar', 'X-Mixed: a, '],
            $options[CURLOPT_HTTPHEADER],
        );
    }

    /**
     * @param string|string[] $value
     */
    #[DataProvider('droppedFramingHeaderProvider')]
    public function testDropsFramingHeadersInsteadOfForwardingThem(string $name, string|array $value): void
    {
        $request = (new Request('POST', 'https://example.com/a'))
            ->withHeader($name, $value)
            ->withBody($this->stream('a body of a length that is not zero'));

        $options = $this->mapper->map($request, new RequestOptions());

        foreach ($options[CURLOPT_HTTPHEADER] as $header) {
            self::assertDoesNotMatchRegularExpression(
                '/^' . preg_quote($name, '/') . '\s*[:;]/i',
                $header,
            );
        }
    }

    /** @return iterable<string, array{string, string|string[]}> */
    public static function droppedFramingHeaderProvider(): iterable
    {
        // a declared zero against a non-empty body is the CL.0 request smuggling primitive
        yield 'Content-Length zero against a real body' => ['Content-Length', '0'];
        yield 'Content-Length list' => ['Content-Length', ['0', '8']];
        yield 'Content-Length empty' => ['Content-Length', ''];
        yield 'Transfer-Encoding chunked' => ['Transfer-Encoding', 'chunked'];
        yield 'Transfer-Encoding blank list' => ['Transfer-Encoding', ['', '']];
        yield 'Transfer-Encoding empty' => ['Transfer-Encoding', ''];
        yield 'lower case transfer-encoding' => ['transfer-encoding', 'chunked'];
    }

    public function testDropsAContentLengthAddedAcrossSeveralCalls(): void
    {
        $request = (new Request('POST', 'https://example.com/a'))
            ->withHeader('Content-Length', '0')
            ->withAddedHeader('Content-Length', '8')
            ->withBody($this->stream('a body of a length that is not zero'));

        self::assertSame('0, 8', $request->getHeaderLine('Content-Length'));
        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame(['Content-Type:', 'Expect:'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testDroppingFramingHeadersLeavesOtherHeadersUntouched(): void
    {
        $request = new Request('POST', 'https://example.com/a', [
            'Content-Length' => '0',
            'X-Foo' => ['', ''],
            'X-Bar' => '',
            'Accept' => 'application/json',
        ]);

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame(['X-Foo: , ', 'X-Bar;', 'Accept: application/json'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testAnEmptyValueListOnANonFramingHeaderIsStillSkipped(): void
    {
        $request = new UnvalidatedRequest(
            new Request('GET', 'https://example.com/a'),
            headers: ['X-Foo' => [], 'Accept' => ['application/json']],
        );

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame(['Accept: application/json'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testRawCurlOptionStillOverridesFramingDeliberately(): void
    {
        $smuggled = ['Transfer-Encoding: chunked', 'Content-Length: 8'];
        $request = (new Request('POST', 'https://example.com/a', ['Content-Length' => '0']))
            ->withBody($this->stream('a body of a length that is not zero'));

        $options = $this->mapper->map(
            $request,
            new RequestOptions([new RawCurlOption(CURLOPT_HTTPHEADER, $smuggled)]),
        );

        self::assertSame($smuggled, $options[CURLOPT_HTTPHEADER]);
    }

    /** @param string[] $values */
    #[DataProvider('unusableHostProvider')]
    public function testRejectsAHostThatIsNotExactlyOneNonEmptyValue(array $values): void
    {
        $request = (new Request('GET', 'https://example.com/a'))->withHeader('Host', $values);

        try {
            $this->mapper->map($request, new RequestOptions());
            self::fail('Expected the unusable Host header to be rejected.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertStringContainsString('Host', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string[]}> */
    public static function unusableHostProvider(): iterable
    {
        yield 'empty' => [['']];
        // implode() joins these into ", ", which the old empty-value guard did not recognise
        yield 'two empty values' => [['', '']];
        yield 'whitespace only' => [[' ']];
        yield 'two real values' => [['a', 'b']];
    }

    public function testADistinctHostHeaderStillPassesThrough(): void
    {
        $request = (new Request('GET', 'https://example.com/a'))->withHeader('Host', 'other.example.com');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame(['Host: other.example.com'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testAHostHeaderMatchingTheUriIsStillSkipped(): void
    {
        $request = (new Request('GET', 'https://example.com/a'))->withHeader('Host', 'example.com');

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame([], $options[CURLOPT_HTTPHEADER]);
    }

    public function testAnEmptySafeHeaderKeepsAutomaticRedirectsEnabled(): void
    {
        $options = $this->mapper->map(new Request('GET', '/', ['Accept' => '']), new RequestOptions());

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testAnEmptyCredentialHeaderDisablesAutomaticRedirects(): void
    {
        $options = $this->mapper->map(new Request('GET', '/', ['Authorization' => '']), new RequestOptions());

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    /** @return iterable<string, array{int}> */
    public static function headBodySizeProvider(): iterable
    {
        yield 'buffered body' => [7];
        yield 'streamed body' => [CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1];
    }

    #[DataProvider('headBodySizeProvider')]
    public function testAHeadRequestNeverTouchesTheBodyStream(int $size): void
    {
        $body = new RecordingStream($this->stream(str_repeat('a', $size)));

        $this->mapper->map((new Request('HEAD', '/'))->withBody($body), new RequestOptions());

        // asserting the absent options is not enough: an unnecessary getSize() or rewind() would
        // consume a one-shot body while leaving the resulting option array identical
        self::assertSame([], $body->calls);
    }

    public function testANonHeadRequestDoesTouchTheBodyStream(): void
    {
        $body = new RecordingStream($this->stream('payload'));

        $this->mapper->map((new Request('POST', '/'))->withBody($body), new RequestOptions());

        self::assertNotSame([], $body->calls);
    }

    public function testBodyPreparationFailureIsWrappedAsARequestException(): void
    {
        $failure = new RuntimeException('rewind failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(1);
        $body->method('isSeekable')->willReturn(true);
        $body->method('rewind')->willThrowException($failure);
        $request = (new Request('POST', '/'))->withBody($body);

        try {
            $this->mapper->map($request, new RequestOptions());
            self::fail('Expected request body preparation to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAFailingBodySizeLookupIsWrappedAsARequestException(): void
    {
        $failure = new RuntimeException('size unavailable');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willThrowException($failure);
        $request = (new Request('POST', '/'))->withBody($body);

        try {
            $this->mapper->map($request, new RequestOptions());
            self::fail('Expected request body preparation to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAFailingBufferedBodyReadIsWrappedAsARequestException(): void
    {
        $failure = new RuntimeException('read failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(7);
        $body->method('isSeekable')->willReturn(false);
        $body->method('getContents')->willThrowException($failure);
        $request = (new Request('POST', '/'))->withBody($body);

        try {
            $this->mapper->map($request, new RequestOptions());
            self::fail('Expected request body preparation to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testRestrictsAnHttpsRequestAndItsRedirectsToHttps(): void
    {
        $options = $this->mapper->map(new Request('GET', 'https://example.com'), new RequestOptions());

        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testAllowsAnHttpRequestToUpgradeToHttps(): void
    {
        $options = $this->mapper->map(new Request('GET', 'http://example.com'), new RequestOptions());

        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testRestrictsProtocolsToTheRequestedSchemeForNonHttpRequests(): void
    {
        $options = $this->mapper->map(new Request('GET', 'ftp://example.com/f.txt'), new RequestOptions());

        self::assertSame(CURLPROTO_FTP, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_FTP, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    #[DataProvider('newProtocolProvider')]
    public function testRestrictsNewlyMappedProtocolsToTheRequestedScheme(string $scheme, int $mask): void
    {
        $options = $this->mapper->map(new Request('GET', $scheme . '://example.com/resource'), new RequestOptions());

        self::assertSame($mask, $options[CURLOPT_PROTOCOLS]);
        self::assertSame($mask, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    /** @return iterable<string, array{string, int}> */
    public static function newProtocolProvider(): iterable
    {
        foreach ([
            'smb' => 'CURLPROTO_SMB',
            'smbs' => 'CURLPROTO_SMBS',
            'mqtt' => 'CURLPROTO_MQTT',
            // Inert: libcurl implements no mqtts at all, so no CURLPROTO_MQTTS exists to expose.
            // Coverage waits on that upstream feature, not on a PHP binding.
            'mqtts' => 'CURLPROTO_MQTTS',
            'rtmp' => 'CURLPROTO_RTMP',
            'rtmpt' => 'CURLPROTO_RTMPT',
            'rtmpe' => 'CURLPROTO_RTMPE',
            'rtmpte' => 'CURLPROTO_RTMPTE',
            'rtmps' => 'CURLPROTO_RTMPS',
            'rtmpts' => 'CURLPROTO_RTMPTS',
        ] as $scheme => $constant) {
            if (defined($constant)) {
                $mask = constant($constant);
                if (is_int($mask)) {
                    yield $scheme => [$scheme, $mask];
                }
            }
        }
    }

    public function testUnknownSchemeKeepsLibcurlProtocolDefaults(): void
    {
        $options = $this->mapper->map(new Request('GET', 'custom://example.com/resource'), new RequestOptions());

        self::assertArrayNotHasKey(CURLOPT_PROTOCOLS, $options);
        self::assertArrayNotHasKey(CURLOPT_REDIR_PROTOCOLS, $options);
    }

    public function testFileSchemeIsNeverReachableFromAnHttpRequest(): void
    {
        $options = $this->mapper->map(new Request('GET', 'http://example.com'), new RequestOptions());

        self::assertSame(0, $options[CURLOPT_PROTOCOLS] & CURLPROTO_FILE);
        self::assertSame(0, $options[CURLOPT_REDIR_PROTOCOLS] & CURLPROTO_FILE);
    }

    public function testEnablesTransparentCompressionForHttpOnly(): void
    {
        $http = $this->mapper->map(new Request('GET', 'https://example.com'), new RequestOptions());
        self::assertSame('', $http[CURLOPT_ENCODING]);

        $ftp = $this->mapper->map(new Request('GET', 'ftp://example.com/f.txt'), new RequestOptions());
        self::assertArrayNotHasKey(CURLOPT_ENCODING, $ftp);
    }

    public function testExplicitEncodingOptionStillOverridesTheDefault(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new Encoding('gzip')])
        );

        self::assertSame('gzip', $options[CURLOPT_ENCODING]);
    }

    public function testMaxResponseSizeMapsToCurlMaxFileSize(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([new MaxResponseSize(4096)])
        );

        self::assertSame(4096, $options[CURLOPT_MAXFILESIZE]);
    }

    public function testAHeadRequestKeepsTheResponseSizeCeiling(): void
    {
        $options = $this->mapper->map(
            new Request('HEAD', 'https://example.com'),
            new RequestOptions([new MaxResponseSize(4096)])
        );

        self::assertTrue($options[CURLOPT_NOBODY]);
        self::assertSame(4096, $options[CURLOPT_MAXFILESIZE]);
    }

    public function testARawCeilingSurvivesARawNoBodyOption(): void
    {
        if (!defined('CURLOPT_MAXFILESIZE_LARGE')) {
            self::markTestSkipped('CURLOPT_MAXFILESIZE_LARGE has no PHP binding before 8.2.');
        }

        $options = $this->mapper->map(
            new Request('GET', 'https://example.com'),
            new RequestOptions([
                new RawCurlOption(CURLOPT_MAXFILESIZE, 4096),
                new RawCurlOption(CURLOPT_MAXFILESIZE_LARGE, 4096),
                new RawCurlOption(CURLOPT_NOBODY, true),
            ])
        );

        self::assertTrue($options[CURLOPT_NOBODY]);
        self::assertSame(4096, $options[CURLOPT_MAXFILESIZE]);
        self::assertSame(4096, $options[CURLOPT_MAXFILESIZE_LARGE]);
    }

    public function testTheCeilingIsKeptWhenNoBodyIsFollowedByAnHttpGetWrite(): void
    {
        $options = $this->mapper->map(
            new Request('HEAD', 'https://example.com'),
            new RequestOptions([
                new MaxResponseSize(4096),
                new RawCurlOption(CURLOPT_HTTPGET, true),
            ])
        );

        self::assertTrue($options[CURLOPT_NOBODY]);
        self::assertTrue($options[CURLOPT_HTTPGET]);
        self::assertSame(4096, $options[CURLOPT_MAXFILESIZE]);
    }

    public function testRejectsAnInjectedHttpMethodFromAForeignRequestImplementation(): void
    {
        $smuggled = new UnvalidatedRequest(
            new Request('GET', 'https://example.com'),
            method: "GET / HTTP/1.1\r\nHost: victim\r\n\r\nDELETE /admin"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->map($smuggled, new RequestOptions());
    }

    public function testRejectsAnInjectedHeaderValueFromAForeignRequestImplementation(): void
    {
        $smuggled = new UnvalidatedRequest(
            new Request('GET', 'https://example.com'),
            headers: ['X-Test' => ["a\r\nX-Injected: yes"]]
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->map($smuggled, new RequestOptions());
    }

    private function stream(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);
        return new Stream($resource);
    }

    public function testABufferedBodySuppressesLibcurlsExpectHeader(): void
    {
        $request = (new Request('POST', '/'))->withBody($this->stream('buffered'));

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertContains('Expect:', $options[CURLOPT_HTTPHEADER]);
    }

    public function testAStreamedBodyLeavesLibcurlsExpectHeaderAlone(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', '/'))->withBody($this->stream($payload));

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertNotContains('Expect:', $options[CURLOPT_HTTPHEADER]);
    }

    public function testACallerSuppliedExpectHeaderIsForwardedUnchanged(): void
    {
        $request = (new Request('POST', '/', ['Expect' => '100-continue']))->withBody($this->stream('buffered'));

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertContains('Expect: 100-continue', $options[CURLOPT_HTTPHEADER]);
        self::assertNotContains('Expect:', $options[CURLOPT_HTTPHEADER]);
    }

    public function testOptionComponentsOverrideDefaults(): void
    {
        $options = $this->mapper->map(
            new Request('GET', '/'),
            new RequestOptions([new HttpVersion('2')])
        );

        self::assertSame(CURL_HTTP_VERSION_2_0, $options[CURLOPT_HTTP_VERSION]);
    }

    #[DataProvider('followingRedirectPolicyProvider')]
    public function testRedirectPolicyCannotFollowAStreamedBody(RedirectPolicy $policy): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', '/'))->withBody($this->stream($payload));

        $options = $this->mapper->map($request, new RequestOptions([$policy]));

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    /** @return iterable<string, array{RedirectPolicy}> */
    public static function followingRedirectPolicyProvider(): iterable
    {
        yield 'follow' => [new RedirectPolicy(follow: true)];
        yield 'followWithCredentials' => [new RedirectPolicy(followWithCredentials: true)];
        yield 'follow and followWithCredentials' => [new RedirectPolicy(follow: true, followWithCredentials: true)];
        yield 'followWithPinnedRequestTarget' => [new RedirectPolicy(followWithPinnedRequestTarget: true)];
        yield 'both opt-ins' => [
            new RedirectPolicy(follow: true, followWithCredentials: true, followWithPinnedRequestTarget: true),
        ];
    }

    #[DataProvider('followingRedirectPolicyProvider')]
    public function testRedirectPolicyStillAppliesToABufferedBody(RedirectPolicy $policy): void
    {
        $request = (new Request('POST', '/'))->withBody($this->stream('small enough to replay'));

        $options = $this->mapper->map($request, new RequestOptions([$policy]));

        self::assertArrayHasKey(CURLOPT_POSTFIELDS, $options);
        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    #[DataProvider('followingRedirectPolicyProvider')]
    public function testRedirectPolicyStillAppliesToABodylessRequest(RedirectPolicy $policy): void
    {
        $options = $this->mapper->map(new Request('GET', '/'), new RequestOptions([$policy]));

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testAStreamedBodyKeepsRedirectsDisabledWithoutAnyFramingHeader(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', '/', ['Transfer-Encoding' => 'chunked']))
            ->withBody($this->stream($payload));

        $options = $this->mapper->map($request, new RequestOptions());

        self::assertSame([], $options[CURLOPT_HTTPHEADER]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    // each guard has its own opt-in; only the streamed-body guard has none at all
    public function testRedirectPolicyStillOverridesTheCredentialGuard(): void
    {
        $options = $this->mapper->map(
            new Request('GET', '/', ['X-Api-Key' => 'secret']),
            new RequestOptions([new RedirectPolicy(followWithCredentials: true)]),
        );

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testTheCredentialOptInDoesNotLiftThePinnedRequestTargetGuard(): void
    {
        $request = (new Request('GET', 'https://example.com/'))->withRequestTarget('/pinned');

        $options = $this->mapper->map(
            $request,
            new RequestOptions([new RedirectPolicy(followWithCredentials: true)]),
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testThePinnedRequestTargetOptInDoesNotLiftTheCredentialGuard(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com/', ['Authorization' => 'Bearer secret']),
            new RequestOptions([new RedirectPolicy(followWithPinnedRequestTarget: true)]),
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testThePinnedRequestTargetOptInLiftsItsOwnGuard(): void
    {
        $request = (new Request('GET', 'https://example.com/'))->withRequestTarget('/pinned');

        $options = $this->mapper->map(
            $request,
            new RequestOptions([new RedirectPolicy(followWithPinnedRequestTarget: true)]),
        );

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testBothOptInsAreNeededWhenBothGuardsFire(): void
    {
        $request = (new Request('GET', 'https://example.com/', ['Authorization' => 'Bearer secret']))
            ->withRequestTarget('/pinned');

        $onlyPinned = $this->mapper->map(
            $request,
            new RequestOptions([new RedirectPolicy(followWithPinnedRequestTarget: true)]),
        );
        $onlyCredentials = $this->mapper->map(
            $request,
            new RequestOptions([new RedirectPolicy(followWithCredentials: true)]),
        );
        $both = $this->mapper->map($request, new RequestOptions([
            new RedirectPolicy(followWithCredentials: true, followWithPinnedRequestTarget: true),
        ]));

        self::assertFalse($onlyPinned[CURLOPT_FOLLOWLOCATION]);
        self::assertFalse($onlyCredentials[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($both[CURLOPT_FOLLOWLOCATION]);
    }

    // Options are an ordered list with no exception: a raw CURLOPT_FOLLOWLOCATION written after the
    // policy passes through untouched, one written before it is overwritten by the policy's verdict.
    public function testARawFollowLocationAfterThePolicyPassesThroughUntouched(): void
    {
        foreach ([null, 'no', false, 0, true, 1] as $rawValue) {
            $options = $this->mapper->map(
                new Request('GET', '/', ['X-Api-Key' => 'secret']),
                new RequestOptions([
                    new RedirectPolicy(followWithCredentials: true),
                    new RawCurlOption(CURLOPT_FOLLOWLOCATION, $rawValue),
                ]),
            );

            self::assertSame($rawValue, $options[CURLOPT_FOLLOWLOCATION]);
        }
    }

    public function testARedirectPolicyAfterARawFollowLocationDecides(): void
    {
        $credentialRequest = new Request('GET', '/', ['X-Api-Key' => 'secret']);

        $guarded = $this->mapper->map($credentialRequest, new RequestOptions([
            new RawCurlOption(CURLOPT_FOLLOWLOCATION, true),
            new RedirectPolicy(),
        ]));
        $optedIn = $this->mapper->map($credentialRequest, new RequestOptions([
            new RawCurlOption(CURLOPT_FOLLOWLOCATION, null),
            new RedirectPolicy(followWithCredentials: true),
        ]));
        $unguarded = $this->mapper->map(new Request('GET', '/'), new RequestOptions([
            new RawCurlOption(CURLOPT_FOLLOWLOCATION, null),
            new RedirectPolicy(),
        ]));

        self::assertFalse($guarded[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($optedIn[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($unguarded[CURLOPT_FOLLOWLOCATION]);
    }

    #[DataProvider('rawFollowLocationOrderProvider')]
    public function testARawCurlOptionAfterThePolicyOverridesTheStreamedBodyGuard(RequestOptions $options): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', '/'))->withBody($this->stream($payload));

        $curlOptions = $this->mapper->map($request, $options);

        self::assertArrayHasKey(CURLOPT_READFUNCTION, $curlOptions);
        self::assertTrue($curlOptions[CURLOPT_FOLLOWLOCATION]);
    }

    /** @return iterable<string, array{RequestOptions}> */
    public static function rawFollowLocationOrderProvider(): iterable
    {
        $raw = new RawCurlOption(CURLOPT_FOLLOWLOCATION, true);
        yield 'alone' => [new RequestOptions([$raw])];
        yield 'after a redirect policy' => [new RequestOptions([new RedirectPolicy(follow: true), $raw])];
    }

    public function testARawCurlOptionBeforeThePolicyDoesNotOverrideTheStreamedBodyGuard(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', '/'))->withBody($this->stream($payload));

        $curlOptions = $this->mapper->map($request, new RequestOptions([
            new RawCurlOption(CURLOPT_FOLLOWLOCATION, true),
            new RedirectPolicy(follow: true),
        ]));

        self::assertFalse($curlOptions[CURLOPT_FOLLOWLOCATION]);
    }

    public function testEveryGuardTheMapperDetectsReachesThePolicy(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', 'https://user:pass@example.com/', ['Authorization' => 'Bearer x']))
            ->withRequestTarget('/pinned')
            ->withBody($this->stream($payload));
        $policy = new RedirectPolicy(followWithCredentials: true, followWithPinnedRequestTarget: true);

        $options = $this->mapper->map($request, new RequestOptions([$policy]));

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testAThirdPartyOptionIsAppliedByTheKeysItReturns(): void
    {
        $option = new class implements CurlOptionInterface {
            public int $calls = 0;

            public function toCurlOptions(): array
            {
                $this->calls++;

                return [CURLOPT_REFERER => 'https://ref.example', CURLOPT_TIMEOUT => 7];
            }
        };

        $options = $this->mapper->map(new Request('GET', 'https://example.com/'), new RequestOptions([$option]));

        self::assertSame(1, $option->calls);
        self::assertSame('https://ref.example', $options[CURLOPT_REFERER]);
        self::assertSame(7, $options[CURLOPT_TIMEOUT]);
    }

    public function testNoGuardReasonOccupiesACurlOptionKey(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', 'https://user:pass@example.com/', ['Authorization' => 'Bearer x']))
            ->withRequestTarget('/pinned')
            ->withBody($this->stream($payload));

        $options = $this->mapper->map($request, new RequestOptions([new RedirectPolicy()]));

        self::assertSame([], array_filter($options, static fn (mixed $value): bool => $value instanceof RedirectGuards));
    }

    public function testARawCurlOptionCannotForgeRedirectGuards(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', 'https://example.com/'))->withBody($this->stream($payload));

        $options = $this->mapper->map($request, new RequestOptions([
            new RawCurlOption(-1, new RedirectGuards(credentials: true)),
            new RedirectPolicy(followWithCredentials: true),
        ]));

        self::assertArrayHasKey(CURLOPT_READFUNCTION, $options);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testARawCurlOptionOnANegativeKeyIsNotDiscarded(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://example.com/'),
            new RequestOptions([new RawCurlOption(CURLOPT_SAFE_UPLOAD, true)]),
        );

        self::assertArrayHasKey(CURLOPT_SAFE_UPLOAD, $options);
        self::assertTrue($options[CURLOPT_SAFE_UPLOAD]);
    }

    public function testARawCurlOptionOnANegativeKeyDoesNotDisarmARedirectOptIn(): void
    {
        $request = new Request('GET', 'https://example.com/', ['Authorization' => 'Bearer secret']);

        $options = $this->mapper->map($request, new RequestOptions([
            new RawCurlOption(CURLOPT_SAFE_UPLOAD, true),
            new RedirectPolicy(followWithCredentials: true),
        ]));

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    #[DataProvider('safeRedirectHeaderProvider')]
    public function testSafeHeadersKeepAutomaticRedirectsEnabled(string $header): void
    {
        $options = $this->mapper->map(new Request('GET', '/', [$header => 'value']), new RequestOptions());

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    /** @return iterable<string, array{string}> */
    public static function safeRedirectHeaderProvider(): iterable
    {
        foreach ([
            'Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language', 'Cache-Control',
            'Connection', 'Content-Length', 'Content-Type', 'Expect', 'If-Match',
            'If-Modified-Since', 'If-None-Match', 'If-Range', 'If-Unmodified-Since', 'Pragma',
            'Range', 'Referer', 'TE', 'User-Agent',
        ] as $header) {
            yield $header => [$header];
        }
    }

    #[DataProvider('credentialHeaderProvider')]
    public function testCredentialHeadersDisableAutomaticRedirects(string $header): void
    {
        $options = $this->mapper->map(new Request('GET', '/', [$header => 'secret']), new RequestOptions());

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    /** @return iterable<string, array{string}> */
    public static function credentialHeaderProvider(): iterable
    {
        foreach (['X-Api-Key', 'Authorization', 'Cookie'] as $header) {
            yield $header => [$header];
        }
    }

    public function testDerivedHostHeaderDoesNotDisableAutomaticRedirects(): void
    {
        $options = $this->mapper->map(new Request('GET', 'https://example.com/'), new RequestOptions());

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testExplicitHostHeaderDisablesAutomaticRedirects(): void
    {
        $request = (new Request('GET', 'https://example.com/'))->withHeader('Host', 'other.example.com');
        $options = $this->mapper->map($request, new RequestOptions());

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testUriUserInfoDisablesAutomaticRedirects(): void
    {
        $options = $this->mapper->map(new Request('GET', 'https://user:pass@example.com/'), new RequestOptions());

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testRedirectPolicyCanExplicitlyFollowWithUriUserInfo(): void
    {
        $options = $this->mapper->map(
            new Request('GET', 'https://user:pass@example.com/'),
            new RequestOptions([new RedirectPolicy(followWithCredentials: true)]),
        );

        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testDefaultRedirectPolicyDoesNotOverrideCredentialProtection(): void
    {
        $options = $this->mapper->map(
            new Request('GET', '/', ['X-Api-Key' => 'secret']),
            new RequestOptions([new RedirectPolicy(follow: true)]),
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testDisabledRedirectPolicyOverridesFollowWithCredentials(): void
    {
        $options = $this->mapper->map(
            new Request('GET', '/', ['X-Api-Key' => 'secret']),
            new RequestOptions([new RedirectPolicy(follow: false, followWithCredentials: true)]),
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testRedirectPolicyOptionOverridesTheDefault(): void
    {
        $options = $this->mapper->map(
            new Request('GET', '/'),
            new RequestOptions([new RedirectPolicy(false)])
        );
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }
}
