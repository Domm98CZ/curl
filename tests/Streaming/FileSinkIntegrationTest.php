<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Streaming;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Streaming\FileSink;
use Domm98CZ\Curl\Tests\Fakes\BoundedMultiTransport;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use PHPUnit\Framework\TestCase;

final class FileSinkIntegrationTest extends TestCase
{
    private static TestServer $server;
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8160);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/curl-download-wire-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testDownloadsALargeBodyByteForByte(): void
    {
        $path = $this->directory . '/large.bin';

        $response = RequestBuilder::get(self::$server->baseUrl . '/large-plain')->downloadTo($path);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(2_097_152, filesize($path));
        self::assertSame(hash('sha256', str_repeat('a', 2_097_152)), hash_file('sha256', $path));
        self::assertSame([$path], glob($this->directory . '/*'));
    }

    public function testAnErrorStatusIsStoredNotThrown(): void
    {
        $path = $this->directory . '/missing.html';

        $response = RequestBuilder::get(self::$server->baseUrl . '/definitely-missing')->downloadTo($path);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not-found', file_get_contents($path));
    }

    public function testTheSinkComposesWithTheAsyncClient(): void
    {
        $path = $this->directory . '/async.bin';
        $sink = new FileSink($path);
        $client = new AsyncClient(new BoundedMultiTransport(new CurlMultiTransport(), deadlineSeconds: 10.0));

        $promise = $client->sendAsync(
            new Request('GET', self::$server->baseUrl . '/large-plain'),
            new RequestOptions([], null, $sink),
        );
        $response = $promise->wait();
        $sink->commit();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(hash('sha256', str_repeat('a', 2_097_152)), hash_file('sha256', $path));
    }
}
