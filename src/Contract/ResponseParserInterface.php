<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

use Domm98CZ\Curl\RawResponse;
use Psr\Http\Message\ResponseInterface;

interface ResponseParserInterface
{
    public function parse(RawResponse $rawResponse): ResponseInterface;
}
