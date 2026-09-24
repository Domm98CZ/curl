<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\Fields;
use PHPUnit\Framework\TestCase;

final class FieldsTest extends TestCase
{
    public function testEncodesFormStyleByDefault(): void
    {
        self::assertSame('a=x+y', Fields::create()->with('a', 'x y')->toString());
    }

    public function testEncodesQueryStyleOnRequest(): void
    {
        self::assertSame('a=x%20y', Fields::create()->with('a', 'x y')->toString(PHP_QUERY_RFC3986));
    }

    public function testWithReplacesEveryPairOfTheSameName(): void
    {
        $fields = Fields::create()->withAdded('t', 'a')->withAdded('t', 'b')->with('t', 'c');

        self::assertSame('t=c', $fields->toString());
    }

    public function testWithAddedKeepsRepeatedNamesInOrder(): void
    {
        $fields = Fields::create()->withAdded('t', 'a')->with('u', '1')->withAdded('t', 'b');

        self::assertSame('t=a&u=1&t=b', $fields->toString());
        self::assertSame(['t', 'u'], $fields->names());
    }

    public function testIsImmutable(): void
    {
        $base = Fields::create()->with('a', '1');
        $base->with('b', '2');
        $base->without('a');

        self::assertSame('a=1', $base->toString());
    }

    public function testScalarsAreStringified(): void
    {
        $fields = Fields::create()->with('i', 5)->with('f', 1.5)->with('t', true)->with('n', false);

        self::assertSame('i=5&f=1.5&t=1&n=0', $fields->toString());
    }

    public function testFromArrayKeepsBracketNotationAndDropsNulls(): void
    {
        $fields = Fields::fromArray(['a' => ['b' => '1', 'c' => 'x y'], 'n' => null, 'k' => '2']);

        self::assertSame('a%5Bb%5D=1&a%5Bc%5D=x+y&k=2', $fields->toString());
        self::assertSame(['a[b]', 'a[c]', 'k'], $fields->names());
    }

    public function testFromArrayRoundTripsReservedCharacters(): void
    {
        $fields = Fields::fromArray(['q' => 'a&b=c', 'k=1' => 'v']);

        self::assertSame('q=a%26b%3Dc&k%3D1=v', $fields->toString());
    }
}
