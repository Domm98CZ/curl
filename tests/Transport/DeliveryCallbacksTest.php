<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Domm98CZ\Curl\Tests\Fakes\ShortWriteStreamWrapper;
use Domm98CZ\Curl\Tests\Fakes\ThrowingWriteStreamWrapper;
use Domm98CZ\Curl\Transport\DeliveryCallbacks;
use Domm98CZ\Curl\Transport\HeaderBuffer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use RuntimeException;

final class DeliveryCallbacksTest extends TestCase
{
    private HeaderBuffer $headerBuffer;

    private DeliveryCallbacks $state;

    /** @var resource */
    private $bodyResource;

    /** @var array<int, mixed> */
    private array $curlOptions = [];

    protected function setUp(): void
    {
        $this->headerBuffer = new HeaderBuffer();
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        $this->bodyResource = $resource;
        ThrowingWriteStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->bodyResource)) {
            fclose($this->bodyResource);
        }
        ThrowingWriteStreamWrapper::unregister();
        ShortWriteStreamWrapper::unregister();
    }

    public function testRawHeaderLinesAccumulateInTheHeaderBufferAndAreFullyConsumed(): void
    {
        $header = $this->install(null, null)[0];

        self::assertSame(17, $header(null, "HTTP/1.1 200 OK\r\n"));
        self::assertSame(21, $header(null, "Content-Length: 2\r\n\r\n"));
        self::assertSame("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n", $this->headerBuffer->raw);
    }

    public function testEveryRawHeaderLineReachesTheStreamHandlerVerbatim(): void
    {
        $handler = $this->recordingHandler();
        $header = $this->install($handler, null)[0];

        $header(null, "HTTP/1.1 200 OK\r\n");
        $header(null, "X-Trailing-Space: a \r\n");

        self::assertSame(["HTTP/1.1 200 OK\r\n", "X-Trailing-Space: a \r\n"], $handler->headerLines);
    }

    public function testAThrowingHeaderCallbackIsRecordedAndAbortsTheTransfer(): void
    {
        $failure = new RuntimeException('header callback failed');
        $header = $this->install($this->throwingHandler($failure), null)[0];

        self::assertSame(0, $header(null, "HTTP/1.1 200 OK\r\n"));
        self::assertSame($failure, $this->state->failure);
    }

    public function testTheFirstHeaderFailureIsTheOneThatSurvives(): void
    {
        $first = new RuntimeException('first');
        $handler = new class ($first) implements StreamHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly RuntimeException $first)
            {
            }

            public function onHeaderLine(string $line): void
            {
                $this->calls++;
                throw $this->calls === 1 ? $this->first : new RuntimeException('second');
            }

            public function onChunk(string $chunk): void
            {
            }
        };
        $header = $this->install($handler, null)[0];

        $header(null, "a\r\n");
        $header(null, "b\r\n");

        self::assertSame(2, $handler->calls);
        self::assertSame($first, $this->state->failure);
    }

    public function testTheFirstChunkFailureIsTheOneThatSurvives(): void
    {
        $first = new RuntimeException('first');
        $handler = new class ($first) implements StreamHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly RuntimeException $first)
            {
            }

            public function onHeaderLine(string $line): void
            {
            }

            public function onChunk(string $chunk): void
            {
                $this->calls++;
                throw $this->calls === 1 ? $this->first : new RuntimeException('second');
            }
        };
        $write = $this->install($handler, null)[1];

        $write(null, 'a');
        $write(null, 'b');

        self::assertSame(2, $handler->calls);
        self::assertSame($first, $this->state->failure);
    }

    public function testTheFirstRefusedBodyWriteIsTheOneThatSurvives(): void
    {
        $refusing = fopen(ThrowingWriteStreamWrapper::SCHEME . '://body', 'w');
        self::assertIsResource($refusing);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $refusing, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        self::assertSame(0, $write(null, 'first'));
        self::assertSame(0, $write(null, 'second'));

        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        $previous = $state->failure->getPrevious();
        self::assertInstanceOf(RuntimeException::class, $previous);
        self::assertSame('write #1 refused', $previous->getMessage());
        fclose($refusing);
    }

    public function testAThrowingBodyWriteIsReportedAsALocalBufferFailureThatKeepsItsCause(): void
    {
        $refusing = fopen(ThrowingWriteStreamWrapper::SCHEME . '://body', 'w');
        self::assertIsResource($refusing);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $refusing, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        self::assertSame(0, $write(null, 'hello'));

        // No consumer code runs in this branch, so wrapping cannot destroy an identity anyone was
        // promised — unlike the StreamHandler branches, which must leave their exception untouched.
        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        self::assertInstanceOf(ClientExceptionInterface::class, $state->failure);
        self::assertStringContainsString('writing 5 bytes raised RuntimeException', $state->failure->getMessage());
        self::assertStringContainsString('local response buffer', $state->failure->getMessage());
        self::assertInstanceOf(RuntimeException::class, $state->failure->getPrevious());
        fclose($refusing);
    }

    public function testChunksReachTheStreamHandlerAndAreFullyConsumed(): void
    {
        $handler = $this->recordingHandler();
        $write = $this->install($handler, null)[1];

        self::assertSame(5, $write(null, 'hello'));
        self::assertSame(5, $write(null, 'world'));
        self::assertSame(['hello', 'world'], $handler->chunks);
        self::assertSame('', stream_get_contents($this->bodyResource, offset: 0));
    }

    public function testAThrowingChunkCallbackIsRecordedAndAbortsTheTransfer(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $write = $this->install($this->throwingHandler($failure), null)[1];

        self::assertSame(0, $write(null, 'hello'));
        self::assertSame($failure, $this->state->failure);
    }

    public function testWithoutAHandlerTheBodyIsWrittenToTheBodyResource(): void
    {
        $write = $this->install(null, null)[1];

        self::assertSame(5, $write(null, 'hello'));
        self::assertSame(6, $write(null, ' world'));
        self::assertSame('hello world', stream_get_contents($this->bodyResource, offset: 0));
        self::assertNull($this->state->failure);
    }

    public function testABodyWriteThatAcceptsNoBytesIsRecordedAsALocalBufferFailure(): void
    {
        ShortWriteStreamWrapper::register(withheld: 5);
        $refusing = fopen(ShortWriteStreamWrapper::SCHEME . '://body', 'w');
        self::assertIsResource($refusing);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $refusing, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        // int(0) is what a failing write reports; it used to leave the callback as a legitimate byte
        // count, aborting the transfer with nothing recorded to explain why.
        self::assertSame(0, $write(null, 'hello'));
        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        self::assertInstanceOf(ClientExceptionInterface::class, $state->failure);
        self::assertStringContainsString('0 of 5 bytes written', $state->failure->getMessage());
        fclose($refusing);
    }

    public function testAShortBodyWriteIsRecordedAsALocalBufferFailure(): void
    {
        ShortWriteStreamWrapper::register(withheld: 1);
        $refusing = fopen(ShortWriteStreamWrapper::SCHEME . '://body', 'w');
        self::assertIsResource($refusing);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $refusing, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        self::assertSame(0, $write(null, 'hello'));
        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        self::assertStringContainsString('4 of 5 bytes written', $state->failure->getMessage());
        fclose($refusing);
    }

    public function testABodyWriteRefusedOutrightIsRecordedAsALocalBufferFailure(): void
    {
        $readOnly = fopen('php://temp', 'r');
        self::assertIsResource($readOnly);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $readOnly, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        // fwrite() returns false here without raising anything at all, so the report cannot lean on
        // error_get_last(); the return value is the only evidence there is.
        self::assertSame(0, $write(null, 'hello'));
        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        self::assertStringContainsString('none of 5 bytes written', $state->failure->getMessage());
    }

    public function testALocalBufferFailureNamesTheBufferRatherThanTheNetwork(): void
    {
        ShortWriteStreamWrapper::register(withheld: 5);
        $refusing = fopen(ShortWriteStreamWrapper::SCHEME . '://body', 'w');
        self::assertIsResource($refusing);
        $state = DeliveryCallbacks::install($this->curlOptions, null, $this->headerBuffer, $refusing, null);
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertIsCallable($write);

        $write(null, 'hello');

        self::assertInstanceOf(ResponseBufferException::class, $state->failure);
        $message = $state->failure->getMessage();
        self::assertStringContainsString('buffer the response body locally', $message);
        self::assertStringContainsString('The transfer itself did not fail', $message);
        // error_get_last() may hold anything by the time a write is refused, so it is never presented
        // as the cause.
        self::assertStringNotContainsString('caused by', $message);
        self::assertStringNotContainsString('Cause:', $message);
        fclose($refusing);
    }

    public function testALocalBufferFailureIsNotRetryableWithoutClaimingTheRequestIsAtFault(): void
    {
        $failure = ResponseBufferException::forRefusedWrite(0, 5);

        self::assertInstanceOf(NonRetryableExceptionInterface::class, $failure);
        // Borrowing RequestExceptionInterface would also stop the retry, but it asserts the request
        // was unusable — and this request was sent and answered.
        self::assertNotInstanceOf(RequestExceptionInterface::class, $failure);
    }

    public function testTheCeilingCountsHeaderAndBodyBytesTogether(): void
    {
        [$header, $write] = $this->install(null, 20);
        $state = $this->state;

        self::assertSame(12, $header(null, "X-Pad: aaaa\n"));
        self::assertFalse($state->responseSizeExceeded);
        self::assertSame(12, $state->receivedBytes);

        self::assertSame(0, $write(null, 'nine bytes'));
        self::assertTrue($state->responseSizeExceeded);
        self::assertSame(22, $state->receivedBytes);
    }

    public function testTheCeilingIsCheckedBeforeTheChunkReachesTheBodyBuffer(): void
    {
        $write = $this->install(null, 10)[1];

        self::assertSame(5, $write(null, 'first'));
        self::assertSame(0, $write(null, 'second t'));

        self::assertTrue($this->state->responseSizeExceeded);
        // Writing first and checking afterwards would hold up to one CURL_MAX_WRITE_SIZE chunk more
        // than the caller allowed, which is the whole point of the ceiling.
        self::assertSame('first', stream_get_contents($this->bodyResource, offset: 0));
    }

    public function testACeilingBreachedByHeadersAloneStopsBeforeTheBufferGrowsPastIt(): void
    {
        $header = $this->install(null, 8)[0];

        self::assertSame(0, $header(null, "X-Too-Long: aaaa\r\n"));
        self::assertTrue($this->state->responseSizeExceeded);
        self::assertSame('', $this->headerBuffer->raw);
    }

    // A response that fills the ceiling exactly is still within what the caller allowed; only the byte
    // after it is not. Each callback counts on its own, so each needs its own boundary pair.
    public function testAHeaderLineThatExactlyFillsTheCeilingIsNotRefused(): void
    {
        $header = $this->install(null, 12)[0];

        self::assertSame(12, $header(null, "X-Pad: aaaa\n"));
        self::assertFalse($this->state->responseSizeExceeded);
        self::assertSame("X-Pad: aaaa\n", $this->headerBuffer->raw);
    }

    public function testAHeaderLineOneByteOverTheCeilingIsRefused(): void
    {
        $header = $this->install(null, 11)[0];

        self::assertSame(0, $header(null, "X-Pad: aaaa\n"));
        self::assertTrue($this->state->responseSizeExceeded);
    }

    public function testAChunkThatExactlyFillsTheCeilingReachesTheStreamHandler(): void
    {
        $handler = $this->recordingHandler();
        $write = $this->install($handler, 5)[1];

        self::assertSame(5, $write(null, 'hello'));
        self::assertFalse($this->state->responseSizeExceeded);
        self::assertSame(['hello'], $handler->chunks);
    }

    public function testAChunkOverTheCeilingNeverReachesTheStreamHandler(): void
    {
        $handler = $this->recordingHandler();
        $write = $this->install($handler, 5)[1];

        self::assertSame(5, $write(null, 'hello'));
        self::assertSame(0, $write(null, 'x'));

        self::assertTrue($this->state->responseSizeExceeded);
        self::assertSame(['hello'], $handler->chunks);
    }

    public function testAChunkThatExactlyFillsTheCeilingReachesTheBodyBuffer(): void
    {
        $write = $this->install(null, 5)[1];

        self::assertSame(5, $write(null, 'hello'));
        self::assertFalse($this->state->responseSizeExceeded);
        self::assertSame('hello', stream_get_contents($this->bodyResource, offset: 0));
    }

    public function testWithoutACeilingNoBytesAreCounted(): void
    {
        [$header, $write] = $this->install(null, null);

        $header(null, "X-Pad: aaaa\n");
        $write(null, 'hello');

        self::assertSame(0, $this->state->receivedBytes);
        self::assertFalse($this->state->responseSizeExceeded);
    }

    /** @return array{\Closure, \Closure} */
    private function install(?StreamHandlerInterface $handler, ?int $maximumResponseSize): array
    {
        $this->state = DeliveryCallbacks::install(
            $this->curlOptions,
            $handler,
            $this->headerBuffer,
            $this->bodyResource,
            $maximumResponseSize,
        );

        $header = $this->curlOptions[CURLOPT_HEADERFUNCTION];
        $write = $this->curlOptions[CURLOPT_WRITEFUNCTION];
        self::assertInstanceOf(\Closure::class, $header);
        self::assertInstanceOf(\Closure::class, $write);

        return [$header, $write];
    }

    private function recordingHandler(): StreamHandlerInterface
    {
        return new class implements StreamHandlerInterface {
            /** @var string[] */
            public array $headerLines = [];

            /** @var string[] */
            public array $chunks = [];

            public function onHeaderLine(string $line): void
            {
                $this->headerLines[] = $line;
            }

            public function onChunk(string $chunk): void
            {
                $this->chunks[] = $chunk;
            }
        };
    }

    private function throwingHandler(RuntimeException $failure): StreamHandlerInterface
    {
        return new class ($failure) implements StreamHandlerInterface {
            public function __construct(private readonly RuntimeException $failure)
            {
            }

            public function onHeaderLine(string $line): void
            {
                throw $this->failure;
            }

            public function onChunk(string $chunk): void
            {
                throw $this->failure;
            }
        };
    }
}
