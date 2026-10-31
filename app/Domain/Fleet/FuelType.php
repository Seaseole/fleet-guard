<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use InvalidArgumentException;

/**
 * The fuel a truck's tank takes (§4.3's `TRUCK.fuel_type`).
 *
 * A closed list rather than free text because the column is a fact about the engine, not a note:
 * "Diesel" and "DIESEL " were two different answers to the same question, and a report grouped by
 * them would be wrong without ever saying so. Petrol and paraffin are here because a fleet can own
 * a bakkie or a yard generator, and the register records the vehicle, not only the heavies.
 */
enum FuelType: string
{
    case Diesel = 'diesel';
    case Petrol = 'petrol';
    case Paraffin = 'paraffin';

    public function label(): string
    {
        return match ($this) {
            self::Diesel => 'Diesel',
            self::Petrol => 'Petrol',
            self::Paraffin => 'Paraffin',
        };
    }

    /**
     * Case and surrounding spaces are the typist's, not the register's: " Diesel " is diesel. A
     * value outside the list is refused with the list in the message, because the useful thing to
     * tell an importer is what would have been accepted.
     */
    public static function fromKey(string $key): self
    {
        return self::tryFrom(mb_strtolower(trim($key))) ?? throw new InvalidArgumentException(sprintf(
            'Fuel type must be one of %s — "%s" is not a fuel this register records.',
            implode(', ', self::values()),
            trim($key),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
