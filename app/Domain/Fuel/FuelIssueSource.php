<?php

declare(strict_types=1);

namespace App\Domain\Fuel;

/**
 * §4.3 FUEL_ISSUE `source` — where the fuel came from.
 *
 * `bulk_tank` is in the vocabulary from the start because the CHECK accepts it from the start; the
 * bulk tank itself (moving-average stock and reconciliation) is Phase 3b, and the Action refuses a
 * bulk issue until the tank that would own its cost exists. A vocabulary that grows later is a
 * migration; one that is complete now is a refusal with a sentence.
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
