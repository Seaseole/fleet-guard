<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Models\Trailer;
use Illuminate\Support\Facades\DB;

/**
 * Edits a trailer's master details (§4.3, D1). Status moves through {@see ChangeTrailerStatus}.
 */
final class UpdateTrailer
{
    public function __invoke(Trailer $trailer, TrailerDto $dto): Trailer
    {
        return DB::transaction(function () use ($trailer, $dto): Trailer {
            $trailer->fill([
                'registration_no' => $dto->registrationNo,
                'fleet_no' => $dto->fleetNo,
                'vin' => $dto->vin,
                'type' => $dto->type,
                'capacity_tonnes_10_2' => $dto->capacityTonnes,
                'axle_config' => $dto->axleConfig,
                'odometer_km' => $dto->odometerKm,
                'active' => $dto->active,
            ]);

            $trailer->save();

            return $trailer;
        });
    }
}
