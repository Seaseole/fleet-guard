<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * A driver's four states (§4.3, §10.1).
 *
 * `standby` is not a synonym for `active`: a standby driver is on the books and licensed but not
 * currently rostered, and dispatch may still use one. `suspended` and `left` stop dispatch, and the
 * move into either carries a mandatory reason.
 */
enum DriverStatus: string
{
    case Active = 'active';
    case Standby = 'standby';
    case Suspended = 'suspended';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Standby => 'Standby',
            self::Suspended => 'Suspended',
            self::Left => 'Left the company',
        };
    }

    /** The `ui/badge` tone for the register (DESIGN-SYSTEM.md §1.5). */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Standby => 'neutral',
            self::Suspended => 'warning',
            self::Left => 'danger',
        };
    }

    /** May appear on a trip as the driver on the given date (papers are checked separately). */
    public function mayBeDispatched(): bool
    {
        return $this === self::Active || $this === self::Standby;
    }
}
