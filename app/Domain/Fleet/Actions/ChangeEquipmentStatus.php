<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Equipment;
use Illuminate\Support\Facades\DB;

/**
 * The only path equipment's status moves through (§10.1, D1).
 */
final class ChangeEquipmentStatus
{
    public function __invoke(Equipment $equipment, FleetStatus $to, ?string $reason = null): Equipment
    {
        return DB::transaction(function () use ($equipment, $to, $reason): Equipment {
            $equipment->moveTo($to, $reason);

            return $equipment->refresh();
        });
    }
}
