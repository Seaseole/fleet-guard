<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Models\Route;
use Illuminate\Support\Facades\DB;

/**
 * Edits a corridor's master details (D1).
 *
 * The code is editable on purpose: it is how the lane is spoken about, and a typo caught a week
 * later should not force a retirement. Rate cards point at the row id, so a corrected code does not
 * detach a single price.
 */
final class UpdateRoute
{
    public function __invoke(Route $route, RouteDto $dto): Route
    {
        return DB::transaction(function () use ($route, $dto): Route {
            $route->fill([
                'code' => $dto->code,
                'origin' => $dto->origin,
                'destination' => $dto->destination,
                'origin_country_code' => $dto->originCountryCode,
                'destination_country_code' => $dto->destinationCountryCode,
                'distance_km' => $dto->distanceKm,
                'notes' => $dto->notes,
                'active' => $dto->active,
            ]);

            $route->save();

            return $route;
        });
    }
}
