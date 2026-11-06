<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * INSPECTION `result` — what the check found, in three words.
 *
 * The enum carries the rule the JSON cannot: a `pass` means every item was answered and every answer
 * was yes, and a `fail` means an item was answered no. Without that, "pass" becomes a button a hurried
 * driver presses, and the register records a safety check that never looked at the brakes.
 *
 * `advisory` is the middle the register needs for a vehicle that may go but has a note — a chipped
 * windscreen, a fraying strap — so an inspector is not forced to choose between a lie and grounding
 * a roadworthy truck.
 */
enum InspectionResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Advisory = 'advisory';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Passed',
            self::Fail => 'Failed',
            self::Advisory => 'Passed with advisory',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Fail => 'danger',
            self::Advisory => 'warning',
        };
    }

    /** A fail or an advisory must say what was seen; a pass must say nothing was. */
    public function requiresDefect(): bool
    {
        return $this !== self::Pass;
    }

    /** A fail must have at least one "no" on the checklist — a defect line alone is not a verdict. */
    public function requiresFailedItem(): bool
    {
        return $this === self::Fail;
    }
}
