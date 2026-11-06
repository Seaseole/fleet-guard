<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Data\TyreDto;
use App\Domain\Maintenance\Models\Tyre;
use App\Domain\Maintenance\TyreStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Puts a casing on the spares rack (phase 3, slice 3f).
 *
 * A tyre is registered before it is fitted, because the serial is what everything afterwards is
 * written against: a fitment names a tyre that already exists rather than describing a "315/80 front
 * left" that cannot be followed across trucks. The price is recorded in the base currency for the
 * reason — a casing bought in ZAR is valued by the bill that carries the rate, not by an invented one
 * here.
 */
final class CreateTyre
{
    public function __invoke(TyreDto $dto): Tyre
    {
        if (! Gate::allows('tyres.manage')) {
            throw new InvalidArgumentException('Adding a tyre to the register is a workshop act.');
        }

        $base = CurrentCompany::model()->base_currency_code;
        $cost = $dto->purchaseCost;

        if ($cost !== null && $cost->currencyCode !== $base) {
            throw new InvalidArgumentException(sprintf(
                'A tyre is valued in %s. One bought in %s is converted by the supplier bill that carries its rate.',
                $base,
                $cost->currencyCode,
            ));
        }

        return DB::transaction(function () use ($dto, $cost): Tyre {
            $serial = $dto->serialNumber();

            if (Tyre::query()->where('serial', $serial)->exists()) {
                throw new InvalidArgumentException(sprintf('Tyre %s is already in the register; fit the casing you have rather than registering it twice.', $serial));
            }

            /** @var Tyre $tyre */
            $tyre = Tyre::create([
                'serial' => $serial,
                'brand' => $dto->brandName(),
                'size' => $dto->sizeLabel(),
                'purchase_cost_base' => $cost?->minorUnits ?? 0,
                'tread_depth_new_x100' => $dto->treadDepthX100,
                'status' => TyreStatus::OnSpares,
            ]);

            return $tyre;
        }, 3);
    }
}
