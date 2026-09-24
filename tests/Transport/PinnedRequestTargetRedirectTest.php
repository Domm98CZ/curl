<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use PHPUnit\Framework\TestCase;

final class PinnedRequestTargetRedirectTest extends TestCase
{
    private static TestServer $source;
    private static TestServer $target;

    public static function setUpBeforeClass(): void
    {
        self::$source = new TestServer(8115);
        self::$target = new TestServer(8116);
        self::$source->start();
        self::$target->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$source->stop();
        self::$target->stop();
    }

    protected function setUp(): void
    {
        self::$source->clearRequests();
        self::$target->clearRequests();
    }

    // CURLOPT_REQUEST_TARGET is per-handle: without this guard libcurl replays the target pinned for the
    // source origin against the redirect target, handing it whatever the path carries.
    public function testAPinnedRequestTargetIsNotReplayedAgainstTheRedirectTarget(): void
    {
        $response = (new Client())->send($this->pinnedRedirectRequest());

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, self::$source->requests());
        self::assertStringContainsString('api_key=SUPER_SECRET', self::$source->requests()[0]);
        self::assertSame([], self::$target->requests());
    }

    public function testTheSameRedirectIsFollowedWhenNoRequestTargetIsPinned(): void
    {
        $request = new Request('GET', self::$source->baseUrl . $this->redirectPath());

        $response = (new Client())->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('target-ok', (string) $response->getBody());
        self::assertCount(1, self::$target->requests());
    }

    // The escape hatch stays available, and what it lets through is exactly what the default prevents:
    // the target origin receives the path pinned for the source origin, secret and all.
    public function testAnExplicitRedirectPolicyOptsBackInAtTheCallersOwnRisk(): void
    {
        try {
            (new Client())->send(
                $this->pinnedRedirectRequest(),
                new RequestOptions([new RedirectPolicy(maxRedirects: 1, followWithPinnedRequestTarget: true)]),
            );
        } catch (NetworkException) {
            // the replayed target makes the second hop redirect to itself, so MAXREDIRS trips
        }

        self::assertCount(1, self::$target->requests());
        self::assertStringContainsString('api_key=SUPER_SECRET', self::$target->requests()[0]);
    }

    public function testTheCredentialOptInDoesNotReachThePinnedTargetGuard(): void
    {
        $response = (new Client())->send(
            $this->pinnedRedirectRequest(),
            new RequestOptions([new RedirectPolicy(followWithCredentials: true)]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], self::$target->requests());
    }

    private function pinnedRedirectRequest(): Request
    {
        return (new Request('GET', self::$source->baseUrl . '/'))
            ->withRequestTarget($this->redirectPath());
    }

    private function redirectPath(): string
    {
        return '/redirect-to?api_key=SUPER_SECRET&url=' . rawurlencode(self::$target->baseUrl . '/hit');
    }
}
