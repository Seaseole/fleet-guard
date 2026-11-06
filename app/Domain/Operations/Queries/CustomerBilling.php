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
use DateTimeInterface;

/**
 * The consolidated run's read door (D5): every `ready` charge one customer has earned.
 *
 * {@see TripBilling} answers "what does this leg owe", which is the per-trip invoice's question. A
 * consolidated run asks the same thing the other way round — "what has this customer not been billed
 * for yet" — and the customer is the grouping key, so the charges have to be gathered across trips
 * before anything is numbered. Invoicing cannot read `Chargeable` itself, so the grouping happens here
 * and travels as {@see TripBill} rows, one per trip, which is also the unit the claim locks.
 *
 * The window is over `chargeables.created_at`, not a trip timestamp: a chargeable exists before an
 * invoice does and before a state moves, and it is the row being billed. A charge raised in August but
 * swept into September's run is therefore visible by its own date rather than by a state change
 * nobody recorded (the design stores no `delivered_at`).
 *
 * Only `ready` rows are returned for {@see TripBilling}'s reason: pending has no paperwork, billed
 * already has a line, and returning either would let a run double-bill a delivery.
 */
final class CustomerBilling
{
    /**
     * @return list<TripBill> grouped by trip, oldest charge first inside each trip
     */
    public function readyForCustomer(int $customerId, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        $query = Chargeable::query()
            ->where('customer_id', $customerId)
            ->where('status', ChargeableStatus::Ready->value)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        $charges = $query->get();

        if ($charges->isEmpty()) {
            return [];
        }

        $trips = Trip::query()
            ->whereIn('id', $charges->pluck('trip_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $jobs = Job::query()
            ->whereIn('id', $charges->pluck('job_id')->unique()->all())
            ->get()
            ->keyBy('id');

        /** @var array<string, list<BillableCharge>> $byTrip */
        $byTrip = [];

        foreach ($charges as $charge) {
            $trip = $trips->get((string) $charge->trip_id);

            if ($trip === null) {
                continue;
            }

            $byTrip[(string) $trip->id][] = new BillableCharge(
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

        /** @var list<TripBill> $bills */
        $bills = [];

        foreach ($byTrip as $tripId => $rows) {
            $trip = $trips->get($tripId);
            $job = $jobs->get((string) $trip->job_id);

            $bills[] = new TripBill(
                tripId: $tripId,
                tripNo: (string) $trip->trip_no,
                jobId: (string) $trip->job_id,
                truckId: (int) $trip->truck_id,
                customerId: $customerId,
                currencyCode: $rows[0]->currencyCode !== ''
                    ? $rows[0]->currencyCode
                    : (string) ($job->currency_code ?? CurrentCompany::model()->base_currency_code),
                charges: $rows,
            );
        }

        return $bills;
    }
}
