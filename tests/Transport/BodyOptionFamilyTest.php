<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Transport\RequestBodyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The replay verdict is a denylist of CURLOPT_* keys, so it is fail-open towards a key nobody has
 * classified yet. This test turns that into a build failure: every CURLOPT_* the running PHP
 * binding exposes must appear in the pinned fixture, and every name that hints at a request body
 * must be either in the family or in the reviewed list of look-alikes that send no body.
 */
final class BodyOptionFamilyTest extends TestCase
{
    private const BODY_FAMILY = [
        'CURLOPT_POSTFIELDS', 'CURLOPT_INFILE', 'CURLOPT_INFILESIZE', 'CURLOPT_INFILESIZE_LARGE',
        'CURLOPT_READDATA', 'CURLOPT_READFUNCTION', 'CURLOPT_UPLOAD', 'CURLOPT_PUT', 'CURLOPT_POST',
        'CURLOPT_CUSTOMREQUEST', 'CURLOPT_NOBODY', 'CURLOPT_HTTPGET',
    ];

    // Names matching the body pattern that were reviewed and put no bytes on the wire as a body:
    // mime encoding flags, FTP post-transfer commands, method after a 3xx, a no-op, a buffer size.
    private const REVIEWED_NOT_A_BODY = [
        'CURLOPT_MIME_OPTIONS', 'CURLOPT_POSTQUOTE', 'CURLOPT_POSTREDIR', 'CURLOPT_SAFE_UPLOAD',
        'CURLOPT_UPLOAD_BUFFERSIZE',
    ];

    private const BODY_NAME_PATTERN = '/POST|UPLOAD|INFILE|READ|MIME|FORM|BODY|DATA|FIELDS|SEEK|COPY/';

    public function testEveryCurlOptionOfThisBindingIsPinnedInTheFixture(): void
    {
        $unknown = array_diff(self::definedOptionNames(), self::pinnedOptionNames());

        self::assertSame([], array_values($unknown), sprintf(
            'PHP %s exposes CURLOPT_* constants that nobody has classified yet; decide whether each one '
            . 'can carry a request body, then add it to tests/Fixtures/curlopt-constants.txt (and to the '
            . 'body family in RequestBodyPolicy if it can).',
            PHP_VERSION,
        ));
    }

    public function testTheFamilyAndTheReviewedListAreThemselvesPinned(): void
    {
        $pinned = self::pinnedOptionNames();

        self::assertSame([], array_values(array_diff(self::BODY_FAMILY, $pinned)));
        self::assertSame([], array_values(array_diff(self::REVIEWED_NOT_A_BODY, $pinned)));
    }

    public function testEveryPinnedNameThatLooksLikeABodyIsClassified(): void
    {
        $lookAlikes = array_values(array_filter(
            self::pinnedOptionNames(),
            static fn (string $name): bool => preg_match(self::BODY_NAME_PATTERN, $name) === 1,
        ));
        $classified = [...self::BODY_FAMILY, ...self::REVIEWED_NOT_A_BODY];

        self::assertSame([], array_values(array_diff($lookAlikes, $classified)));
    }

    #[DataProvider('definedOptionProvider')]
    public function testTheVerdictDegradesExactlyForTheFamily(string $name, int $key): void
    {
        self::assertSame(
            in_array($name, self::BODY_FAMILY, true),
            RequestBodyPolicy::writesBody([$key => 'x']),
            $name,
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function definedOptionProvider(): iterable
    {
        foreach (self::definedOptions() as $name => $key) {
            yield $name => [$name, $key];
        }
    }

    /** @return list<string> */
    private static function definedOptionNames(): array
    {
        return array_keys(self::definedOptions());
    }

    // Values are read off the constant table rather than through constant(): resolving a deprecated
    // name such as CURLOPT_BINARYTRANSFER by name raises a deprecation the suite treats as a failure.
    /** @return array<string, int> */
    private static function definedOptions(): array
    {
        $options = [];
        foreach (get_defined_constants(true)['curl'] ?? [] as $name => $value) {
            if (str_starts_with($name, 'CURLOPT_') && is_int($value)) {
                $options[$name] = $value;
            }
        }
        ksort($options);

        return $options;
    }

    /** @return list<string> */
    private static function pinnedOptionNames(): array
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/curlopt-constants.txt');
        self::assertIsString($contents);

        return preg_split('/\R/', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
