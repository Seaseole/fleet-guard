<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Actions;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Queries\TripsInMotion;
use App\Domain\Telematics\Adapters\Registry;
use App\Domain\Telematics\Data\IngestReport;
use App\Domain\Telematics\Data\PositionDto;
use App\Domain\Telematics\Models\GpsProviderAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * §14.3's ingest path: one webhook batch in, positions on file, projection moved, in one transaction.
 *
 * Three responsibilities here, each a decision rather than a convenience:
 *
 * - **The batch is one statement per chunk.** At 21 vehicles × a 30 s ping the table takes ~60 k rows
 *   a day, so row-by-row inserts would be 60 k round trips. `insertOrIgnore` against
 *   `UNIQUE(company_id, provider, provider_message_id)` *is* the dedupe: a provider that retries after
 *   a timeout gets a report of duplicates instead of a second copy of every fix.
 * - **A bad message costs the message, not the batch.** A device that woke up at 91° north, or that
 *   names a truck this company does not have, is refused and logged; the rest of the backlog still
 *   lands. Refusing the whole batch would let one broken device blind the map.
 * - **The projection moves only forwards.** `latest_positions` is what the map reads, and a buffered
 *   device posting an hour-old fix must not put the truck back where it was.
 *
 * No business invariant depends on any of this (§16 R6): a trip's distance comes from its odometer
 * pair, not from these rows, so an outage costs the map and nothing else.
 */
final class IngestPositions
{
    /** Rows per INSERT statement. A webhook batch is normally far below this. */
    private const CHUNK = 500;

    public function __construct(
        private readonly Registry $adapters,
        private readonly FleetDirectory $fleet,
        private readonly TripsInMotion $trips,
    ) {}

    /** @param array<string, mixed> $payload the provider's decoded request body */
    public function __invoke(GpsProviderAccount $account, array $payload): IngestReport
    {
        if (! $account->active) {
            throw new InvalidArgumentException(sprintf(
                'The %s is switched off, so its messages are not accepted (§4.4).',
                $account->label(),
            ));
        }

        $adapter = $this->adapters->for($account->adapter);
        $positions = $adapter->parse($payload);

        if ($positions === []) {
            return new IngestReport(0, 0, 0, 0);
        }

        // A webhook arrives with no session, so the account is the tenancy statement (§8). The pin is
        // restored rather than forgotten on the way out: the caller — a queued job, a verifier — may be
        // pinned to its own company, and erasing that pin is §20.4's leak in reverse.
        $previous = CurrentCompany::pin();
        CurrentCompany::force((int) $account->company_id);

        try {
            $report = $this->store((int) $account->company_id, $adapter->adapter(), $positions);

            // The feed's own clock. A provider account whose `last_sync_at` is stale is the difference
            // between "no trucks are moving" and "the gateway stopped calling us", and Phase 7's alert
            // reads this column rather than the positions table.
            $account->forceFill(['last_sync_at' => Carbon::now()])->save();

            return $report;
        } finally {
            CurrentCompany::force($previous);
        }
    }

    /** @param list<PositionDto> $positions */
    private function store(int $company, string $provider, array $positions): IngestReport
    {
        $rejected = 0;

        /** @var array<int, bool> $trucks memo of truck ids already checked against the fleet */
        $trucks = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        /** @var array<int, PositionDto> $newest the fix that would move each truck's projection */
        $newest = [];
        /** @var array<int, string|null> $newestTrip ...and the trip that fix is filed under */
        $newestTrip = [];
        $seen = [];

        foreach ($positions as $position) {
            if (isset($seen[$position->providerMessageId])) {
                // The provider repeated itself inside one request. The unique key would have caught it;
                // counting it here keeps the report honest about what was actually in the payload.
                $rejected++;

                continue;
            }

            $seen[$position->providerMessageId] = true;

            $refusal = $this->refusal($position, $trucks);

            if ($refusal !== null) {
                $rejected++;
                Log::warning('GPS message rejected', [
                    'provider' => $provider,
                    'message_id' => $position->providerMessageId,
                    'reason' => $refusal,
                ]);

                continue;
            }

            $tripId = $this->tripFor($position);

            $rows[] = [
                'company_id' => $company,
                'provider' => $provider,
                'provider_message_id' => $position->providerMessageId,
                'truck_id' => $position->truckId,
                'trip_id' => $tripId,
                'fixed_at' => $position->fixedAt->format('Y-m-d H:i:s'),
                'lat_10_7' => $position->lat,
                'lng_10_7' => $position->lng,
                'speed_kph' => $position->speedKph,
                'heading_deg' => $position->headingDeg,
                'odometer_km' => $position->odometerKm,
                'accuracy_m' => $position->accuracyM,
                'raw_json' => $position->raw === [] ? null : json_encode($position->raw, JSON_THROW_ON_ERROR),
                'ingested_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];

            $current = $newest[$position->truckId] ?? null;

            if ($current === null || $position->fixedAt->greaterThan($current->fixedAt)) {
                $newest[$position->truckId] = $position;
                $newestTrip[$position->truckId] = $tripId;
            }
        }

        $stored = 0;

        DB::transaction(function () use ($company, $provider, $rows, $newest, $newestTrip, &$stored): void {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $stored += DB::table('gps_positions')->insertOrIgnore($chunk);
            }

            $this->moveProjection($company, $provider, $newest, $newestTrip);
        });

        return new IngestReport(
            received: count($positions),
            stored: $stored,
            duplicates: max(0, count($rows) - $stored),
            rejected: $rejected,
        );
    }

