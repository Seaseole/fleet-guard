<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * §10.9's consumption-variance machine: a trip burns more diesel than its load and distance explain.
 *
 * `open` is the state a row is created in when the threshold is crossed at completion. A person then
 * says what they think of it — `acknowledged` (the reason is known and acceptable) or `disputed` (the
 * figures themselves are doubted) — and every row ends `resolved`, whether that took a reversal, a
 * corrected odometer or just an explanation. `resolved` is the leaf: a second question about the same
 * trip is a new row in its own right if the figures move again.
 *
 * The enum is the vocabulary and the tone map; the transitions live on {@see Models\FuelVariance} so
 * they cannot drift from the migration's CHECK.
 */
enum FuelVarianceState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Disputed = 'disputed';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Disputed => 'Disputed',
            self::Resolved => 'Resolved',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Disputed => 'neutral',
            self::Resolved => 'success',
        };
    }
}
