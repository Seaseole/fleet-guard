<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Models\Part;
use Illuminate\Support\Facades\DB;

/**
 * Adds a line to the parts catalogue (D1).
 *
 * The currency must be one the books know and the specific-truck id must resolve inside this
 * company's scope — the FK alone would accept another tenant's truck id, and a 404 is the honest
 * answer to a posted id that is not yours.
 */
final class CreatePart
{
    public function __invoke(PartDto $dto): Part
    {
        return DB::transaction(function () use ($dto): Part {
            Currency::of($dto->currencyCode);

            $truckId = $dto->truckId === null ? null : Truck::query()->findOrFail($dto->truckId)->id;

            $part = new Part([
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
