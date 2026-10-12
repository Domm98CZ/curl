<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;

final class Encoding implements CurlOptionInterface
{
    private ?string $encoding;

    /** @param string $encoding '' negotiates and auto-decodes every encoding curl supports (gzip, deflate, br, ...) */
    public function __construct(string $encoding = '')
    {
        $this->encoding = $encoding;
    }

    public static function disabled(): self
    {
        $disabled = new self();
        $disabled->encoding = null;

        return $disabled;
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_ENCODING => $this->encoding];
    }
}
