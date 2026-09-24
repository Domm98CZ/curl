<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\RedirectGuards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testMaxResponseSizeRejectsNonPositiveValues(): void
    {
        foreach ([0, -1] as $bytes) {
            try {
                new MaxResponseSize($bytes);
                self::fail(sprintf('Expected %d bytes to be rejected.', $bytes));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHttpVersionMapsKnownVersions(): void
    {
        self::assertSame([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1], (new HttpVersion('1.1'))->toCurlOptions());
        self::assertSame([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0], (new HttpVersion('2'))->toCurlOptions());
    }

    public function testHttpVersionRejectsUnknownVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpVersion('0.9');
    }

    public function testSslVerificationContributesBothFlags(): void
    {
        self::assertSame(
            [CURLOPT_SSL_VERIFYPEER => 1, CURLOPT_SSL_VERIFYHOST => 2],
            (new SslVerification(true, true))->toCurlOptions()
        );
        self::assertSame(
            [CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0],
            (new SslVerification(false, false))->toCurlOptions()
        );
    }

    public function testRedirectPolicyIsAPlainCurlOption(): void
    {
        self::assertInstanceOf(CurlOptionInterface::class, new RedirectPolicy());
    }

    // Following is decided by the mapper, which alone holds the guards; an option applied anywhere
    // else leaves the key absent and libcurl at its no-follow default.
    public function testRedirectPolicyWritesOnlyTheRedirectCeiling(): void
    {
        self::assertSame([CURLOPT_MAXREDIRS => 20], (new RedirectPolicy())->toCurlOptions());
        self::assertSame([CURLOPT_MAXREDIRS => 5], (new RedirectPolicy(follow: false, maxRedirects: 5))->toCurlOptions());
    }

    public function testRedirectPolicyFollowsByDefaultWhenNoGuardFired(): void
    {
        self::assertTrue((new RedirectPolicy())->allowsFollowing(new RedirectGuards()));
        self::assertFalse((new RedirectPolicy(follow: false))->allowsFollowing(new RedirectGuards()));
    }

    public function testDisabledFollowingOverridesEveryOptIn(): void
    {
        $policy = new RedirectPolicy(follow: false, followWithCredentials: true, followWithPinnedRequestTarget: true);

        self::assertFalse($policy->allowsFollowing(new RedirectGuards()));
        self::assertFalse($policy->allowsFollowing(new RedirectGuards(credentials: true)));
    }

    #[DataProvider('liftedGuardProvider')]
    public function testAMatchingOptInLiftsItsOwnGuard(RedirectGuards $guards, RedirectPolicy $policy): void
    {
        self::assertTrue($policy->allowsFollowing($guards));
    }

    /** @return iterable<string, array{RedirectGuards, RedirectPolicy}> */
    public static function liftedGuardProvider(): iterable
    {
        yield 'credentials' => [
            new RedirectGuards(credentials: true),
            new RedirectPolicy(followWithCredentials: true),
        ];
        yield 'pinned target' => [
            new RedirectGuards(pinnedRequestTarget: true),
            new RedirectPolicy(followWithPinnedRequestTarget: true),
        ];
        yield 'both guards with both opt-ins' => [
            new RedirectGuards(credentials: true, pinnedRequestTarget: true),
            new RedirectPolicy(followWithCredentials: true, followWithPinnedRequestTarget: true),
        ];
    }

    #[DataProvider('standingGuardProvider')]
    public function testAGuardStandsWithoutItsOwnOptIn(RedirectGuards $guards, RedirectPolicy $policy): void
    {
        self::assertFalse($policy->allowsFollowing($guards));
    }

    /** @return iterable<string, array{RedirectGuards, RedirectPolicy}> */
    public static function standingGuardProvider(): iterable
    {
        yield 'credentials, no opt-in' => [
            new RedirectGuards(credentials: true),
            new RedirectPolicy(follow: true),
        ];
        yield 'pinned target, no opt-in' => [
            new RedirectGuards(pinnedRequestTarget: true),
            new RedirectPolicy(follow: true),
        ];
        yield 'credential opt-in against a pinned target' => [
            new RedirectGuards(pinnedRequestTarget: true),
            new RedirectPolicy(followWithCredentials: true),
        ];
        yield 'pinned-target opt-in against credentials' => [
            new RedirectGuards(credentials: true),
            new RedirectPolicy(followWithPinnedRequestTarget: true),
        ];
        yield 'both guards, only the credential opt-in' => [
            new RedirectGuards(credentials: true, pinnedRequestTarget: true),
            new RedirectPolicy(followWithCredentials: true),
        ];
        yield 'both guards, only the pinned-target opt-in' => [
            new RedirectGuards(credentials: true, pinnedRequestTarget: true),
            new RedirectPolicy(followWithPinnedRequestTarget: true),
        ];
        yield 'a streamed body has no opt-in at all' => [
            new RedirectGuards(streamedBody: true),
            new RedirectPolicy(followWithCredentials: true, followWithPinnedRequestTarget: true),
        ];
        yield 'every guard with every opt-in' => [
            new RedirectGuards(credentials: true, streamedBody: true, pinnedRequestTarget: true),
            new RedirectPolicy(followWithCredentials: true, followWithPinnedRequestTarget: true),
        ];
    }
}
