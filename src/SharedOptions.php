<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\OptionsInterface;
use InvalidArgumentException;

/** @internal */
final class SharedOptions
{
    // both collaborators are per-transfer state: chunks of concurrent bodies would interleave in one
    // handler and a collector accepts exactly one transfer, so sharing them cannot mean anything
    public static function assertCarriesNoPerTransferState(OptionsInterface $options, string $subject, string $hint): void
    {
        if ($options->getStreamHandler() !== null || $options->getTransferInfoCollector() !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot carry a stream handler or a transfer info collector; %s',
                $subject,
                $hint
            ));
        }
    }

    // shared options go first so a per-request option overrides them by the ordinary later-write-wins rule
    public static function mergeInto(?OptionsInterface $shared, ?OptionsInterface $perRequest): OptionsInterface
    {
        $perRequest ??= new RequestOptions();
        if ($shared === null) {
            return $perRequest;
        }

        return new RequestOptions(
            [...$shared->all(), ...$perRequest->all()],
            $perRequest->getTransferInfoCollector(),
            $perRequest->getStreamHandler(),
        );
    }
}
