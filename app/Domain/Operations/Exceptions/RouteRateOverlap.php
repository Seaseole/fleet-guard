<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exceptions;

use RuntimeException;

/**
 * A rate card the pricing rules refuse: a window that would overlap another card for the same
 * route, customer and basis.
 *
 * The message names both windows, because "these dates clash" leaves the person holding the form to
 * work out which of the customer's cards they are colliding with — and on a corridor with four
 * customers and three bases that is not obvious.
 */
final class RouteRateOverlap extends RuntimeException
{
    public static function window(string $basis, string $from, ?string $to, string $existingFrom, ?string $existingTo): self
    {
        $ends = $to === null ? 'open ended' : "ends {$to}";
        $existingEnds = $existingTo === null ? 'open ended' : "ends {$existingTo}";

        return new self(
            "A {$basis} card for this route and customer already runs from {$existingFrom} ({$existingEnds}). "
            ."The window starting {$from} ({$ends}) would overlap it. Close the earlier card by setting its end date first — "
            .'two live prices for one route make every quote a guess.'
        );
    }
}
