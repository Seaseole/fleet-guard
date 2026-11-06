<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * BORDER_COST's `kind` — what the gate charged for.
 *
 * The list is the DESIGN sketch's own; `other` is last because a charge that does not fit is a
 * question for the accountant, not a bucket to hide in. Phase 6 reads these to split recoverable
 * input tax by kind.
 */
enum BorderCostKind: string
{
    case Duty = 'duty';
    case ClearingAgent = 'clearing_agent';
    case Permit = 'permit';
    case RoadUserFee = 'road_user_fee';
    case TransitBond = 'transit_bond';
    case Scanning = 'scanning';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Duty => 'Duty',
            self::ClearingAgent => 'Clearing agent',
            self::Permit => 'Permit',
            self::RoadUserFee => 'Road-user fee',
            self::TransitBond => 'Transit bond',
            self::Scanning => 'Scanning',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
