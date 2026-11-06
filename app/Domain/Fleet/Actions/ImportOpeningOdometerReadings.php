<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Models\OdometerReading;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use Carbon\Carbon;

/**
 * Phase 0.5 — the opening odometer file.
 *
 * One row is one reading: what a truck's face said on a particular day, before this system had eyes on
 * it. The rows go through {@see RecordOdometerReading}, the same door the workshop and the kiosk use,
 * which means the two rules that make the timeline honest apply to imported history exactly as they do
 * to live ones: a reading below the truck's recorded distance is stored and marked `suspect` rather
 * than believed, and the projection only ever rises.
 *
 * That is the whole reason this is a readings file and not a `trucks.odometer_km` update. The
 * trucks importer already takes an odometer — for a truck it creates, in the same breath — and refuses
 * one for a truck that already exists, because overwriting the projection is how a fleet loses an
 * argument about distance. The distance a truck has gone is a **series of measurements**, and the
 * accountant's spreadsheet is a set of measurements too.
 *
 * Applying is ordered oldest reading first, and a reading that is already on the book for that truck,
 * that day and that figure is skipped rather than written twice: a retried intake leaves the history
 * exactly as it found it (the retry rule, in a table with no natural key of its own).
 */
final class ImportOpeningOdometerReadings implements CutoverDatasetHandler
{
    public function __construct(
        private readonly RecordOdometerReading $record,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $byRegistration = [];

        foreach (Truck::query()->get(['id', 'registration_no']) as $truck) {
            $byRegistration[strtoupper(trim((string) $truck->registration_no))] = $truck->id;
        }

        $seen = [];

        return CutoverRows::read(
            CutoverDataset::OpeningOdometerReadings,
            $contents,
            function (CutoverCells $cells) use ($byRegistration, &$seen): CutoverRowResult {
                $errors = [];

                $registration = strtoupper(trim($cells->get('registration_no')));

                if ($registration === '') {
                    $errors[] = 'Registration number is required — a reading with no truck is a number nobody will ever be asked about.';
                } elseif (! isset($byRegistration[$registration])) {
                    $errors[] = sprintf(
                        'Truck "%s" is not in the fleet. Import the trucks file first: an odometer reading belongs to a truck, '
                        .'not to a registration the register has never seen.',
                        $registration,
                    );
                }

                [$readOn, $error] = ImportValues::date($cells->get('read_on'), 'Read on', true);
                ImportValues::push($errors, $error);

                [$odometerKm, $error] = ImportValues::integer($cells->get('odometer_km'), 'Odometer km', true);
                ImportValues::push($errors, $error);

                if ($odometerKm !== null && $odometerKm < 0) {
                    $errors[] = 'An odometer reading cannot be negative.';
                }

                $engineHours = trim($cells->get('engine_hours'));
                $engineHoursValue = null;

                if ($engineHours !== '') {
                    if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $engineHours) !== 1) {
                        $errors[] = 'Engine hours must be a positive number with at most 2 decimals.';
                    } else {
                        $engineHoursValue = (float) $engineHours;
                    }
                }

                if ($registration !== '' && $readOn !== null) {
                    $key = $registration.'|'.$readOn;

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already carries a reading for %s on %s.', $seen[$key], $registration, $readOn);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'registration_no' => $registration === '' ? null : $registration,
                    'read_on' => $readOn,
                    'odometer_km' => $odometerKm,
                    'engine_hours' => $engineHoursValue,
                ], static fn (mixed $value): bool => $value !== null);

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $rows = $batch->rows()
            ->where('status', 'valid')
            ->orderBy('line_number')
            ->get();

        // Oldest first: the projection climbs, so a file applied newest-first would mark the older
        // — and smaller — true reading suspect on its way past it.
        $ordered = $rows->sortBy([
            static fn ($a, $b): int => strcmp((string) ($a->payload['read_on'] ?? ''), (string) ($b->payload['read_on'] ?? '')),
        ])->values();

        $applied = 0;

        foreach ($ordered as $row) {
            $payload = $row->payload ?? [];
            $truck = Truck::query()->where('registration_no', (string) ($payload['registration_no'] ?? ''))->first();

            if ($truck === null) {
                // Validation already refused this; reaching it means the truck went missing between
                // the preview and the apply, which is a refusal and not a silent skip.
                throw new \InvalidArgumentException(sprintf(
                    'Row %d names truck "%s", which is no longer in the fleet — import the trucks file first.',
                    $row->line_number,
                    (string) ($payload['registration_no'] ?? ''),
                ));
            }

            $odometerKm = (int) ($payload['odometer_km'] ?? 0);
            $readOn = (string) ($payload['read_on'] ?? Carbon::now()->toDateString());

            $already = OdometerReading::query()
                ->where('truck_id', $truck->id)
                ->where('source', OdometerSource::Cutover->value)
                ->whereDate('read_on', $readOn)
                ->where('odometer_km', $odometerKm)
                ->exists();

            if ($already) {
                $applied++;

                continue;
            }

            ($this->record)(new OdometerReadingDto(
                truckId: $truck->id,
                odometerKm: $odometerKm,
                readOn: $readOn,
                source: OdometerSource::Cutover,
                engineHours: isset($payload['engine_hours']) ? (float) $payload['engine_hours'] : null,
            ));

            $applied++;
        }

        return $applied;
    }
}
