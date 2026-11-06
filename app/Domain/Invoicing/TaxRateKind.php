<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * Q4 — how a line is treated for VAT, which is not the same question as "what rate".
 *
 * `zero_rated` and `exempt` both carry 0%, and the migration's CHECK enforces that; they differ in
 * what the return does with the line, so collapsing them into one value would lose the only fact the
 * filing needs. An export leg (Botswana→Namibia/SA/Zambia) is the case the owner has not yet ruled
 * on, and Q4's default is that the *line type exists* so the answer is a data change rather than a
 * migration.
 */
enum TaxRateKind: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard rate',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'Exempt',
        };
    }

    /** The only kind that charges anything: everything else has no VAT to compute. */
    public function chargesTax(): bool
    {
        return $this === self::Standard;
    }
}
