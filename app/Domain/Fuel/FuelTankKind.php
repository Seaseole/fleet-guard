<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * §4.3 FUEL_TANK `kind` — which tank on the truck an issue fills.
 *
 * Two values, exactly the spec's `main|auxiliary`. The truck row already states
 * `main_tank_litres`/`aux_tank_litres`; this enum names the row an issue writes, not the capacity.
 */
enum FuelTankKind: string
{
    case Main = 'main';
    case Auxiliary = 'auxiliary';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main tank',
            self::Auxiliary => 'Auxiliary tank',
        };
    }
}
