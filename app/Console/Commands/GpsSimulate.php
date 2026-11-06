<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Data\TripInMotion;
use App\Domain\Operations\Queries\TripsInMotion;
use App\Domain\Telematics\Actions\EnsureSimulatorAccount;
use App\Domain\Telematics\Actions\IngestPositions;
use App\Domain\Telematics\Data\IngestReport;
use App\Domain\Telematics\Models\GpsProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * R6's simulator: the only way to see the map before hardware arrives, and the thing that proves
 * the ingest path end to end.
 *
 * It walks a truck along its corridor, one ping per run, and sends what it generates through the same
 * {@see IngestPositions} Action a provider webhook will use — so "the simulator works" and "ingest
 * works" are the same claim. Re-running it advances the truck: progress is read back from the odometer
 * on the last fix it stored, which is also how a real device's backlog would be handled.
 *
 * Coordinates come from `fleet.telematics.simulator.places`, matched against the route's endpoint
 * names. A corridor whose endpoint is not in that list is skipped with a message rather than guessed
 * at, and `--from`/`--to` are the escape hatch: an invented coordinate would put a truck in a place it
 * was never driving, which is the one thing a simulator must not do.
 */
class GpsSimulate extends Command
{
    protected $signature = 'gps:simulate
        {trip? : One trip by ULID or trip number. Omit for every trip on the road.}
        {--from= : Corridor start as "lat,lng", overriding the place table}
        {--to= : Corridor end as "lat,lng", overriding the place table}
        {--pings=1 : Fixes to generate per truck in this run}
        {--speed= : km/h to simulate. Defaults to fleet.telematics.simulator.speed_kph}
        {--company= : Company to simulate for. Defaults to the pinned company, else 1.}';

    protected $description = 'Generate simulated GPS fixes for trips on the road and ingest them';

    /** Seconds between two pings from one device (the volume figure assumes 30 s). */
    private const INTERVAL = 30;

    public function handle(TripsInMotion $trips, EnsureSimulatorAccount $ensure, IngestPositions $ingest): int
    {
        $company = $this->option('company') !== null
            ? (int) $this->option('company')
            : (CurrentCompany::pin() ?? 1);

        $previous = CurrentCompany::pin();
        CurrentCompany::force($company);

        try {
            $account = $ensure();

            $filter = $this->argument('trip');

            if (is_string($filter) && $filter !== '') {
                $trip = $trips->find($filter);

                if ($trip === null) {
                    $this->error(sprintf(
                        'No trip "%s" is on the road in company %d. Only a departed, in-transit or at-border trip is tracked.',
                        $filter,
                        $company,
                    ));

                    return self::FAILURE;
                }

                $onRoad = [$trip];
            } else {
                $onRoad = $trips->active();
            }

            if ($onRoad === []) {
                $this->warn('No trip is on the road, so there is nothing to track. Depart a trip first, or name one: php artisan gps:simulate TRIP-2026-0001');

                return self::SUCCESS;
            }

            $stored = 0;
            $skipped = 0;

            foreach ($onRoad as $trip) {
                try {
                    $report = $this->simulate($trip, $account, $ingest);
                } catch (InvalidArgumentException $e) {
                    $skipped++;
                    $this->warn(sprintf('  %s skipped — %s', $trip->tripNo, $e->getMessage()));

                    continue;
                }

                $stored += $report->stored + $report->duplicates;
                $this->line(sprintf('  %s — %s', $trip->tripNo, $report->summary()));
            }

            $this->newLine();
            $this->info(sprintf(
                '%d trip(s) simulated through the %s feed, %d fix(es) on file, %d skipped.',
                count($onRoad) - $skipped,
                $account->adapter,
                $stored,
                $skipped,
            ));

            return $skipped === count($onRoad) ? self::FAILURE : self::SUCCESS;
        } finally {
            CurrentCompany::force($previous);
        }
    }

