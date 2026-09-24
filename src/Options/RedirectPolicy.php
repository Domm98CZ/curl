<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Options;

use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\RedirectGuards;

final class RedirectPolicy implements CurlOptionInterface
{
    public function __construct(
        private readonly bool $follow = true,
        private readonly int $maxRedirects = 20,
        private readonly bool $followWithCredentials = false,
        private readonly bool $followWithPinnedRequestTarget = false,
    ) {
    }

    // CURLOPT_FOLLOWLOCATION is deliberately not written here: only the mapper holds the redirect
    // guards, and an absent key leaves libcurl at its no-follow default, so applying the policy
    // outside the mapper fails closed.
    public function toCurlOptions(): array
    {
        return [CURLOPT_MAXREDIRS => $this->maxRedirects];
    }

    public function allowsFollowing(RedirectGuards $guards): bool
    {
        return $this->follow
            && !$guards->streamedBody
            && (!$guards->credentials || $this->followWithCredentials)
            && (!$guards->pinnedRequestTarget || $this->followWithPinnedRequestTarget);
    }
}
