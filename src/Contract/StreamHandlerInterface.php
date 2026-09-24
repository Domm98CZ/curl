<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

interface StreamHandlerInterface
{
    /** @param string $line one raw header line as libcurl delivers it, trailing CRLF included */
    public function onHeaderLine(string $line): void;

    /** @param string $chunk body bytes as they arrive; the transport buffers nothing while a handler is attached */
    public function onChunk(string $chunk): void;
}
