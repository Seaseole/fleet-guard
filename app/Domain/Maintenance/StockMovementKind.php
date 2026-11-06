<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The three ways a part's book changes: a purchase arriving on the shelf, a part
 * leaving it for a job, and a physical count that says what is really there.
 *
 * A count is kept even when it agrees — "the book is right for once" is a fact with a date, and the
 * next shortage is easier to read against the last confirmation. There is no reversal kind: a
 * mistaken issue is corrected by counting the shelf, which is what the store would have to do anyway.
 */
enum StockMovementKind: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Received',
            self::Issue => 'Issued to a job',
            self::Count => 'Counted',
        };
    }
}
