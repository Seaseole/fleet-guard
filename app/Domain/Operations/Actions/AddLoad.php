<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Data\LoadDto;
use App\Domain\Operations\Models\Load;
use App\Domain\Operations\Models\Trip;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts one load on a trip (§4.1 TRIP ||--o{ LOAD, §15.2's "loading schedule").
 *
 * Adding a load never refuses an overload, and that is deliberate. The schedule is written across a
 * phone call and a warehouse: the planner who types the first of six parcels cannot know the fifth is
 * the one that breaks the trailer's 28 t. §10.2 puts the tonnage test on `assigned → loaded`, where
 * the whole manifest is present — {@see LoadingGate} — so refusing here would only teach people to
 * enter loads in descending order of size.
 *
 * What *is* refused is a load on a trip that has already left. After departure the manifest is a
 * record of what crossed the weighbridge, and §10.1's answer to a wrong record is a correcting entry,
 * not an edit.
 */
final class AddLoad
{
    public function __invoke(Trip $trip, LoadDto $dto, ?int $actorId = null): Load
    {
        if (! $trip->status->isPreDeparture()) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s has already departed (%s). The load it carried is a fact now; a missing parcel belongs on the next trip or a credit note, not on this row (§10.1).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        return DB::transaction(static fn (): Load => Load::create([
            'trip_id' => $trip->id,
            'commodity' => $dto->commodity,
            'tonnes_10_2' => $dto->tonnes,
            'cubic_meters_10_2' => $dto->cubicMeters,
            'consignee' => $dto->consignee,
            'origin' => $dto->origin,
            'destination' => $dto->destination,
            'stop_sequence' => $dto->stopSequence,
            'waybill_document_id' => $dto->waybillDocumentId,
        ]));
    }
}
