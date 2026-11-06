<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\SupplierCategory;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Throwaway helper for the browser pass: it creates the same named fixtures
 * `verify-opening-intake.php` uses (OPENSUP-*, OPENEMP-*, "B 9001 OV*"), so the two demo files in
 * `docs/cutover/demo/` validate against the dev book through the cutover screen, and running that
 * harness afterwards removes them again — its opening sweep is the cleanup.
 *
 * Run: php scripts/tmp-cutover-browser-fixtures.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "REFUSED: dev only.\n");
    exit(2);
}

CurrentCompany::force(1);

$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', 'owner')
    ->select('users.id')
    ->first();

$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner seat. Run: php artisan fleet:setup-owner.\n");
    exit(2);
}

Auth::login($owner);

foreach ([
    ['OPENSUP-1', 'OPENSUP Garage', 'workshop'],
    ['OPENSUP-2', 'OPENSUP Clearing Agent', 'customs'],
    ['OPENSUP-3', 'OPENSUP Insurer', 'insurance'],
] as [$code, $name, $category]) {
    if (Supplier::query()->where('code', $code)->doesntExist()) {
        (app(CreateSupplier::class))(new SupplierDto(
            name: $name,
            code: $code,
            category: SupplierCategory::tryFrom($category) ?? SupplierCategory::Other,
            paymentTerms: PaymentTerms::DEFAULT,
        ));
    }
}

foreach ([
    ['B 9001 OV1', 'T-9001-1', 'VIN9001000000000000001'],
    ['B 9001 OV2', 'T-9001-2', 'VIN9001000000000000002'],
] as [$registration, $fleetNo, $vin]) {
    if (Truck::query()->where('registration_no', $registration)->doesntExist()) {
        (app(CreateTruck::class))(new TruckDto(
            registrationNo: $registration,
            fleetNo: $fleetNo,
            vin: $vin,
            make: 'Probe',
            model: 'Browser pass',
            odometerKm: 10_000,
        ));
    }
}

$employeeIds = [];

foreach ([
    ['OPENEMP-1', 'OPENEMP First Driver'],
    ['OPENEMP-2', 'OPENEMP Second Driver'],
    ['OPENEMP-3', 'OPENEMP Office Clerk'],
] as [$employeeNo, $name]) {
    $employee = Employee::query()->where('employee_no', $employeeNo)->first();

    if ($employee === null) {
        $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: $employeeNo, name: $name, hiredOn: '2025-01-06'));
    }

    $employeeIds[$employeeNo] = (int) $employee->id;
}

foreach (['OPENEMP-1', 'OPENEMP-2'] as $employeeNo) {
    if (DB::table('drivers')->where('employee_id', $employeeIds[$employeeNo])->doesntExist()) {
        (app(CreateDriver::class))(new DriverDto(employeeId: $employeeIds[$employeeNo]));
    }
}

printf(
    "fixtures ready · suppliers=%d trucks=%d employees=%d drivers=%d (owner user %d)\n",
    Supplier::query()->where('code', 'like', 'OPENSUP-%')->count(),
    Truck::query()->where('registration_no', 'like', 'B 9001 OV%')->count(),
    Employee::query()->where('employee_no', 'like', 'OPENEMP-%')->count(),
    DB::table('drivers')->whereIn('employee_id', array_values($employeeIds))->count(),
    (int) $owner->id,
);
