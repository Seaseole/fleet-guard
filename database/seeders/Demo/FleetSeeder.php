<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Fleet\Actions\AssignVehicle;
use App\Domain\Fleet\Actions\CreateEquipment;
use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Data\EquipmentDto;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Plausibility;
use App\Domain\Kernel\Documents\Actions\RegisterDocument;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds 22 trucks, 22 trailers, equipment, employees, drivers, assignments and the paper that proves
 * the first few (§15.2 Phase 1 definition of done).
 *
 * SADC corridor configuration: Gaborone - Windhoek, Gaborone - Durban, Lusaka, Beira. Expiries are
 * deliberately spread across current / lapsing-soon / lapsed so the expiry board, the dashboard
 * figures and the reminder engine all have something true to show.
 *
 * Every row goes through the module's own Action, never `Model::create()`: a seeder that bypasses
 * the state machine would produce demo data the application itself would have refused.
 */
final class FleetSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->first() ?? Company::create([
            'id' => 1,
            'name' => 'Fleet Guard Logistics',
            'base_currency_code' => 'BWP',
            'timezone' => 'Africa/Gaborone',
            'fiscal_year_starts_on' => '2026-07-01',
        ]);

        CurrentCompany::force($company->id);

        $now = CarbonImmutable::now();

        // 1. Employees & drivers. Two papers deliberately inside the warning window and one lapsed:
        //    a demo where everything is green proves nothing about the expiry board.
        $driverProfiles = [
            ['emp' => 'EMP-001', 'name' => 'Kabelo Tau', 'role' => 'Senior Long-haul Driver', 'lic' => 'DL-982134', 'code' => 'EC', 'lic_exp' => $now->addMonths(14), 'pdp_exp' => $now->addMonths(8), 'kiosk' => true],
            ['emp' => 'EMP-002', 'name' => 'Mpho Moloi', 'role' => 'Cross-Border Driver', 'lic' => 'DL-771245', 'code' => 'EC', 'lic_exp' => $now->addMonths(10), 'pdp_exp' => $now->addDays(12), 'kiosk' => true],
            ['emp' => 'EMP-003', 'name' => 'Thabo Ndlovu', 'role' => 'Cross-Border Driver', 'lic' => 'DL-552190', 'code' => 'EC', 'lic_exp' => $now->subDays(2), 'pdp_exp' => $now->addMonths(6), 'kiosk' => true],
            ['emp' => 'EMP-004', 'name' => 'Tumelo Khama', 'role' => 'Regional Driver', 'lic' => 'DL-661289', 'code' => 'EC', 'lic_exp' => $now->addMonths(5), 'pdp_exp' => $now->addMonths(5), 'kiosk' => true],
            ['emp' => 'EMP-005', 'name' => 'Sipho Moyo', 'role' => 'Heavy Haulage Driver', 'lic' => 'DL-334190', 'code' => 'EC', 'lic_exp' => $now->addMonths(18), 'pdp_exp' => $now->addMonths(11), 'kiosk' => true],
            ['emp' => 'EMP-006', 'name' => 'Ditiro Sechele', 'role' => 'Relief Driver', 'lic' => 'DL-881923', 'code' => 'EC', 'lic_exp' => $now->addMonths(8), 'pdp_exp' => $now->addMonths(3), 'kiosk' => true],
            ['emp' => 'EMP-007', 'name' => 'Lesego Kgosi', 'role' => 'Long-haul Driver', 'lic' => 'DL-112349', 'code' => 'EC', 'lic_exp' => $now->addMonths(20), 'pdp_exp' => $now->addMonths(14), 'kiosk' => true],
            ['emp' => 'EMP-008', 'name' => 'Bakani Dube', 'role' => 'Local Shunter / Driver', 'lic' => 'DL-449102', 'code' => 'C1', 'lic_exp' => $now->addMonths(12), 'pdp_exp' => $now->addMonths(12), 'kiosk' => false],
        ];

        $driverModels = [];
        $createEmployee = app(CreateEmployee::class);
        $createDriver = app(CreateDriver::class);

        foreach ($driverProfiles as $profile) {
            $employee = ($createEmployee)(new EmployeeDto(
                employeeNo: $profile['emp'],
                name: $profile['name'],
                hiredOn: $now->subMonths(18)->toDateString(),
                grossSalaryBase: 1_200_000, // 12,000.00 BWP
                payFrequency: 'monthly',
                nationalId: 'ID-'.random_int(100000, 999999),
                roleOrGrade: $profile['role'],
            ));

            $driverModels[] = ($createDriver)(new DriverDto(
                employeeId: $employee->id,
                licenceNo: $profile['lic'],
                licenceCode: $profile['code'],
                licenceExpiresOn: $profile['lic_exp']->toDateString(),
                permitExpiresOn: $profile['pdp_exp']->toDateString(),
                allowanceRateBase: 35_000, // 350.00 BWP per night
                fuelEfficiencyBaseline: 34.50,
                kioskEnabled: $profile['kiosk'],
            ));
        }

        // 2. Trucks.
        $createTruck = app(CreateTruck::class);
        $recordOdometer = app(RecordOdometerReading::class);
        $truckModels = [];

        $makes = ['Volvo FH 500', 'Scania R500', 'Mercedes-Benz Actros 2645', 'MAN TGX 26.480', 'DAF XF 480'];

        for ($i = 1; $i <= 22; $i++) {
            $fleetNo = sprintf('T-%02d', $i);
            $regNo = sprintf('B %03d %s', 100 + $i, chr(65 + ($i % 26)).chr(66 + ($i % 25)));
            $makeModel = $makes[$i % count($makes)];
            [$make, $model] = explode(' ', $makeModel, 2);

            $initialKm = 120_000 + ($i * 14_500);
            $engineHours = round($initialKm / 65, 2);

            $truck = ($createTruck)(new TruckDto(
                registrationNo: $regNo,
                fleetNo: $fleetNo,
                vin: sprintf('VIN99BWPTRUCK%04d', $i),
                make: $make,
                model: $model,
                year: 2022 + ($i % 4),
                capacityTonnes: 34.00,
                odometerKm: $initialKm,
                engineHours: $engineHours,
                purchaseAmountBase: 145_000_000, // 1,450,000.00 BWP
                purchasedOn: $now->subMonths(24)->toDateString(),
                tyreSize: '315/80R22.5',
                serviceIntervalKm: 30_000,
                serviceIntervalHours: 500,
                serviceIntervalMonths: 6,
                fuelType: 'diesel',
                mainTankLitres: 450,
                auxTankLitres: 350,
                expectedConsumptionPer100: 34.50,
            ));

            ($recordOdometer)(new OdometerReadingDto(
                truckId: $truck->id,
                odometerKm: $initialKm,
                readOn: $now->subDays(1)->toDateString(),
                source: OdometerSource::Manual,
                plausibility: Plausibility::Ok,
                engineHours: $engineHours,
            ));

            $truckModels[] = $truck;
        }

        // Workshop and off-road states go through the state machine, reason included, so the demo
        // rows carry the same event trail a real move would.
        $truckModels[8]->moveTo(FleetStatus::InWorkshop);
        $truckModels[9]->moveTo(FleetStatus::InWorkshop);
        $truckModels[10]->moveTo(FleetStatus::OutOfService, 'Gearbox failure; awaiting parts from Johannesburg.');

        // 3. Trailers.
        $createTrailer = app(CreateTrailer::class);
        $trailerTypes = ['flatbed', 'curtainsider', 'tipper', 'tanker', 'lowboy'];
        $trailerModels = [];

        for ($j = 1; $j <= 22; $j++) {
            $trailerModels[] = ($createTrailer)(new TrailerDto(
                registrationNo: sprintf('B %03d TLR', 200 + $j),
                type: $trailerTypes[$j % count($trailerTypes)],
                fleetNo: sprintf('TR-%02d', $j),
                vin: sprintf('VIN99BWPTRAIL%04d', $j),
                capacityTonnes: 36.00,
                axleConfig: 'Tri-axle',
                odometerKm: 85_000 + ($j * 5_000),
            ));
        }

        // 4. Equipment.
        $createEquipment = app(CreateEquipment::class);
        $equipmentItems = [
            ['code' => 'EQ-GEN-01', 'kind' => 'generator', 'price' => 4_500_000],
            ['code' => 'EQ-GEN-02', 'kind' => 'generator', 'price' => 4_500_000],
            ['code' => 'EQ-LDR-01', 'kind' => 'loader', 'price' => 12_500_000],
            ['code' => 'EQ-TOOL-01', 'kind' => 'toolbox', 'price' => 150_000],
            ['code' => 'EQ-TOOL-02', 'kind' => 'toolbox', 'price' => 150_000],
        ];

        foreach ($equipmentItems as $equipment) {
            ($createEquipment)(new EquipmentDto(
                code: $equipment['code'],
                kind: $equipment['kind'],
                purchaseAmountBase: $equipment['price'],
            ));
        }

        // 5. Open assignments — only for drivers whose papers actually cover today, because the
        //    Action refuses the rest and a seeded fleet must not contain a dispatch the app forbids.
        $assignVehicle = app(AssignVehicle::class);
        $eligible = array_values(array_filter($driverModels, fn ($driver): bool => $driver->eligibleOn($now->startOfDay())));

        foreach (array_slice($eligible, 0, 6) as $index => $driver) {
            ($assignVehicle)(new AssignmentDto(
                truckId: $truckModels[$index]->id,
                fromAt: $now->subDays(7)->toDateTimeString(),
                trailerId: $trailerModels[$index]->id,
                driverId: $driver->id,
                reason: 'Regular corridor allocation',
            ));
        }

        // 6. Paper on file for the first driver and the first truck, so the register and the two
        //    subject links are exercised by real rows rather than by hand during a demo.
        $register = app(RegisterDocument::class);

        ($register)->handle(
            DocumentType::Licence,
            'Driving licence — '.$driverModels[0]->employee->name,
            morphType: 'driver',
            morphId: (string) $driverModels[0]->id,
            expiresOn: $driverModels[0]->licence_expires_on,
            notes: 'Scan filed with the personnel record.',
        );

        $insuranceTruck = Truck::query()->findOrFail($truckModels[0]->id);

        ($register)->handle(
            DocumentType::Insurance,
            'Commercial insurance — '.$insuranceTruck->label(),
            morphType: 'truck',
            morphId: (string) $insuranceTruck->id,
            expiresOn: $now->addDays(20)->startOfDay(),
        );

        CurrentCompany::forget();
    }
}
