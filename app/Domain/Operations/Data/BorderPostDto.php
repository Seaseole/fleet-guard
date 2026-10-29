<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Operations\Actions\CreateBorderPost;
use InvalidArgumentException;

/**
 * The validated input behind {@see CreateBorderPost} (D1).
 *
 * The country is checked against the corridor set in `config/fleet.php`, for the same reason a route's
 * endpoints are: the fleet drives into a named set of countries, and a gate in a country nobody drives
 * to is a typo. Coordinates are optional but, when given, are checked for the hemisphere — a
 * swapped sign puts the gate in the Atlantic and makes Phase 7's geofence fire on the wrong side.
 */
final readonly class BorderPostDto
{
    public function __construct(
        public string $name,
        public string $countryCode,
        public ?string $lat = null,
        public ?string $lng = null,
        public ?string $openFrom = null,
        public ?string $openTo = null,
        public ?string $notes = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A border post needs a name — it is what the crossing and the register both print.');
        }

        /** @var array<string, string> $corridor */
        $corridor = (array) config('fleet.commercial.countries', []);

        if (! array_key_exists($countryCode, $corridor)) {
            throw new InvalidArgumentException("Country '{$countryCode}' is not a corridor country — add it to config/fleet.php before registering a gate there.");
        }

        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $field => [$min, $max]) {
            $value = $field === 'lat' ? $lat : $lng;

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                throw new InvalidArgumentException("A border post's {$field} must be a number between {$min} and {$max}.");
            }
        }

        foreach (['openFrom' => $openFrom, 'openTo' => $openTo] as $field => $value) {
            if ($value !== null && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
                throw new InvalidArgumentException("Opening hours must be a time like 06:00, not '{$value}'.");
            }
        }
    }
}
