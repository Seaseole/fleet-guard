<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Operations\ChargeableType;

/**
 * One chargeable as the Invoicing module may see it (D5's read model).
 *
 * Deliberately not the `Chargeable` model: Invoicing needs the figures, the leg and the sentence to
 * print, and nothing it could write. `type` travels as the enum because Invoicing maps it straight onto
 * `InvoiceLineKind`, whose values are inherited from `chargeables.type` verbatim.
 *
 * Both amounts are already integer minor units in the currency the chargeable was raised in; the
 * base figure is the one frozen when the job was quoted, so the invoice cannot re-convert history at
 * today's rate.
 */
final readonly class BillableCharge
{
    public function __construct(
        public string $id,
        public ChargeableType $type,
        public string $description,
        public string $currencyCode,
        public int $amountMinor,
        public int $baseAmountMinor,
        public string $tripId,
        public string $jobId,
        public int $customerId,
        public int $truckId,
    ) {}
}
