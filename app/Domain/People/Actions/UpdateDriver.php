<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Data\DriverDto;
use App\Domain\People\Models\Driver;
use Illuminate\Support\Facades\DB;

/**
 * Edits a driver's papers and terms (§4.3, D1).
 *
 * Status is deliberately absent: it moves through {@see ChangeDriverStatus}, which writes the
 * §10.1 event and enforces the reason rule. An edit form that could post `status` would be the
 * second, silent way to suspend someone.
 */
final class UpdateDriver
{
    public function __invoke(Driver $driver, DriverDto $dto): Driver
    {
        return DB::transaction(function () use ($driver, $dto): Driver {
            $driver->fill([
                'licence_code' => $dto->licenceCode,
                'licence_expires_on' => $dto->licenceExpiresOn,
                'permit_expires_on' => $dto->permitExpiresOn,
                'default_allowance_rate_base' => $dto->allowanceRateBase,
                'fuel_efficiency_baseline_10_2' => $dto->fuelEfficiencyBaseline,
                'kiosk_enabled' => $dto->kioskEnabled,
            ]);

            if (! $dto->preservesMaskedLicence()) {
                $driver->changeLicenceNo($dto->licenceNo);
            }

            $driver->save();

            return $driver;
        });
    }
}
