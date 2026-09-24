<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Contract;

/**
 * Marks a failure that replaying the request cannot fix, without claiming the request itself was
 * at fault. PSR-18's RequestExceptionInterface is the only such signal the standard offers, and it
 * asserts something different — that the request is unusable — so a failure of the client's own
 * local resources cannot borrow it truthfully.
 */
interface NonRetryableExceptionInterface
{
}
