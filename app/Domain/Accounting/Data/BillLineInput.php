<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\BillCategory;

/**
 * One proposed line of a supplier bill, in the shape the door accepts.
 *
 * `netMinor` is deliberately **net of VAT**: the supplier's VAT is computed per line by the Action
 * from the rate effective on the bill date (Q4), never typed alongside the figure it was
 * calculated from. A line that carried its own tax total could disagree with the rate register and
 * nothing downstream would notice.
 *
 * `truckId`/`tripId` answer the account's dimension mask: fuel lines need both, parts and
 * labour need the truck. The Action refuses a line whose account demands a leg it does not name.
 */
final class BillLineInput
{
    public function __construct(
        public readonly string $description,
        public readonly BillCategory $category,
        public readonly int $netMinor,
        public readonly ?int $truckId = null,
        public readonly ?string $tripId = null,
        public readonly ?string $workOrderId = null,
        public readonly ?string $currencyCode = null,
    ) {}
}
