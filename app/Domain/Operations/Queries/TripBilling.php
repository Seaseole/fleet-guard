<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Data\BillableCharge;
use App\Domain\Operations\Data\TripBill;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;

/**
 * The Operations module's billing read door (D5).
 *
 * Invoicing may not import `Chargeable`, `Trip` or `Job`, and it should not: what it needs is the money a
 * delivery earned, the leg it earned it on and who owes it. This class answers exactly that and nothing the
 * caller could write back.
 *
 * Only `ready` chargeables are returned. A `pending` one has no paperwork behind it yet, and a `billed` one
 * already has a line — returning either would let a second invoice double-bill a delivery, which
 * `invoice_lines.chargeable_unique` would then refuse at the database with a worse message.
 *
 * Ordering is oldest first, so an invoice's line positions follow the order the work was delivered rather
 * than whatever the storage engine returns — the same reason the allocation is FIFO.
 */
final class TripBilling
{
    public function forTrip(string $tripId): ?TripBill
    {
        $trip = Trip::query()->find($tripId);

        if ($trip === null) {
            return null;
        }

        $charges = Chargeable::query()
            ->where('trip_id', $trip->id)
            ->where('status', ChargeableStatus::Ready->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $job = Job::query()->find($trip->job_id);

        /** @var list<BillableCharge> $rows */
        $rows = [];

        foreach ($charges as $charge) {
            $rows[] = new BillableCharge(
                id: (string) $charge->id,
                type: $charge->type,
                description: (string) ($charge->description ?? $charge->type->label()),
                currencyCode: (string) $charge->currency_code,
                amountMinor: (int) $charge->amount,
                baseAmountMinor: (int) $charge->base_amount,
                tripId: (string) $charge->trip_id,
                jobId: (string) $charge->job_id,
                customerId: (int) $charge->customer_id,
                truckId: (int) $trip->truck_id,
            );
        }

        $currency = $rows !== []
            ? $rows[0]->currencyCode
            : (string) ($job->currency_code ?? CurrentCompany::model()->base_currency_code);

        return new TripBill(
            tripId: (string) $trip->id,
            tripNo: (string) $trip->trip_no,
            jobId: (string) $trip->job_id,
            truckId: (int) $trip->truck_id,
            // The job is the commercial agreement, so the customer is its customer even when the
            // chargeables have not been raised yet.
            customerId: (int) ($job->customer_id ?? 0),
            currencyCode: $currency,
            charges: $rows,
        );
    }
}
