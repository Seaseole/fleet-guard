<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Which way the truck went through the gate (§4.4 `direction`).
 *
 * One value per crossing rather than a boolean: a corridor trip enters one country and exits it, and
 * the pair is what lets Phase 6 count days inside a country without reading the route.
 */
enum BorderCrossingDirection: string
{
    case Entry = 'entry';
    case Exit = 'exit';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'entry',
            self::Exit => 'exit',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
