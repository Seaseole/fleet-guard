<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The billing claim: `ready → billed`, taken under a row lock.
 *
 * This is Operations' half of phase 4's first door. Invoicing may not write `chargeables` (D5), so
 * issuing an invoice asks this Action to claim the rows it is about to bill — which is also the only place
 * the claim can be **raced safely**. Two issuers on the same trip both reach here; the first takes the
 * `FOR UPDATE` lock and marks the rows, the second blocks, wakes to `billed` and is refused. Its invoice is
 * rolled back with it, and because the number was allocated in the same transaction the sequence is
 * un-burned — the gap-free promise survives a lost race rather than being repaired afterwards.
 *
 * `billed` is set here and nowhere else: `BuildChargeablesFromPod` stops at `ready` deliberately, and a
 * chargeable that claimed itself billed before an invoice existed would be a delivery nobody could chase.
 */
final class MarkTripChargesBilled
{
    /**
     * @param  list<string>  $chargeableIds  the charges the caller is about to put on an invoice
     * @return int how many rows this claim took
     */
    public function __invoke(string $tripId, array $chargeableIds): int
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException(
                'Charges are claimed inside the invoice transaction: outside one, a lock releases before the '
                .'invoice commits and two issuers can both believe they own the same delivery.',
            );
        }

        $ids = array_values(array_unique($chargeableIds));

        if ($ids === []) {
            throw new InvalidArgumentException('An invoice cannot claim zero charges — there is nothing to bill.');
        }

        $rows = Chargeable::query()
            ->where('trip_id', $tripId)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();

        if ($rows->count() !== count($ids)) {
            throw new InvalidArgumentException(sprintf(
                'Those charges are not all on trip %s in this company, so the invoice would bill something it '
                .'cannot name. Re-read the trip before issuing.',
                $tripId,
            ));
        }

        foreach ($rows as $row) {
            if ($row->status !== ChargeableStatus::Ready) {
                throw new InvalidArgumentException(sprintf(
                    'The charge for %s is already %s, so it has been billed or withdrawn. A second invoice would '
                    .'bill the delivery twice.',
                    $row->description ?? $row->type->label(),
                    $row->status->label(),
                ));
            }
        }

        foreach ($rows as $row) {
            $row->forceFill(['status' => ChargeableStatus::Billed->value])->save();
        }

        return $rows->count();
    }
}
