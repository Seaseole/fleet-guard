<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * The things that can change a bulk tank's book: a delivery in, diesel out to
 * a trip, a dip that disagrees with either, a reversal that puts an issue's litres and money
 * back after the issue was found to be wrong, since, the cutover intake's `opening`
 * fill, which belongs to none of the other four.
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
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Delivered in',
            self::Issue => 'Issued to a trip',
            self::Dip => 'Dip count',
            self::Reversal => 'Issue reversed',
            self::Opening => 'Opening stock',
        };
    }
}