    /**
     * @param  array<int, bool>  $trucks
     * @return string|null the reason to refuse this message, or null to accept it
     */
    private function refusal(PositionDto $position, array &$trucks): ?string
    {
        if ($position->lat < -90 || $position->lat > 90) {
            return sprintf('latitude %s is not on the planet', $position->lat);
        }

        if ($position->lng < -180 || $position->lng > 180) {
            return sprintf('longitude %s is not on the planet', $position->lng);
        }

        if ($position->speedKph !== null && $position->speedKph < 0) {
            return 'a negative speed';
        }

        if ($position->headingDeg !== null && ($position->headingDeg < 0 || $position->headingDeg > 359)) {
            return sprintf('heading %d° is not a bearing', $position->headingDeg);
        }

        if ($position->odometerKm !== null && $position->odometerKm < 0) {
            return 'a negative odometer';
        }

        if (! array_key_exists($position->truckId, $trucks)) {
            $trucks[$position->truckId] = $this->fleet->truck($position->truckId) !== null;
        }

        if ($trucks[$position->truckId] === false) {
            return sprintf('truck #%d is not in this company\'s fleet', $position->truckId);
        }

        // A clock an hour ahead is a device fault, not a fix. Anything less is left alone: border
        // queues and a drifted RTC are normal, and dropping those rows would lose real track.
        if ($position->fixedAt->greaterThan(Carbon::now()->addHour())) {
            return sprintf('a fix from %s, which is in the future', $position->fixedAt->toIso8601String());
        }

        return null;
    }

    /**
     * The trip this fix belongs to.
     *
     * A payload that names a trip is believed only if that trip is really this truck's: a device left
     * configured with a finished journey would otherwise paint a track on a trip the truck never ran.
     * A payload that names none is attached to the trip the truck is on, which is what lets a provider
     * that knows nothing about trips still produce a trip map.
     */
    private function tripFor(PositionDto $position): ?string
    {
        if ($position->tripId !== null && $this->trips->belongsToTruck($position->tripId, $position->truckId)) {
            return $position->tripId;
        }

        return $this->trips->currentForTruck($position->truckId)?->id;
    }

    /**
     * @param  array<int, PositionDto>  $newest
     * @param  array<int, string|null>  $newestTrip
     */
    private function moveProjection(int $company, string $provider, array $newest, array $newestTrip): void
    {
        foreach ($newest as $truckId => $position) {
            // The row's own id, so the projection can cite the fix it is showing. A retried message
            // made no row, and then the projection keeps citing the one it already had.
            $positionId = DB::table('gps_positions')
                ->where('company_id', $company)
                ->where('provider', $provider)
                ->where('provider_message_id', $position->providerMessageId)
                ->value('id');

            $columns = [
                'trip_id' => $newestTrip[$truckId] ?? null,
                'position_id' => $positionId === null ? null : (int) $positionId,
                'provider' => $provider,
                'lat_10_7' => $position->lat,
                'lng_10_7' => $position->lng,
                'speed_kph' => $position->speedKph,
                'heading_deg' => $position->headingDeg,
                'odometer_km' => $position->odometerKm,
                'accuracy_m' => $position->accuracyM,
                'fixed_at' => $position->fixedAt->format('Y-m-d H:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];

            $existing = DB::table('latest_positions')
                ->where('company_id', $company)
                ->where('truck_id', $truckId)
                ->first(['id', 'fixed_at']);

            if ($existing === null) {
                DB::table('latest_positions')->insert($columns + ['company_id' => $company, 'truck_id' => $truckId]);

                continue;
            }

            if (Carbon::parse($existing->fixed_at)->lessThan($position->fixedAt)) {
                DB::table('latest_positions')->where('id', $existing->id)->update($columns);
            }
        }
    }
}
