<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * BILL.category — what the supplier did for us, and the account its money debits.
 *
 * The account is the design's the design answer translated onto COA v1, the same way the fuel rows were
 * amended for Phase 3: the section drafted against a paper chart, and `5210`, `3100` and
 * `2211` do not exist in the seeded one. Labour and parts therefore land on `5300 Maintenance and
 * repairs` / `5310 Parts consumed` — and note that a parts bill for parts the shelf already
 * issued is *not* this door: 3e already moved `1190 → 5310` at the issue, and a bill must not
 * expense the same part a second time.
 *
 * `fuel` maps to `5100`, which requires truck **and** trip (the mask). That is deliberate and it
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
    case Opening = 'opening';

    /** The COA v1 account this category's money debits (restated for the seeded chart). */
    public function accountCode(): string
    {
        return match ($this) {
            self::Fuel => '5100',
            self::Parts => '5310',
            self::Labour => '5300',
            self::Insurance => '5500',
            self::Lease => '5500',
            self::Other => '5700',
            // The cutover intake's open bill. Its debit is `3010`, not an expense: the
            // supplier's work was expensed in the old system's year, and this system's job is to
            // carry the *obligation* forward — re-expensing it here would report the same diesel
            // twice, in two different years, and the P&L would tie to nobody.
            self::Opening => '3010',
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
            self::Opening => 'Opening balance',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
