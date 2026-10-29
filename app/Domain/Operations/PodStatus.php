<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * §4.1 PROOF_OF_DELIVERY's own machine — separate from the trip's §10.2 states on purpose.
 *
 * The trip says where the truck is; the POD says whether the paperwork for one delivery is in and
 * accepted. Merging them is the failure §10.2 names: a driver's submit is a fact from the road, while
 * `delivered` is an office decision, and one column that carried both would let the kiosk complete a
 * delivery by itself.
 */
enum PodStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'not captured yet',
            self::Submitted => 'waiting for approval',
            self::Approved => 'approved',
            self::Rejected => 'rejected',
        };
    }

    /** @return 'neutral'|'warning'|'success'|'danger' */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function isCaptured(): bool
    {
        return $this !== self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
