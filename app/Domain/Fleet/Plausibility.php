<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * Whether a reading can be trusted.
 *
 * `suspect` is the automatic answer when a reading runs backwards against the truck's recorded
 * odometer; `disputed` is a human decision. Neither is an edit — the reading stays as measured and
 * the truck keeps the higher number, because "the odometer went backwards" is the incident, not the
 * typo.
 */
enum Plausibility: string
{
    case Ok = 'ok';
    case Suspect = 'suspect';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Plausible',
            self::Suspect => 'Suspect',
            self::Disputed => 'Disputed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Suspect => 'warning',
            self::Disputed => 'danger',
        };
    }
}
