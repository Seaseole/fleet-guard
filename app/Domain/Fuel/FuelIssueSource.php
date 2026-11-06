<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

use App\Domain\Operations\Kiosk\KioskAuthority;

/**
 * FUEL_ISSUE `source` — where the fuel came from.
 *
 * `bulk_tank` is a real source from slice 3b: the issue names the yard tank it drew from, its cost is
 * the tank's moving average at that moment, and the journal credits `1180 Bulk fuel stock` rather
 * than a cash or payable account. `kiosk` is a real source from slice 3g: the driver's phone records
 * the slip they paid for and the issue that drains it in one act, and only a verified
 * {@see KioskAuthority} may write it.
 */
enum FuelIssueSource: string
{
    case PumpCard = 'pump_card';
    case BulkTank = 'bulk_tank';
    case Advance = 'advance';
    case Kiosk = 'kiosk';

    public function label(): string
    {
        return match ($this) {
            self::PumpCard => 'Pump card',
            self::BulkTank => 'Bulk tank',
            self::Advance => 'Cash advance',
            self::Kiosk => 'Kiosk',
        };
    }

    /** A purchase slip named on the issue is what fixes its cost; a bulk issue has no slip. */
    public function requiresPurchase(): bool
    {
        return $this !== self::BulkTank;
    }
}
