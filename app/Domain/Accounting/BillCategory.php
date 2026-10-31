<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * §4.2 BILL.category — what the supplier did for us, and the account its money debits.
 *
 * The account is the design's §6.8 answer translated onto COA v1, the same way the fuel rows were
 * amended for Phase 3 (§20.25): the section drafted against a paper chart, and `5210`, `3100` and
 * `2211` do not exist in the seeded one. Labour and parts therefore land on `5300 Maintenance and
 * repairs` / `5310 Parts consumed` — and note that a parts bill for parts the shelf already
 * issued is *not* this door: 3e already moved `1190 → 5310` at the issue, and a bill must not
 * expense the same part a second time.
 *
 * `fuel` maps to `5100`, which requires truck **and** trip (§6.1's mask). That is deliberate and it
 * bites: a garage's pump bill must name the trip its diesel burned, because fuel that loses its
 * leg quietly lands in overheads and per-trip margin stops being true. A station bill that cannot
 * name one is the slip/issue path (Phase 3), not a bill line.
 */
enum BillCategory: string
{
    case Fuel = 'fuel';
    case Parts = 'parts';
    case Labour = 'labour';
    case Insurance = 'insurance';
    case Lease = 'lease';
    case Other = 'other';

    /** The COA v1 account this category's money debits (§6.8, restated for the seeded chart). */
    public function accountCode(): string
    {
        return match ($this) {
            self::Fuel => '5100',
            self::Parts => '5310',
            self::Labour => '5300',
            self::Insurance => '5500',
            self::Lease => '5500',
            self::Other => '5700',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Parts => 'Parts',
            self::Labour => 'Labour',
            self::Insurance => 'Insurance',
            self::Lease => 'Lease',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
