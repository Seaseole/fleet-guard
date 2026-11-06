<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Queries;

use App\Domain\Fleet\Data\FleetUnitOption;
use App\Domain\Fleet\Models\Equipment;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Support\Collection;

/**
 * The Fleet module's read door for other modules (D5).
 *
 * Operations names a truck and a trailer on every trip and has to ask both about status, capacity
 * and mileage; it may not import the models. This class is the whole contract, in the tenant scope
 * the caller is already in — the same shape {@see DriverDirectory} set in
 * Phase 1, so a second module needing the fleet does not invent its own bypass.
 *
 * Equipment is included because a trip may carry a generator, and `unit()` answers by kind rather
 * than guessing which of the three tables an id belongs to: three tables with independent sequences
 * make a bare id ambiguous, and an ambiguous id in a dispatch gate is how the wrong truck gets
 * blamed for a load.
 */
final class FleetDirectory
{
    public function truck(int $truckId): ?FleetUnitOption
    {
        $truck = Truck::query()->find($truckId);

        return $truck === null ? null : $this->truckOption($truck);
    }

    public function trailer(int $trailerId): ?FleetUnitOption
    {
        $trailer = Trailer::query()->find($trailerId);

        return $trailer === null ? null : $this->trailerOption($trailer);
    }

    public function unit(string $kind, int $unitId): ?FleetUnitOption
    {
        return match ($kind) {
            'truck' => $this->truck($unitId),
            'trailer' => $this->trailer($unitId),
            default => $this->equipment($unitId),
        };
    }

    /**
     * Several trucks in one read, keyed by id.
     *
     * A board that lists a week of trips knows every truck it is about to ask for; the per-id door
     * would spend one query per row to answer the same question. Ids that do not exist are absent,
     * which is exactly the answer `truck()` gives by returning null.
     *
     * @param  iterable<int>  $truckIds
     * @return array<int, FleetUnitOption>
     */
    public function trucksById(iterable $truckIds): array
    {
        $ids = self::ids($truckIds);

        if ($ids === []) {
            return [];
        }

        $options = [];

        foreach (Truck::query()->whereKey($ids)->get() as $truck) {
            $option = $this->truckOption($truck);
            $options[$option->id] = $option;
        }

        return $options;
    }

    /**
     * @param  iterable<int>  $trailerIds
     * @return array<int, FleetUnitOption>
     */
    public function trailersById(iterable $trailerIds): array
    {
        $ids = self::ids($trailerIds);

        if ($ids === []) {
            return [];
        }

        $options = [];

        foreach (Trailer::query()->whereKey($ids)->get() as $trailer) {
            $option = $this->trailerOption($trailer);
            $options[$option->id] = $option;
        }

        return $options;
    }

    public function equipment(int $equipmentId): ?FleetUnitOption
    {
        $item = Equipment::query()->find($equipmentId);

        if ($item === null) {
            return null;
        }

        /** @var string $label */
        $label = (string) ($item->asset_no ?? $item->name ?? 'Equipment #'.$item->id);

        return new FleetUnitOption(
            id: $item->id,
            kind: 'equipment',
            label: $label,
            status: $item->status,
            capacityTonnes: null,
            odometerKm: null,
            engineHours: null,
            active: ! $item->status->isTerminal(),
        );
    }

    /**
     * Every truck still on the books, dispatchable ones first.
     *
     * Named for what it returns rather than what a caller usually wants: the dispatch board shows a
     * truck that is in the workshop — that is the whole point of a board — while a picker wants only
     * the free ones, which is {@see self::$availableOnly} rather than a second method.
     *
     * @return Collection<int, FleetUnitOption>
     */
    public function trucks(bool $availableOnly = false): Collection
    {
        $trucks = Truck::query()->where('active', true)->get();

        if ($availableOnly) {
            $trucks = $trucks->filter(static fn (Truck $truck): bool => $truck->status->mayBeDispatched());
        }

        return $this->ordered($trucks);
    }

    /** @return Collection<int, FleetUnitOption> */
    public function availableTrailers(): Collection
    {
        return $this->ordered(Trailer::query()->where('active', true)->get());
    }

    /**
     * @param  iterable<int, Truck|Trailer>  $units
     * @return Collection<int, FleetUnitOption>
     */
    private function ordered(iterable $units): Collection
    {
        /** @var Collection<int, FleetUnitOption> $options */
        $options = collect($units)->map(fn ($unit): FleetUnitOption => $unit instanceof Truck
            ? $this->truckOption($unit)
            : $this->trailerOption($unit));

        return $options
            ->sortBy(static fn (FleetUnitOption $o): string => $o->label)
            ->sortBy(static fn (FleetUnitOption $o): int => $o->mayBeDispatched() ? 0 : 1)
            ->values();
    }

    private function truckOption(Truck $truck): FleetUnitOption
    {
        return new FleetUnitOption(
            id: $truck->id,
            kind: 'truck',
            label: $truck->label(),
            status: $truck->status,
            capacityTonnes: $truck->capacity_tonnes_10_2 === null ? null : (float) $truck->capacity_tonnes_10_2,
            odometerKm: (int) $truck->odometer_km,
            engineHours: $truck->engine_hours_10_2 === null ? null : (float) $truck->engine_hours_10_2,
            active: (bool) $truck->active,
        );
    }

    private function trailerOption(Trailer $trailer): FleetUnitOption
    {
        return new FleetUnitOption(
            id: $trailer->id,
            kind: 'trailer',
            label: $trailer->label(),
            status: $trailer->status,
            capacityTonnes: $trailer->capacity_tonnes_10_2 === null ? null : (float) $trailer->capacity_tonnes_10_2,
            odometerKm: null,
            engineHours: null,
            active: (bool) $trailer->active,
        );
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    private static function ids(iterable $ids): array
    {
        return array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            is_array($ids) ? $ids : iterator_to_array($ids, false),
        )));
    }
}
