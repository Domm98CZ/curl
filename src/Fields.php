<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

final class Fields
{
    /** @param list<array{string, string}> $pairs */
    private function __construct(private readonly array $pairs)
    {
    }

    public static function create(): self
    {
        return new self([]);
    }

    /** @param array<string, mixed> $fields nested arrays use the bracket notation of http_build_query(), null values are dropped */
    public static function fromArray(array $fields): self
    {
        $pairs = [];
        foreach (explode('&', http_build_query($fields, '', '&', PHP_QUERY_RFC3986)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $pairs[] = [rawurldecode($name), rawurldecode($value)];
        }
        return new self($pairs);
    }

    public function with(string $name, string|int|float|bool $value): self
    {
        return $this->without($name)->withAdded($name, $value);
    }

    public function withAdded(string $name, string|int|float|bool $value): self
    {
        $pairs = $this->pairs;
        $pairs[] = [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value];
        return new self($pairs);
    }

    public function without(string $name): self
    {
        return new self(array_values(array_filter(
            $this->pairs,
            static fn (array $pair): bool => $pair[0] !== $name
        )));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_unique(array_column($this->pairs, 0)));
    }

    /** @param int $encoding PHP_QUERY_RFC1738 (spaces as +, form bodies) or PHP_QUERY_RFC3986 (query strings) */
    public function toString(int $encoding = PHP_QUERY_RFC1738): string
    {
        $encode = $encoding === PHP_QUERY_RFC3986 ? 'rawurlencode' : 'urlencode';
        return implode('&', array_map(
            static fn (array $pair): string => $encode($pair[0]) . '=' . $encode($pair[1]),
            $this->pairs
        ));
    }
}
