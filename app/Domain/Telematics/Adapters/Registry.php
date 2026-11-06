<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Adapters;

use App\Domain\Telematics\Contracts\GpsProvider;
use InvalidArgumentException;

/**
 * The adapter map in `fleet.telematics.adapters`, resolved (R6).
 *
 * Config rather than a `match` because Phase 7 adds providers without editing this file, and a class
 * string in config is still resolved through the container, so an adapter can take dependencies.
 * An account naming an adapter nobody registered is refused by name — a provider feed that silently
 * ingests nothing is worse than one that says it is misconfigured.
 */
final class Registry
{
    public function for(string $adapter): GpsProvider
    {
        $class = config('fleet.telematics.adapters.'.$adapter);

        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException(sprintf(
                'No GPS adapter is registered for "%s". Add it to fleet.telematics.adapters (R6).',
                $adapter,
            ));
        }

        $provider = app($class);

        if (! $provider instanceof GpsProvider) {
            throw new InvalidArgumentException(sprintf('%s is not a GPS adapter — it must implement %s.', $class, GpsProvider::class));
        }

        return $provider;
    }
}
