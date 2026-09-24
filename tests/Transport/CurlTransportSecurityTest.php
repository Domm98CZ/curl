<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;

final class CurlTransportSecurityTest extends TestCase
{
    private static TestServer $server;
    private CurlTransport $transport;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8102);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->transport = new CurlTransport();
    }

    public function testTheClientRefusesToWriteALocalFile(): void
    {
        $target = sys_get_temp_dir() . '/curl-protocol-probe-' . bin2hex(random_bytes(4)) . '.txt';

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'written-by-probe');
        rewind($resource);

        $request = (new Request('PUT', 'file://' . $target))->withBody(new Stream($resource));

        try {
            (new Client())->sendRequest($request);
            self::fail('Expected the file scheme to be refused.');
        } catch (RequestException) {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testTheClientRefusesEveryNonHttpScheme(): void
    {
        foreach (['file', 'ftp', 'gopher', 'dict', 'ldap', 'scp'] as $scheme) {
            try {
                (new Client())->sendRequest(new Request('GET', $scheme . '://example.com/x'));
                self::fail(sprintf('Expected scheme "%s" to be refused.', $scheme));
            } catch (RequestException $exception) {
                self::assertStringContainsString($scheme, $exception->getMessage());
            }
        }
    }

    public function testAnHttpRequestCannotBeRedirectedIntoTheFileScheme(): void
    {
        $raw = $this->transport->execute(
            new Request('GET', self::$server->baseUrl . '/redirect-to-file'),
            new RequestOptions()
        );

        self::assertTrue($raw->isTransportError());
        self::assertStringNotContainsString('root', (string) $raw->body);
    }

    public function testSendRawStillReachesItsOwnSchemeDeliberately(): void
    {
        $raw = $this->transport->execute(new Request('GET', 'file:///etc/hostname'), new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertNotSame('', (string) $raw->body);
    }

    public function testARejectedCurlOptionNeverLetsTheRequestGoOutHalfConfigured(): void
    {
        // curl_setopt_array() would have stopped at this option and silently skipped every option
        // after it, including a protocol allowlist or SSL_VERIFYPEER, then sent the request anyway.
        $options = new RequestOptions([new RawCurlOption(CURLOPT_PROXYTYPE, 123456)]);

        try {
            $this->transport->execute(new Request('GET', self::$server->baseUrl . '/ok'), $options);
            self::fail('Expected the rejected option to abort the transfer.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('the request was not sent', $exception->getMessage());
            self::assertInstanceOf(\Psr\Http\Client\ClientExceptionInterface::class, $exception);
        }
    }
}
