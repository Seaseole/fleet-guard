<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Operations\Exceptions\LoadingRefused;
use App\Domain\Operations\Models\Load;
use App\Domain\Operations\Models\Trip;
use Illuminate\Support\Collection;

/**
 * §10.2's `assigned → loaded` gate: the tonnage a truck can lawfully carry, and the paper that says
 * what it is carrying.
 *
 * Two limits are checked, not one. §10.2 words it as "truck **and** trailer capacity" because the
 * weaker of the pair is the law: a 34 t tractor behind a 28 t flatbed crosses a weighbridge at 28 t,
 * and a board that measured only the truck would dispatch an overload every time the trailer was the
 * limiting item.
 *
 * An unrecorded capacity **refuses** rather than passing. It is the same call §4.3 makes about a null
 * licence date — a missing fact is not a permission — and the message names the register to fix. The
 * alternative is a fleet of trucks whose capacity was never typed in silently carrying anything.
 *
 * The waybill is asked per load. A trip with six loads and paper for five has five verified tonnes and
 * one that will be argued about at the far end, which is exactly the state §10.2 refuses to let a
 * truck depart into.
 *
 * @see LoadingRefused for the words a dispatcher reads
 */
final class LoadingGate
{
    public function __construct(private readonly FleetDirectory $fleet) {}

    /**
     * @return list<string> every problem at once, so the loading bay fixes the trip in one pass
     */
    public function findings(Trip $trip): array
    {
        /** @var Collection<int, Load> $loads */
        $loads = $trip->loads()->get();

        if ($loads->isEmpty()) {
            return [LoadingRefused::noLoads($trip->trip_no)->getMessage()];
        }

        $tonnes = round($loads->sum(static fn (Load $load): float => (float) $load->tonnes_10_2), 2);

        $findings = $this->capacityFindings($trip, $loads, $tonnes);

        foreach ($loads as $load) {
            if ($load->waybill_document_id === null) {
                $findings[] = LoadingRefused::missingWaybill($load->commodity, (float) $load->tonnes_10_2)->getMessage();

                continue;
            }

            /** @var Document|null $waybill */
            $waybill = Document::query()->find($load->waybill_document_id);

            if ($waybill?->lifecycle === Lifecycle::Voided) {
                $findings[] = LoadingRefused::waybillVoided($load->commodity, $waybill->title)->getMessage();
            }
        }

        return $findings;
    }

    public function mayLoad(Trip $trip): bool
    {
        return $this->findings($trip) === [];
    }

    /**
     * The heavier-of-the-pair check, against the truck and the trailer it is hauling.
     *
     * The heaviest load is named because that is the row a dispatcher can actually split — "3 t over"
     * invites moving anything, and the biggest single parcel is the only one big enough to help.
     *
     * @param  Collection<int, Load>  $loads
     * @return list<string>
     */
    private function capacityFindings(Trip $trip, $loads, float $tonnes): array
    {
        $findings = [];

        $units = [];

        $truck = $this->fleet->truck((int) $trip->truck_id);

        if ($truck !== null) {
            $units[] = $truck;
        }

        if ($trip->trailer_id !== null) {
            $trailer = $this->fleet->trailer((int) $trip->trailer_id);

            if ($trailer !== null) {
                $units[] = $trailer;
            }
        }

        if ($units === []) {
            return ['The truck on this trip is no longer in this company\'s fleet, so its load cannot be measured.'];
        }

        $heaviest = $loads->sortByDesc(static fn (Load $load): float => (float) $load->tonnes_10_2)->first();

        foreach ($units as $unit) {
            if ($unit->capacityTonnes === null) {
                $findings[] = LoadingRefused::capacityUnknown($unit->kind, $unit->label)->getMessage();

                continue;
            }

            if ($tonnes > $unit->capacityTonnes) {
                $findings[] = LoadingRefused::overloaded(
                    $unit->kind,
                    $unit->label,
                    $tonnes,
                    $unit->capacityTonnes,
                    $heaviest === null ? 'unknown' : $heaviest->label(),
                )->getMessage();
            }
        }

        return $findings;
    }
}
