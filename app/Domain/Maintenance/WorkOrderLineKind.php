<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * What a work-order line is (§4.3).
 *
 * The four kinds are the four ways a workshop spends money, and they are kept apart because they
 * post in different places at different times (§10.6): a `part` line becomes a stock issue in phase
 * 3e, `labour` waits for a timesheet, `external` waits for the supplier's bill, and `consumable` is
 * the oil that is neither stocked nor invoiced. The work order's totals carry the same split, so the
 * close-time capitalise decision can be taken line by line.
 */
enum WorkOrderLineKind: string
{
    case Labour = 'labour';
    case Part = 'part';
    case External = 'external';
    case Consumable = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::Labour => 'Labour',
            self::Part => 'Part',
            self::External => 'External work',
            self::Consumable => 'Consumable',
        };
    }

    /** Which of the work order's three frozen totals this line feeds. */
    public function totalBucket(): string
    {
        return match ($this) {
            self::Labour => 'labour',
            self::Part, self::Consumable => 'parts',
            self::External => 'external',
        };
    }

    /** The unit the quantity is counted in, for the form's suffix. */
    public function unit(): string
    {
        return match ($this) {
            self::Labour => 'hours',
            default => 'units',
        };
    }

    /** A part line is the one kind that may name a catalogue row and become a stock issue. */
    public function mayNamePart(): bool
    {
        return $this === self::Part;
    }
}
