<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\EquipmentDto;
use App\Domain\Fleet\Models\Equipment;
use Illuminate\Support\Facades\DB;

/**
 * Edits equipment (D1). Status moves through {@see ChangeEquipmentStatus}.
 */
final class UpdateEquipment
{
    public function __invoke(Equipment $equipment, EquipmentDto $dto): Equipment
    {
        return DB::transaction(function () use ($equipment, $dto): Equipment {
            $equipment->fill([
                'code' => $dto->code,
                'kind' => $dto->kind,
                'purchase_amount_base' => $dto->purchaseAmountBase,
            ]);

            $equipment->save();

            return $equipment;
        });
    }
}
