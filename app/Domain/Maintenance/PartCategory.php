<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The parts catalogue's typing vocabulary (PART `category`).
 *
 * The design leaves the column's values open, which is right for a store that buys whatever the trucks
 * need — but a free-text category would make "brakes", "Brake" and "brake pads" three filters.
 * These are the broad shelves a heavy-vehicle store is actually organised into; a new shelf is a
 * migration to the CHECK, deliberately, so the vocabulary cannot drift silently.
 */
enum PartCategory: string
{
    case Filters = 'filters';
    case Lubricants = 'lubricants';
    case Tyres = 'tyres';
    case Brakes = 'brakes';
    case Suspension = 'suspension';
    case Electrical = 'electrical';
    case Body = 'body';
    case Consumables = 'consumables';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Filters => 'Filters',
            self::Lubricants => 'Lubricants',
            self::Tyres => 'Tyres',
            self::Brakes => 'Brakes',
            self::Suspension => 'Suspension',
            self::Electrical => 'Electrical',
            self::Body => 'Body',
            self::Consumables => 'Consumables',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
