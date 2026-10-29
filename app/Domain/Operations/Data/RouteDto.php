<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use InvalidArgumentException;

/**
 * The validated input behind the route Actions (D1).
 *
 * Country codes are checked against the corridor set in `config/fleet.php` rather than against ISO
 * 3166: the fleet drives into a named set of countries, and a route to a country nobody has driven
 * to is a typo far more often than it is a new lane. Adding a lane is a config line.
 *
 * `distanceKm` arrives as the typed decimal string and is checked here, because a route at 0 km
 * would price a per-km rate at zero while looking perfectly ordinary on the register.
 */
final readonly class RouteDto
{
    public function __construct(
        public string $code,
        public string $origin,
        public string $destination,
        public string $originCountryCode,
        public string $destinationCountryCode,
        public string $distanceKm,
        public ?string $notes = null,
        public bool $active = true,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('A route needs a code — it is the reference dispatch and the rate card use.');
        }

        if (trim($origin) === '' || trim($destination) === '') {
            throw new InvalidArgumentException('A route needs both endpoints named.');
        }

        /** @var array<string, string> $corridor */
        $corridor = (array) config('fleet.commercial.countries', []);

        foreach ([$originCountryCode, $destinationCountryCode] as $country) {
            if (! array_key_exists($country, $corridor)) {
                throw new InvalidArgumentException("Country '{$country}' is not a corridor country — add it to config/fleet.php before routing there.");
            }
        }

        if (! is_numeric($distanceKm) || (float) $distanceKm <= 0) {
            throw new InvalidArgumentException('A route distance must be a positive number of kilometres.');
        }

        if ((float) $distanceKm >= 1_000_000) {
            throw new InvalidArgumentException('A route distance that large is a typo — check the unit is kilometres.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            code: strtoupper(trim((string) $input['code'])),
            origin: trim((string) $input['origin']),
            destination: trim((string) $input['destination']),
            originCountryCode: strtoupper(trim((string) $input['origin_country_code'])),
            destinationCountryCode: strtoupper(trim((string) $input['destination_country_code'])),
            distanceKm: trim((string) $input['distance_km']),
            notes: is_string($input['notes'] ?? null) && trim($input['notes']) !== '' ? trim($input['notes']) : null,
            active: (bool) ($input['active'] ?? true),
        );
    }
}
