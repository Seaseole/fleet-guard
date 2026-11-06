<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Models\Part;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a catalogue line (D1) — a mistyped SKU, a cost that moved, a part that turns out
 * to fit every truck instead of one. Work-order lines will point at the row id, so correcting the
 * catalogue does not detach a repair's history.
 */
final class UpdatePart
{
    public function __invoke(Part $part, PartDto $dto): Part
    {
        return DB::transaction(function () use ($part, $dto): Part {
            Currency::of($dto->currencyCode);

            $truckId = $dto->truckId === null ? null : Truck::query()->findOrFail($dto->truckId)->id;

            $part->fill([
                'sku' => $dto->sku,
                'name' => $dto->name,
                'category' => $dto->category,
                'unit_cost_minor' => $dto->unitCostMinor,
                'currency_code' => $dto->currencyCode,
                'reorder_level' => $dto->reorderLevel,
                'truck_id' => $truckId,
                'active' => $dto->active,
            ]);

            $part->save();

            return $part;
        });
    }
}
