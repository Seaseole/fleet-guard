<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `billed → ready`: the delivery is billable again because the document that claimed it was voided.
 *
 * A voided invoice is not a deletion — its number stays spent and its posting is reversed — but the charges
 * it claimed must not stay claimed, or the trip could never be billed again and the revenue would be lost
 * with the paper. This is the exact inverse of {@see MarkTripChargesBilled}, with the same row lock, so a
 * void and a second issuance cannot both believe they own the delivery.
 *
 * It refuses a charge that is not currently `billed`: `pending` never had paperwork, `ready` was never
 * claimed, and `written_off` is a decision a person made — none of them is a charge this Action may reopen.
 */
final class ReleaseTripCharges
{
    /**
     * @param  list<string>  $chargeableIds
     * @return int how many rows were released
     */
    public function __invoke(string $tripId, array $chargeableIds): int
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException(
                'Charges are released inside the voiding transaction: outside one, a lock releases before the '
                .'void commits and the delivery is momentarily billable by two documents.',
            );
        }

        $ids = array_values(array_unique($chargeableIds));

        if ($ids === []) {
            return 0;
        }

        $rows = Chargeable::query()
            ->where('trip_id', $tripId)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($row->status !== ChargeableStatus::Billed) {
                throw new InvalidArgumentException(sprintf(
                    'The charge for %s is %s, so it is not claimed by an invoice and cannot be released. A void '
                    .'only reopens what it closed.',
                    $row->description ?? $row->type->label(),
                    $row->status->label(),
                ));
            }
        }

        foreach ($rows as $row) {
            $row->forceFill(['status' => ChargeableStatus::Ready->value])->save();
        }

        return $rows->count();
    }
}
