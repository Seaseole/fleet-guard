<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use InvalidArgumentException;

/**
 * §15.2 Phase 1 — the trailer register.
 *
 * The type is §4.3's five-value vocabulary; a file that says "curtain side" gets a row error naming
 * the accepted values rather than a CHECK violation. `fleet_no` and `vin` are alternate unique keys
 * exactly as they are on trucks.
 */
final class ImportTrailers implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateTrailer $create,
        private readonly UpdateTrailer $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $fleetOwners = [];
        $vinOwners = [];

        foreach (Trailer::query()->get(['registration_no', 'fleet_no', 'vin']) as $trailer) {
            $registration = ImportValues::codeKey($trailer->registration_no);

            if ($trailer->fleet_no !== null && $trailer->fleet_no !== '') {
                $fleetOwners[ImportValues::codeKey($trailer->fleet_no)] = $registration;
            }

            if ($trailer->vin !== null && $trailer->vin !== '') {
                $vinOwners[ImportValues::codeKey($trailer->vin)] = $registration;
            }
        }

        $seenRegistrations = [];
        $seenFleet = [];
        $seenVin = [];

        return CutoverRows::read(
            CutoverDataset::Trailers,
            $contents,
            function (CutoverCells $cells) use ($fleetOwners, $vinOwners, &$seenRegistrations, &$seenFleet, &$seenVin) {
                $errors = [];

                [$registration, $error] = ImportValues::text($cells->get('registration_no'), 24, 'Registration number', true);
                ImportValues::push($errors, $error);

                [$typeRaw, $error] = ImportValues::text($cells->get('type'), 20, 'Type');
                ImportValues::push($errors, $error);

                $type = $typeRaw === null ? 'flatbed' : strtolower($typeRaw);

                if (! in_array($type, TrailerDto::TYPES, true)) {
                    $errors[] = sprintf('Type "%s" is not one of %s.', $typeRaw, implode(', ', TrailerDto::TYPES));
                }

                [$fleetNo, $error] = ImportValues::text($cells->get('fleet_no'), 24, 'Fleet number');
                ImportValues::push($errors, $error);

                [$vin, $error] = ImportValues::text($cells->get('vin'), 32, 'VIN');
                ImportValues::push($errors, $error);

                [$capacityTonnes, $error] = ImportValues::decimal($cells->get('capacity_tonnes'), 'Capacity (tonnes)');
                ImportValues::push($errors, $error);

                [$axleConfig, $error] = ImportValues::text($cells->get('axle_config'), 40, 'Axle configuration');
                ImportValues::push($errors, $error);

                [$odometerKm, $error] = ImportValues::integer($cells->get('odometer_km'), 'Odometer (km)');
                ImportValues::push($errors, $error);

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($registration !== null) {
                    $registrationKey = ImportValues::codeKey($registration);

                    if (isset($seenRegistrations[$registrationKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the registration "%s".',
                            $seenRegistrations[$registrationKey],
                            $registration,
                        );
                    } else {
                        $seenRegistrations[$registrationKey] = $cells->line;
                    }

                    if ($fleetNo !== null) {
                        $fleetKey = ImportValues::codeKey($fleetNo);
                        $owner = $fleetOwners[$fleetKey] ?? $seenFleet[$fleetKey] ?? null;

                        if ($owner !== null && $owner !== $registrationKey) {
                            $errors[] = sprintf('Fleet number "%s" already belongs to trailer %s.', $fleetNo, strtoupper($owner));
                        }

                        $seenFleet[$fleetKey] = $registrationKey;
                    }

                    if ($vin !== null) {
                        $vinKey = ImportValues::codeKey($vin);
                        $owner = $vinOwners[$vinKey] ?? $seenVin[$vinKey] ?? null;

                        if ($owner !== null && $owner !== $registrationKey) {
                            $errors[] = sprintf('VIN "%s" already belongs to trailer %s.', $vin, strtoupper($owner));
                        }

                        $seenVin[$vinKey] = $registrationKey;
                    }
                }

                $values = [
                    'registration_no' => $registration,
                    'type' => $type,
                    'fleet_no' => $fleetNo,
                    'vin' => $vin,
                    'capacity_tonnes' => $capacityTonnes,
                    'axle_config' => $axleConfig,
                    'odometer_km' => $odometerKm,
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        TrailerDto::fromArray($values);
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $dto = TrailerDto::fromArray($row->payload ?? []);
            $existing = Trailer::query()->where('registration_no', $dto->registrationNo)->first();

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }
}
