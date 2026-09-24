<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

/**
 * Whether the same request can be put on the wire a second time and carry the same payload.
 *
 * Unknown is not a third outcome but an abstention: the participant asked has no view of what the
 * mapping will send, so the caller must fall back to whatever it would have done on its own.
 */
enum BodyReplay
{
    case Replayable;
    case NotReplayable;
    case Unknown;
}
