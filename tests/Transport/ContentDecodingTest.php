<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Transport\ContentDecoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentDecodingTest extends TestCase
{
    public function testTheAcceptEncodingNameIsAnAliasOfTheEncodingKey(): void
    {
        if (!defined('CURLOPT_ACCEPT_ENCODING')) {
            self::markTestSkipped('CURLOPT_ACCEPT_ENCODING is not exposed by this PHP build.');
        }

        // the helper reads a single key; a second, distinct key would need reading as well
        self::assertSame(CURLOPT_ENCODING, constant('CURLOPT_ACCEPT_ENCODING'));
    }

    /** @param array<int, mixed> $curlOptions */
    #[DataProvider('curlOptionsProvider')]
    public function testReadsWhetherLibcurlDecodesTheBody(array $curlOptions, bool $expected): void
    {
        self::assertSame($expected, ContentDecoding::isEnabled($curlOptions));
    }

    /** @return iterable<string, array{array<int, mixed>, bool}> */
    public static function curlOptionsProvider(): iterable
    {
        yield 'every supported encoding' => [[CURLOPT_ENCODING => ''], true];
        yield 'a written null switches it off' => [[CURLOPT_ENCODING => null], false];
        yield 'content decoding switched off' => [[CURLOPT_ENCODING => '', CURLOPT_HTTP_CONTENT_DECODING => false], false];
        yield 'no encoding key at all' => [[], false];
        yield 'a named encoding' => [[CURLOPT_ENCODING => 'gzip'], true];
        yield 'content decoding switched on explicitly' => [[CURLOPT_ENCODING => '', CURLOPT_HTTP_CONTENT_DECODING => true], true];
        yield 'content decoding without an encoding' => [[CURLOPT_HTTP_CONTENT_DECODING => true], false];
    }
}
