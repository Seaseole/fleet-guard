<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\EquipmentDto;
use App\Domain\Fleet\Models\Equipment;
use Illuminate\Support\Facades\DB;

/**
 * Adds a piece of yard equipment (§4.3, D1).
 */
final class CreateEquipment
{
    public function __invoke(EquipmentDto $dto): Equipment
    {
        return DB::transaction(function () use ($dto): Equipment {
            $equipment = new Equipment([
                'code' => $dto->code,
                'kind' => $dto->kind,
                'purchase_amount_base' => $dto->purchaseAmountBase,
            ]);

            $equipment->status = $dto->initialStatus;

            $equipment->save();

            return $equipment;
        });
    }
}
