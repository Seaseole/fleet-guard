<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Data\DriverDto;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Turns an employee into a driver (§4.3, D1).
 *
 * The employee is looked up through the tenant scope rather than by raw id, so a form that posted
 * another company's employee id cannot create a driver pointing at it — the FK would allow the row,
 * the scope is what refuses it.
 */
final class CreateDriver
{
    public function __invoke(DriverDto $dto): Driver
    {
        return DB::transaction(function () use ($dto): Driver {
            Employee::query()->findOrFail($dto->employeeId);

            $driver = new Driver([
                'employee_id' => $dto->employeeId,
                'licence_code' => $dto->licenceCode,
                'licence_expires_on' => $dto->licenceExpiresOn,
                'permit_expires_on' => $dto->permitExpiresOn,
                'default_allowance_rate_base' => $dto->allowanceRateBase,
                'fuel_efficiency_baseline_10_2' => $dto->fuelEfficiencyBaseline,
                'kiosk_enabled' => $dto->kioskEnabled,
            ]);

            // State is set by assignment, not mass fill: `status` is not fillable, so a screen
            // cannot post its way into a state the transition table would have refused.
            $driver->status = $dto->initialStatus;

            if (! $dto->preservesMaskedLicence()) {
                $driver->changeLicenceNo($dto->licenceNo);
            }

            $driver->save();

            return $driver;
        });
    }
}
