<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * The settlement sheet's own machine (the `TripSettled`, the `completed → closed` witness).
 *
 * `open → settled` posts the journal and is the accountant's signature; `open|settled → void`
 * discards or corrects — and a *settled* void first reverses its entry through the Kernel's
 * mirror door, because a signed settlement is corrected the way a posted journal is:
 * by a fact in the opposite direction, never by forgetting.
 */
enum TripSettlementStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Settled => 'Settled',
            self::Void => 'Void',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Open;
    }
}
