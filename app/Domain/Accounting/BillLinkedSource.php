<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * §20.36 — whose movement a linked bill documents, and the door that answers for it.
 *
 * A bill is one of two shapes, and the CHECK on `bills` keeps them apart: free lines that post their
 * own expense (§20.35's `BillRecorded`), or **no lines at all** and a link to an obligation Phase 3
 * already put in the book (`Cr 2110` at the bulk delivery §20.26, at the part receipt §20.29). The
 * linked shape posts nothing of its own — a second expense debit for diesel the tank already funded
 * is the double-count the Phase 3 logs kept warning about, and this enum is where the door refuses it.
 */
enum BillLinkedSource: string
{
    case FuelDelivery = 'fuel_delivery';
    case StockReceipt = 'stock_receipt';

    public function label(): string
    {
        return match ($this) {
            self::FuelDelivery => 'Bulk fuel delivery',
            self::StockReceipt => 'Parts receipt',
        };
    }
}
