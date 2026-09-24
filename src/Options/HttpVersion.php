<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use InvalidArgumentException;

final class HttpVersion implements CurlOptionInterface
{
    private const MAP = [
        '1.1' => CURL_HTTP_VERSION_1_1,
        '2' => CURL_HTTP_VERSION_2_0,
        '3' => 30, // CURL_HTTP_VERSION_3, not defined on every libcurl build this package targets
    ];

    private readonly int $curlConstant;

    public function __construct(string $version)
    {
        if (!array_key_exists($version, self::MAP)) {
            throw new InvalidArgumentException(sprintf('Unsupported HTTP version "%s". Use "1.1", "2", or "3".', $version));
        }
        $this->curlConstant = self::MAP[$version];
    }

    public function toCurlOptions(): array
    {
        return [CURLOPT_HTTP_VERSION => $this->curlConstant];
    }
}
