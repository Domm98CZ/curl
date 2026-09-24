<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fakes\OptionsInjectingMultiTransport;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RedirectPolicyIntegrationTest extends TestCase
{
    private static TestServer $source;
    private static TestServer $target;

    public static function setUpBeforeClass(): void
    {
        self::$source = new TestServer(8110);
        self::$target = new TestServer(8111);
        self::$source->start();
        self::$target->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$source->stop();
        self::$target->stop();
    }

    #[DataProvider('redirectCaseProvider')]
    public function testCredentialRedirectPolicyAcrossSyncAsyncAndPool(
        string $path,
        bool $withCredential,
        ?RedirectPolicy $policy,
        int $expectedStatus,
        int $expectedTargetHits,
    ): void {
        self::$source->clearRequests();
        self::$target->clearRequests();

        $headers = $withCredential ? ['X-Api-Key' => 'secret'] : [];
        $target = self::$target->baseUrl . '/hit';
        $request = new Request('GET', self::$source->baseUrl . '/redirect-to?url=' . rawurlencode($target), $headers);
        $options = $policy !== null ? new RequestOptions([$policy]) : null;

        $response = $this->sendThrough($path, $request, $options);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertCount(1, self::$source->requests());
        self::assertCount($expectedTargetHits, self::$target->requests());
        if ($expectedTargetHits === 1) {
            self::assertSame('target-ok', (string) $response->getBody());
        }
    }

    /** @return iterable<string, array{string, bool, ?RedirectPolicy, int, int}> */
    public static function redirectCaseProvider(): iterable
    {
        foreach (['sync', 'async', 'pool'] as $path) {
            yield $path . ': credential default' => [$path, true, null, 302, 0];
            yield $path . ': no credential default' => [$path, false, null, 200, 1];
            yield $path . ': explicit follow remains conditional' => [$path, true, new RedirectPolicy(follow: true), 302, 0];
            yield $path . ': credential opt-in' => [$path, true, new RedirectPolicy(followWithCredentials: true), 200, 1];
        }
    }

    #[DataProvider('userinfoRedirectCaseProvider')]
    public function testUserInfoRedirectPolicyAcrossSyncAsyncAndPool(
        string $path,
        ?RedirectPolicy $policy,
        int $expectedStatus,
        int $expectedTargetHits,
    ): void {
        self::$source->clearRequests();
        self::$target->clearRequests();

        $source = str_replace('http://', 'http://user:pass@', self::$source->baseUrl);
        $target = self::$target->baseUrl . '/hit';
        $request = new Request('GET', $source . '/redirect-to?url=' . rawurlencode($target));
        $options = $policy !== null ? new RequestOptions([$policy]) : null;

        $response = $this->sendThrough($path, $request, $options);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertCount(1, self::$source->requests());
        self::assertCount($expectedTargetHits, self::$target->requests());
        if ($expectedTargetHits === 1) {
            self::assertSame('target-ok', (string) $response->getBody());
        }
    }

    /** @return iterable<string, array{string, ?RedirectPolicy, int, int}> */
    public static function userinfoRedirectCaseProvider(): iterable
    {
        foreach (['sync', 'async', 'pool'] as $path) {
            yield $path . ': userinfo default' => [$path, null, 302, 0];
            yield $path . ': userinfo opt-in' => [$path, new RedirectPolicy(followWithCredentials: true), 200, 1];
        }
    }

    // A raw CURLOPT_FOLLOWLOCATION written after the policy reaches curl_setopt untouched, so whatever
    // that call does to the value is what decides the wire behaviour, with or without a policy ahead
    // of it in the chain.
    #[DataProvider('rawFollowLocationWireValueProvider')]
    public function testTheEscapeHatchAgreesWithLibcurlOnTheWire(mixed $rawValue, bool $expectedToFollow): void
    {
        $raw = new RawCurlOption(CURLOPT_FOLLOWLOCATION, $rawValue);

        $libcurlReading = $this->followedHits(new RequestOptions([$raw]));
        $policyReading = $this->followedHits(new RequestOptions([new RedirectPolicy(), $raw]));

        self::assertSame($expectedToFollow, $libcurlReading);
        self::assertSame($libcurlReading, $policyReading);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function rawFollowLocationWireValueProvider(): iterable
    {
        // Through 8.4 CURLOPT_FOLLOWLOCATION was the single long option curl_setopt() still read with
        // zend_is_true(); 8.5 removed that exception, so values truthy but numerically below one now
        // reach libcurl as 0 instead of 1.
        $truthinessDecides = PHP_VERSION_ID < 80500;

        yield 'boolean true follows' => [true, true];
        yield 'integer 1 follows' => [1, true];
        yield 'string one follows' => ['1', true];
        yield 'non-numeric string follows' => ['no', $truthinessDecides];
        yield 'string with a numeric prefix follows' => ['2abc', true];
        yield 'float below one follows' => [0.5, $truthinessDecides];
        yield 'array holding a zero follows' => [[0], true];
        yield 'boolean false does not follow' => [false, false];
        yield 'integer 0 does not follow' => [0, false];
        yield 'string zero does not follow' => ['0', false];
        yield 'empty string does not follow' => ['', false];
        yield 'null does not follow' => [null, false];
        yield 'float zero does not follow' => [0.0, false];
        yield 'empty array does not follow' => [[], false];
    }

    private function followedHits(OptionsInterface $options): bool
    {
        self::$source->clearRequests();
        self::$target->clearRequests();

        // the credential header makes the mapper write its own block first, so the raw value below is
        // overriding a real guard rather than an untouched default
        $request = new Request(
            'GET',
            self::$source->baseUrl . '/redirect-to?url=' . rawurlencode(self::$target->baseUrl . '/hit'),
            ['X-Api-Key' => 'secret'],
        );

        $response = (new Client())->send($request, $options);
        $followed = self::$target->requests() !== [];

        self::assertSame($followed ? 200 : 302, $response->getStatusCode());

        return $followed;
    }

    private function sendThrough(string $path, RequestInterface $request, ?OptionsInterface $options): ResponseInterface
    {
        if ($path === 'sync') {
            return (new Client())->send($request, $options);
        }
        if ($path === 'async') {
            return (new AsyncClient())->sendAsync($request, $options)->wait();
        }

        $pool = new Pool(new OptionsInjectingMultiTransport(new CurlMultiTransport(), [], $options));
        $result = $pool->send([$request])[0];
        self::assertInstanceOf(ResponseInterface::class, $result);
        return $result;
    }
}
