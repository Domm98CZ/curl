<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\KeepAliveTestServer;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// The rest of the integration suite runs against PHP's built-in dev server, which closes the
// connection after each response. That close doubles as an end-of-message signal and hides a HEAD
// exchange in which libcurl is still waiting for a response body, so this class needs a server
// that keeps the connection open.
final class HeadOverKeepAliveTest extends TestCase
{
    private const TRANSFER_TIMEOUT_SECONDS = 5;
    private const HANG_THRESHOLD_SECONDS = 2.0;

    private static KeepAliveTestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new KeepAliveTestServer(8114);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testAPlainHeadCompletesWithoutWaitingForABody(): void
    {
        $elapsed = $this->timeHead(new Request('HEAD', self::$server->baseUrl . '/x'));

        self::assertLessThan(self::HANG_THRESHOLD_SECONDS, $elapsed);
    }

    #[DataProvider('headBodySizeProvider')]
    public function testAHeadCarryingABodyCompletesWithoutWaitingForABody(int $size): void
    {
        $request = (new Request('HEAD', self::$server->baseUrl . '/x'))
            ->withBody($this->stream(str_repeat('a', $size)));

        $elapsed = $this->timeHead($request);

        self::assertLessThan(self::HANG_THRESHOLD_SECONDS, $elapsed);
    }

    /** @return iterable<string, array{int}> */
    public static function headBodySizeProvider(): iterable
    {
        yield 'buffered body' => [7];
        yield 'streamed body' => [CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1];
    }

    private function timeHead(Request $request): float
    {
        $options = new RequestOptions([
            new Timeout(self::TRANSFER_TIMEOUT_SECONDS),
            new ConnectTimeout(2),
        ]);

        $start = microtime(true);
        $response = (new Client())->send($request, $options);
        $elapsed = microtime(true) - $start;

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HEAD', $response->getHeaderLine('X-Echo-Method'));
        self::assertSame('2097152', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());

        return $elapsed;
    }

    private function stream(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to open a temporary stream.');
        }
        fwrite($resource, $contents);
        rewind($resource);

        return new Stream($resource);
    }
}
