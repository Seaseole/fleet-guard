<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use InvalidArgumentException;

/**
 * Phase 1 — the truck register.
 *
 * Matched by registration number, which is how the fleet is spoken about; `fleet_no` and `vin` are
 * two alternate unique keys, so each is checked against the database and the rest of the file before
 * the row is accepted.
 *
 * An existing truck is updated through {@see UpdateTruck}, which deliberately ignores
 * `odometer_km`: mileage is a projection of readings, and an import that typed over it would be the
 * silent correction the plausibility rule exists to prevent. The file's reading therefore applies
 * only when the truck is created here.
 */
final class ImportTrucks implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateTruck $create,
        private readonly UpdateTruck $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $existing = [];
        $fleetOwners = [];
        $vinOwners = [];

        foreach (Truck::query()->get(['registration_no', 'fleet_no', 'vin']) as $truck) {
            $registration = ImportValues::codeKey($truck->registration_no);
            $existing[$registration] = true;

            if ($truck->fleet_no !== null && $truck->fleet_no !== '') {
                $fleetOwners[ImportValues::codeKey($truck->fleet_no)] = $registration;
            }

            if ($truck->vin !== null && $truck->vin !== '') {
                $vinOwners[ImportValues::codeKey($truck->vin)] = $registration;
            }
        }

        $seenRegistrations = [];
        $seenFleet = [];
        $seenVin = [];

        return CutoverRows::read(
            CutoverDataset::Trucks,
            $contents,
            function (CutoverCells $cells) use ($companyCurrency, $fleetOwners, $vinOwners, &$seenRegistrations, &$seenFleet, &$seenVin) {
                $errors = [];

                [$registration, $error] = ImportValues::text($cells->get('registration_no'), 24, 'Registration number', true);
                ImportValues::push($errors, $error);

                [$fleetNo, $error] = ImportValues::text($cells->get('fleet_no'), 24, 'Fleet number');
                ImportValues::push($errors, $error);

                [$vin, $error] = ImportValues::text($cells->get('vin'), 32, 'VIN');
                ImportValues::push($errors, $error);

                [$make, $error] = ImportValues::text($cells->get('make'), 60, 'Make');
                ImportValues::push($errors, $error);

                [$model, $error] = ImportValues::text($cells->get('model'), 60, 'Model');
                ImportValues::push($errors, $error);

                [$year, $error] = ImportValues::integer($cells->get('year'), 'Year');
                ImportValues::push($errors, $error);

                [$capacityTonnes, $error] = ImportValues::decimal($cells->get('capacity_tonnes'), 'Capacity (tonnes)');
                ImportValues::push($errors, $error);

                [$odometerKm, $error] = ImportValues::integer($cells->get('odometer_km'), 'Odometer (km)');
                ImportValues::push($errors, $error);

                [$engineHours, $error] = ImportValues::decimal($cells->get('engine_hours'), 'Engine hours');
                ImportValues::push($errors, $error);

                [$purchaseAmount, $error] = ImportValues::money($cells->get('purchase_amount'), $companyCurrency, 'Purchase amount');
                ImportValues::push($errors, $error);

                [$purchasedOn, $error] = ImportValues::date($cells->get('purchased_on'), 'Purchased on');
                ImportValues::push($errors, $error);

                [$tyreSize, $error] = ImportValues::text($cells->get('tyre_size'), 40, 'Tyre size');
                ImportValues::push($errors, $error);

                [$serviceIntervalKm, $error] = ImportValues::count($cells->get('service_interval_km'), 'Service interval (km)');
                ImportValues::push($errors, $error);

                [$serviceIntervalHours, $error] = ImportValues::count($cells->get('service_interval_hours'), 'Service interval (hours)');
                ImportValues::push($errors, $error);

                [$serviceIntervalMonths, $error] = ImportValues::count($cells->get('service_interval_months'), 'Service interval (months)');
                ImportValues::push($errors, $error);

                [$fuelType, $error] = ImportValues::text($cells->get('fuel_type'), 16, 'Fuel type');
                ImportValues::push($errors, $error);

                [$mainTankLitres, $error] = ImportValues::count($cells->get('main_tank_litres'), 'Main tank (litres)');
                ImportValues::push($errors, $error);

                [$auxTankLitres, $error] = ImportValues::count($cells->get('aux_tank_litres'), 'Auxiliary tank (litres)');
                ImportValues::push($errors, $error);

                [$expectedConsumption, $error] = ImportValues::decimal($cells->get('expected_consumption_per_100'), 'Expected consumption (per 100 km)');
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
                            $errors[] = sprintf('Fleet number "%s" already belongs to truck %s.', $fleetNo, strtoupper($owner));
                        }

                        $seenFleet[$fleetKey] = $registrationKey;
                    }

                    if ($vin !== null) {
                        $vinKey = ImportValues::codeKey($vin);
                        $owner = $vinOwners[$vinKey] ?? $seenVin[$vinKey] ?? null;

                        if ($owner !== null && $owner !== $registrationKey) {
                            $errors[] = sprintf('VIN "%s" already belongs to truck %s.', $vin, strtoupper($owner));
                        }

                        $seenVin[$vinKey] = $registrationKey;
                    }
                }

                $values = [
                    'registration_no' => $registration,
                    'fleet_no' => $fleetNo,
                    'vin' => $vin,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'capacity_tonnes' => $capacityTonnes,
                    'odometer_km' => $odometerKm,
                    'engine_hours' => $engineHours,
                    'purchase_amount' => $purchaseAmount,
                    'purchased_on' => $purchasedOn,
                    'tyre_size' => $tyreSize,
                    'service_interval_km' => $serviceIntervalKm,
                    'service_interval_hours' => $serviceIntervalHours,
                    'service_interval_months' => $serviceIntervalMonths,
                    'fuel_type' => $fuelType,
                    'main_tank_litres' => $mainTankLitres,
                    'aux_tank_litres' => $auxTankLitres,
                    'expected_consumption_per_100' => $expectedConsumption,
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        TruckDto::fromArray(self::dtoInput($values));
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
            $payload = $row->payload ?? [];
            $dto = TruckDto::fromArray(self::dtoInput($payload));
            $existing = Truck::query()->where('registration_no', $dto->registrationNo)->first();

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * The payload keeps the file's own column names so the error report prints the headers the
     * accountant wrote; the DTO's amount is base-currency typed, hence the one rename.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function dtoInput(array $payload): array
    {
        return [
            'registration_no' => $payload['registration_no'] ?? '',
            'fleet_no' => $payload['fleet_no'] ?? null,
            'vin' => $payload['vin'] ?? null,
            'make' => $payload['make'] ?? null,
            'model' => $payload['model'] ?? null,
            'year' => $payload['year'] ?? null,
            'capacity_tonnes' => $payload['capacity_tonnes'] ?? null,
            'odometer_km' => $payload['odometer_km'] ?? 0,
            'engine_hours' => $payload['engine_hours'] ?? null,
            'purchase_amount_base' => $payload['purchase_amount'] ?? 0,
            'purchased_on' => $payload['purchased_on'] ?? null,
            'tyre_size' => $payload['tyre_size'] ?? null,
            'service_interval_km' => $payload['service_interval_km'] ?? null,
            'service_interval_hours' => $payload['service_interval_hours'] ?? null,
            'service_interval_months' => $payload['service_interval_months'] ?? null,
            'fuel_type' => $payload['fuel_type'] ?? 'diesel',
            'main_tank_litres' => $payload['main_tank_litres'] ?? null,
            'aux_tank_litres' => $payload['aux_tank_litres'] ?? null,
            'expected_consumption_per_100' => $payload['expected_consumption_per_100'] ?? null,
            'active' => $payload['active'] ?? true,
        ];
    }
}
