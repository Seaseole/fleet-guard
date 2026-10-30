<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * The four things that can change a bulk tank's book (§15.2 phase 3): a delivery in, diesel out to
 * a trip, a dip that disagrees with either, and a reversal that puts an issue's litres and money
 * back after the issue was found to be wrong.
 *
 * A dip is kept even when it agrees — "the book is right for once" is a fact with a date, and the
 * next disagreement is easier to trust when the last confirmation is on the record.
 */
enum BulkTankMovementKind: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Dip = 'dip';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Delivered in',
            self::Issue => 'Issued to a trip',
            self::Dip => 'Dip count',
            self::Reversal => 'Issue reversed',
        };
    }
}
