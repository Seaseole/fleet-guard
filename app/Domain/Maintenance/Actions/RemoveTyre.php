<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\Tyre;
use App\Domain\Maintenance\Models\TyrePosition;
use App\Domain\Maintenance\TyreStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Closes a fitment and says what became of the casing (TYRE_POSITION).
 *
 * The cost per kilometre is written here and nowhere else, because until the closing odometer is read
 * there is no distance to divide by. It is stored as a four-place rate (`×10000` of a base unit per
 * kilometre): a P1,200 tyre over 60,000 km is 0.0200, and four places keep a thebe per kilometre
 * readable without rounding it to nothing. A tyre with no recorded price, or one fitted and removed at
 * the same reading, gets no rate rather than a confident zero.
 *
 * The new status is the repairer's verdict: back on the rack, off to a retreader, or finished. It can
 * never be `in_service` — a removal that leaves the tyre fitted is not a removal, and a separate fit
 * opens the next stint.
 */
final class RemoveTyre
{
    public function __invoke(
        Tyre $tyre,
        CarbonInterface $removedAt,
        int $odometerKm,
        TyreStatus $nextStatus,
    ): TyrePosition {
        if (! Gate::allows('tyres.manage')) {
            throw new InvalidArgumentException('Removing a tyre is a workshop act.');
        }

        if ($nextStatus === TyreStatus::InService) {
            throw new InvalidArgumentException('A tyre taken off cannot stay in service; fit it to its next position instead.');
        }

        return DB::transaction(function () use ($tyre, $removedAt, $odometerKm, $nextStatus): TyrePosition {
            /** @var Tyre $lockedTyre */
            $lockedTyre = Tyre::query()->whereKey($tyre->id)->lockForUpdate()->firstOrFail();

            /** @var TyrePosition|null $position */
            $position = TyrePosition::query()
                ->where('tyre_id', $lockedTyre->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($position === null) {
                throw new InvalidArgumentException(sprintf('%s is not fitted to anything, so there is nothing to remove.', $lockedTyre->label()));
            }

            // The date columns hold the company's wall time rather than a UTC instant, so a date-time
            // typed in the company zone formats to the same wall clock the fitment was written with.
            // Comparing instants would read the stored row hours ahead and refuse a removal made now.
            if ($removedAt->format('Y-m-d H:i:s') < $position->fitted_at->format('Y-m-d H:i:s')) {
                throw new InvalidArgumentException(sprintf('The removal cannot be before the fitment (%s).', $position->fitted_at->format('d M Y H:i')));
            }

            if ($odometerKm < $position->fitted_odometer_km) {
                throw new InvalidArgumentException(sprintf(
                    'This tyre was fitted at %s km; a removal at %s km would run it backwards.',
                    number_format($position->fitted_odometer_km),
                    number_format($odometerKm),
                ));
            }

            $kilometres = $odometerKm - $position->fitted_odometer_km;

            $position->forceFill([
                'removed_at' => $removedAt,
                'removed_odometer_km' => $odometerKm,
                'cost_per_km_x10000' => $kilometres > 0 && $lockedTyre->purchase_cost_base > 0
                    ? intdiv($lockedTyre->purchase_cost_base * 100 + intdiv($kilometres, 2), $kilometres)
                    : null,
            ])->save();

            $lockedTyre->forceFill(['status' => $nextStatus])->save();

            return $position;
        }, 3);
    }
}
