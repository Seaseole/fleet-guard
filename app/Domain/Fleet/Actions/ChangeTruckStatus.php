<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Facades\DB;

/**
 * The only path a truck's status moves through (D1).
 *
 * Selling clears `active` in the same transaction as the state move: the state says what happened,
 * `active` says whether the asset is still part of the fleet's master data, and leaving the two to
 * be set separately is how a sold truck stays in the dispatch picker.
 */
final class ChangeTruckStatus
{
    public function __invoke(Truck $truck, FleetStatus $to, ?string $reason = null): Truck
    {
        return DB::transaction(function () use ($truck, $to, $reason): Truck {
            $truck->moveTo($to, $reason);

            if ($to === FleetStatus::Sold && $truck->active) {
                $truck->forceFill(['active' => false])->save();
            }

            return $truck->refresh();
        });
    }
}
