<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\Options\Encoding;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RedirectGuards;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\TestCase;
use TypeError;

final class RequestOptionsTest extends TestCase
{
    /** @return array<int, mixed> */
    private function mapped(RequestOptions $options): array
    {
        return (new CurlOptionsMapper())->map(new Request('GET', 'https://example.com/'), $options);
    }

    public function testAllReturnsConstructorOptions(): void
    {
        $options = new RequestOptions([new Timeout(5), new Encoding('gzip')]);
        self::assertCount(2, $options->all());
    }

    public function testWithOptionAddsWithoutMutatingOriginal(): void
    {
        $original = new RequestOptions([]);
        $withTimeout = $original->withOption(new Timeout(5));

        self::assertCount(0, $original->all());
        self::assertCount(1, $withTimeout->all());
    }

    public function testWithOptionAppliesSameClassInOrderWithTheLastValueWinning(): void
    {
        $options = (new RequestOptions([new Timeout(5)]))->withOption(new Timeout(10));

        self::assertCount(2, $options->all());
        self::assertSame(10, $this->mapped($options)[CURLOPT_TIMEOUT]);
    }

    public function testSameRawCurlOptionUsesTheLaterValue(): void
    {
        $options = new RequestOptions([
            new RawCurlOption(CURLOPT_REFERER, 'https://first.example'),
            new RawCurlOption(CURLOPT_REFERER, 'https://second.example'),
        ]);

        self::assertSame('https://second.example', $this->mapped($options)[CURLOPT_REFERER]);
    }

    public function testALaterRedirectPolicyWinsOverAnEarlierOne(): void
    {
        $options = new RequestOptions([
            new RedirectPolicy(follow: false, maxRedirects: 5),
            new RedirectPolicy(follow: true, maxRedirects: 10),
        ]);

        $curlOptions = $this->mapped($options);
        self::assertTrue($curlOptions[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(10, $curlOptions[CURLOPT_MAXREDIRS]);

        $reversed = $this->mapped(new RequestOptions([
            new RedirectPolicy(follow: true, maxRedirects: 10),
            new RedirectPolicy(follow: false, maxRedirects: 5),
        ]));
        self::assertFalse($reversed[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(5, $reversed[CURLOPT_MAXREDIRS]);
    }

    public function testConstructorRejectsAValueThatIsNotACurlOption(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be a list of');

        /** @phpstan-ignore argument.type */
        new RequestOptions([new Timeout(5), new RedirectGuards(credentials: true)]);
    }

    public function testConstructorNamesTheOffendingType(): void
    {
        try {
            /** @phpstan-ignore argument.type */
            new RequestOptions(['not-an-option']);
            self::fail('The constructor must reject a value that is not a CurlOptionInterface.');
        } catch (TypeError $error) {
            self::assertStringContainsString('string given', $error->getMessage());
        }
    }

    public function testTransferInfoCollectorDefaultsToNull(): void
    {
        self::assertNull((new RequestOptions([]))->getTransferInfoCollector());
    }

    public function testTransferInfoCollectorIsExposed(): void
    {
        $collector = new TransferInfoCollector();
        $options = new RequestOptions([], $collector);
        self::assertSame($collector, $options->getTransferInfoCollector());
    }
}