    private function simulate(TripInMotion $trip, GpsProviderAccount $account, IngestPositions $ingest): IngestReport
    {
        [$fromLat, $fromLng, $toLat, $toLng] = $this->endpoints($trip);

        $distance = $trip->distanceKm ?? 0.0;

        if ($distance <= 0) {
            throw new InvalidArgumentException(sprintf('the corridor %s has no distance to walk', (string) $trip->routeCode));
        }

        $speed = $this->option('speed') !== null
            ? (int) $this->option('speed')
            : (int) config('fleet.telematics.simulator.speed_kph', 80);

        $pings = max(1, (int) $this->option('pings'));
        $progress = $this->progress($trip, $distance);
        $step = ($speed * self::INTERVAL / 3600) / $distance;
        $heading = $this->bearing($fromLat, $fromLng, $toLat, $toLng);

        $messages = [];

        for ($i = 0; $i < $pings; $i++) {
            $fraction = min(1.0, $progress + ($i + 1) * $step);

            $messages[] = [
                'id' => 'SIM-'.Str::upper((string) Str::ulid()),
                'truck_id' => $trip->truckId,
                'trip_id' => $trip->id,
                'fixed_at' => CarbonImmutable::now()->subSeconds(($pings - 1 - $i) * self::INTERVAL)->format('Y-m-d H:i:s'),
                'lat' => round($fromLat + ($toLat - $fromLat) * $fraction, 7),
                'lng' => round($fromLng + ($toLng - $fromLng) * $fraction, 7),
                'speed_kph' => $speed,
                'heading_deg' => $heading,
                'odometer_km' => ($trip->odometerStartKm ?? 0) + (int) round($distance * $fraction),
                'accuracy_m' => 8,
            ];
        }

        return $ingest($account, ['messages' => $messages]);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function endpoints(TripInMotion $trip): array
    {
        $from = $this->point('from');
        $to = $this->point('to');

        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('--from and --to come as a pair');
        }

        if ($from !== null && $to !== null) {
            return [...$from, ...$to];
        }

        /** @var array<string, array{0: float, 1: float}> $places */
        $places = (array) config('fleet.telematics.simulator.places', []);

        $origin = $this->place($places, $trip->origin);
        $destination = $this->place($places, $trip->destination);

        if ($origin === null || $destination === null) {
            $missing = $origin === null ? (string) $trip->origin : (string) $trip->destination;

            throw new InvalidArgumentException(sprintf(
                'the simulator has no coordinates for "%s". Add it to fleet.telematics.simulator.places, or pass --from=lat,lng --to=lat,lng',
                $missing,
            ));
        }

        return [...$origin, ...$destination];
    }

    /** @return array{0: float, 1: float}|null */
    private function point(string $option): ?array
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            throw new InvalidArgumentException(sprintf('--%s must be "lat,lng", e.g. --%s=-24.6282,25.9231', $option, $option));
        }

        return [(float) $parts[0], (float) $parts[1]];
    }

    /**
     * @param  array<string, array{0: float, 1: float}>  $places
     * @return array{0: float, 1: float}|null
     */
    private function place(array $places, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        $wanted = mb_strtolower(trim($name));

        foreach ($places as $place => $coordinates) {
            if (mb_strtolower((string) $place) === $wanted) {
                return [(float) $coordinates[0], (float) $coordinates[1]];
            }
        }

        return null;
    }

    /**
     * How far along the corridor the truck already is, as a fraction of the distance.
     *
     * Read from the last fix's odometer when there is one, so each run continues where the previous
     * stopped. With no fix yet it is estimated from the departure clock at the simulated speed — an
     * estimate is honest here because nothing downstream reads it: R6 keeps every business number
     * on the odometer and the trip facts.
     */
    private function progress(TripInMotion $trip, float $distance): float
    {
        $start = $trip->odometerStartKm ?? 0;

        $lastOdometer = DB::table('gps_positions')
            ->where('trip_id', $trip->id)
            ->whereNotNull('odometer_km')
            ->orderByDesc('fixed_at')
            ->orderByDesc('id')
            ->value('odometer_km');

        if ($lastOdometer !== null) {
            return $this->clamp(((int) $lastOdometer - $start) / $distance);
        }

        if ($trip->departedAt === null) {
            return 0.0;
        }

        $speed = (int) config('fleet.telematics.simulator.speed_kph', 80);

        return $this->clamp($trip->departedAt->diffInHours(CarbonImmutable::now()) * $speed / $distance);
    }

    private function clamp(float $fraction): float
    {
        return max(0.0, min(1.0, $fraction));
    }

    /** Initial bearing between two points, as whole degrees clockwise from north. */
    private function bearing(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad($toLat);
        $dlng = deg2rad($toLng - $fromLng);

        $y = sin($dlng) * cos($lat2);
        $x = (cos($lat1) * sin($lat2)) - (sin($lat1) * cos($lat2) * cos($dlng));

        return ((int) round(fmod(rad2deg(atan2($y, $x)) + 360, 360))) % 360;
    }
}
