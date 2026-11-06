<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\BillLinkedSource;

/**
 * One obligation another module already posted, waiting for its supplier paper.
 *
 * The projection exists so Accounting can offer a delivery or a receipt to a clerk **without**
 * importing `FuelPurchase` or `StockMovement` (D5): the kind says which door will stamp the
 * row, the amount is what the movement put on `2110`, and a linked bill must equal it exactly —
 * the paper that disagrees with the book is refused at the door, not reconciled by a spreadsheet.
 */
final class UnbilledObligation
{
    public function __construct(
        public readonly BillLinkedSource $kind,
        public readonly string $id,
        public readonly string $label,
        public readonly int $amountBase,
        public readonly string $occurredOn,
    ) {}
}
