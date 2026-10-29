<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Models\Route;
use Illuminate\Support\Facades\DB;

/**
 * Adds a corridor (§4.1, D1). The DTO has already refused unknown country codes and a zero
 * distance; the Action owns the transaction and the single write path the future API shares.
 */
final class CreateRoute
{
    public function __invoke(RouteDto $dto): Route
    {
        return DB::transaction(function () use ($dto): Route {
            $route = new Route([
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
