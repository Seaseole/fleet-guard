<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * §4.3's work-order kinds: why the truck is in the workshop.
 *
 * The kind is not decoration — it is what the register groups by and what a maintenance-history read
 * asks for ("how often does this truck break down" is a different question from "how often is it
 * serviced"), and `capital` is the kind whose close-time decision starts from a presumption that the
 * work is an asset rather than an expense.
 */
enum WorkOrderKind: string
{
    case Service = 'service';
    case Repair = 'repair';
    case Breakdown = 'breakdown';
    case Tyre = 'tyre';
    case Inspection = 'inspection';
    case Capital = 'capital';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Repair => 'Repair',
            self::Breakdown => 'Breakdown',
            self::Tyre => 'Tyres',
            self::Inspection => 'Inspection',
            self::Capital => 'Capital work',
        };
    }

    /** A breakdown starts from a presumption of urgency, so the register can sort it first. */
    public function isUrgent(): bool
    {
        return $this === self::Breakdown;
    }
}
