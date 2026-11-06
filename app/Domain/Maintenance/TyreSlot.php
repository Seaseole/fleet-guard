<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * TYRE_POSITION `position` — six wheels, as the CHECK in the migration allows.
 *
 * One rear axle carrying dual wheels on each side is the common Botswana rigid truck, so the slots
 * are counted per side rather than by axle: `RL1`/`RL2` are the two tyres on the left rear, inner and
 * outer. A truck with a second rear axle would need new slots, which is a migration to the CHECK and
 * a deliberate act, not a free-text position that a typo can invent.
 */
enum TyreSlot: string
{
    case FrontLeft = 'FL';
    case FrontRight = 'FR';
    case RearLeftInner = 'RL1';
    case RearLeftOuter = 'RL2';
    case RearRightInner = 'RR1';
    case RearRightOuter = 'RR2';

    public function label(): string
    {
        return match ($this) {
            self::FrontLeft => 'Front left',
            self::FrontRight => 'Front right',
            self::RearLeftInner => 'Rear left inner',
            self::RearLeftOuter => 'Rear left outer',
            self::RearRightInner => 'Rear right inner',
            self::RearRightOuter => 'Rear right outer',
        };
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $slot) {
            $options[$slot->value] = $slot->label();
        }

        return $options;
    }
}
