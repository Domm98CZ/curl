<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Options\LowSpeedLimit;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\Options\Timeout;
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

    public function testTimeoutsWriteOnlyTheMillisecondKey(): void
    {
        self::assertSame([CURLOPT_TIMEOUT_MS => 5000], Timeout::seconds(5)->toCurlOptions());
        self::assertSame([CURLOPT_TIMEOUT_MS => 3500], Timeout::milliseconds(3500)->toCurlOptions());
        self::assertSame([CURLOPT_CONNECTTIMEOUT_MS => 2000], ConnectTimeout::seconds(2)->toCurlOptions());
        self::assertSame([CURLOPT_CONNECTTIMEOUT_MS => 250], ConnectTimeout::milliseconds(250)->toCurlOptions());
    }

    public function testZeroSecondsStaysTheDocumentedSentinel(): void
    {
        self::assertSame([CURLOPT_TIMEOUT_MS => 0], Timeout::seconds(0)->toCurlOptions());
        self::assertSame([CURLOPT_CONNECTTIMEOUT_MS => 0], ConnectTimeout::seconds(0)->toCurlOptions());
    }

    #[DataProvider('rejectedTimeoutProvider')]
    public function testTimeoutsRejectValuesThatAreNotABudget(\Closure $construct): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $construct();
    }

    /** @return iterable<string, array{\Closure}> */
    public static function rejectedTimeoutProvider(): iterable
    {
        yield 'negative seconds' => [static fn () => Timeout::seconds(-1)];
        yield 'zero milliseconds' => [static fn () => Timeout::milliseconds(0)];
        yield 'negative milliseconds' => [static fn () => Timeout::milliseconds(-1)];
        yield 'negative connect seconds' => [static fn () => ConnectTimeout::seconds(-1)];
        yield 'zero connect milliseconds' => [static fn () => ConnectTimeout::milliseconds(0)];
        yield 'negative connect milliseconds' => [static fn () => ConnectTimeout::milliseconds(-1)];
    }

    public function testLowSpeedLimitWritesRateAndWindow(): void
    {
        self::assertSame(
            [CURLOPT_LOW_SPEED_LIMIT => 100, CURLOPT_LOW_SPEED_TIME => 15],
            (new LowSpeedLimit(100, 15))->toCurlOptions()
        );
        self::assertSame(
            [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 30],
            LowSpeedLimit::stalledFor(30)->toCurlOptions()
        );
    }

    public function testLowSpeedLimitRejectsADisabledRateOrWindow(): void
    {
        foreach ([[0, 10], [10, 0], [-1, 10], [10, -1]] as [$rate, $seconds]) {
            try {
                new LowSpeedLimit($rate, $seconds);
                self::fail(sprintf('Expected %d B/s over %d s to be rejected.', $rate, $seconds));
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
