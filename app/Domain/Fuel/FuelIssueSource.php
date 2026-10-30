<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * §4.3 FUEL_ISSUE `source` — where the fuel came from.
 *
 * `bulk_tank` is a real source from slice 3b: the issue names the yard tank it drew from, its cost is
 * the tank's moving average at that moment, and the journal credits `1180 Bulk fuel stock` rather
 * than a cash or payable account. `kiosk` is still only vocabulary — the driver's screen arrives with
 * slice 3d, and the Action refuses it by name until then.
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
