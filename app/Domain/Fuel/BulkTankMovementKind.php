<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * The three things that can change a bulk tank's book (§15.2 phase 3): a delivery in, diesel out to
 * a trip, and a dip that disagrees with either.
 *
 * A dip is kept even when it agrees — "the book is right for once" is a fact with a date, and the
 * next disagreement is easier to trust when the last confirmation is on the record.
 */
enum BulkTankMovementKind: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Dip = 'dip';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Delivered in',
            self::Issue => 'Issued to a trip',
            self::Dip => 'Dip count',
        };
    }
}
