<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * The fuel-issue machine: `recorded → posted → reversed`.
 *
 * The enum is the vocabulary and the tone map; the transitions live on {@see Models\FuelIssue} so
 * they cannot drift from the migration's CHECK. `recorded` is the state a row has between its own
 * creation and its trip cost's posting, inside one transaction — a screen never sees it, but a
 * crashed transaction that somehow left the row behind would say honestly that it never posted.
 *
 * `reversed` is a leaf and is the only correction: never an edit, always a reversal plus a new
 * issue. The reversal Action arrives with the variance slice; the vocabulary and the CHECK are here
 * now because a state that is added later is a migration of live data.
 */
enum FuelIssueStatus: string
{
    case Recorded = 'recorded';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Recorded => 'neutral',
            self::Posted => 'success',
            self::Reversed => 'danger',
        };
    }
}
