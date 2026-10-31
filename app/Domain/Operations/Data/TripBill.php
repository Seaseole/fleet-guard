<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

/**
 * Everything one trip owes, in the shape §10.4's invoice needs it (§13 D5).
 *
 * The trip is the unit because §15 phase 4's first door is "invoice per trip": the customer, the job and
 * the truck all come from the trip, and the charges are the POD's earnings that no invoice has claimed.
 * A consolidated billing run is the same list grouped by customer, which is why the DTO names the one
 * customer rather than accepting a mixture.
 *
 * `charges` is empty for a trip that is not yet worth billing, and that is a state the caller must say
 * something about rather than treat as zero — an invoice for nothing is not a zero-value invoice.
 */
final readonly class TripBill
{
    /**
     * @param  list<BillableCharge>  $charges
     */
    public function __construct(
        public string $tripId,
        public string $tripNo,
        public string $jobId,
        public int $truckId,
        public int $customerId,
        public string $currencyCode,
        public array $charges,
    ) {}

    /** The net total in the chargeables' own currency, in integer minor units. */
    public function netMinor(): int
    {
        return array_sum(array_map(static fn (BillableCharge $charge): int => $charge->amountMinor, $this->charges));
    }

    /** The net total in the company's base currency. */
    public function netBaseMinor(): int
    {
        return array_sum(array_map(static fn (BillableCharge $charge): int => $charge->baseAmountMinor, $this->charges));
    }

    /** @return list<string> */
    public function chargeableIds(): array
    {
        return array_map(static fn (BillableCharge $charge): string => $charge->id, $this->charges);
    }
}
