<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Tests\Fixtures\TlsTestServer;
use PHPUnit\Framework\TestCase;

final class ProtocolRedirectIntegrationTest extends TestCase
{
    private static TestServer $http;
    private static TlsTestServer $https;

    public static function setUpBeforeClass(): void
    {
        self::$http = new TestServer(8112);
        self::$https = new TlsTestServer(8113);
        self::$http->start();
        self::$https->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$http->stop();
        self::$https->stop();
    }

    public function testHttpsRedirectToHttpFailsWithoutReachingTheTarget(): void
    {
        self::$http->clearRequests();
        self::$https->clearRequests();
        $target = self::$http->baseUrl . '/hit';
        $request = new Request('GET', self::$https->baseUrl . '/redirect-to?url=' . rawurlencode($target));

        try {
            (new Client())->send($request, $this->insecureLocalTlsOptions());
            self::fail('Expected an HTTPS-to-HTTP redirect to be rejected.');
        } catch (RequestException $exception) {
            self::assertSame(CURLE_UNSUPPORTED_PROTOCOL, $exception->getCurlErrno());
        }

        self::assertCount(1, self::$https->requests());
        self::assertSame([], self::$http->requests());
    }

    public function testHttpRedirectToHttpsStillSucceeds(): void
    {
        self::$http->clearRequests();
        self::$https->clearRequests();
        $target = self::$https->baseUrl . '/hit';
        $request = new Request('GET', self::$http->baseUrl . '/redirect-to?url=' . rawurlencode($target));

        $response = (new Client())->send($request, $this->insecureLocalTlsOptions());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tls-target-ok', (string) $response->getBody());
        self::assertCount(1, self::$http->requests());
        self::assertCount(1, self::$https->requests());
    }

    private function insecureLocalTlsOptions(): RequestOptions
    {
        return new RequestOptions([new SslVerification(false, false)]);
    }
}
