<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Streaming;

use Closure;
use Domm98CZ\Curl\Contract\RetryAwareInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Transport\HttpResponseParser;
use Psr\Http\Message\ResponseInterface;

final class HttpStreamHandler implements StreamHandlerInterface, RetryAwareInterface
{
    private string $block = '';

    /**
     * @param Closure(string): void $onChunk
     * @param (Closure(ResponseInterface): void)|null $onResponse
     * @param (Closure(): bool)|null $onRetry
     */
    public function __construct(
        private readonly Closure $onChunk,
        private readonly ?Closure $onResponse = null,
        private readonly HttpResponseParser $parser = new HttpResponseParser(),
        private readonly ?Closure $onRetry = null,
    ) {
    }

    public function onHeaderLine(string $line): void
    {
        if (trim($line) !== '') {
            $this->block .= $line;
            return;
        }

        if ($this->block !== '' && $this->onResponse !== null) {
            ($this->onResponse)($this->parser->parseHeaderBlock($this->block));
        }
        $this->block = '';
    }

    public function onChunk(string $chunk): void
    {
        ($this->onChunk)($chunk);
    }

    public function resetForRetry(): bool
    {
        $this->block = '';
        return $this->onRetry !== null && ($this->onRetry)();
    }
}
