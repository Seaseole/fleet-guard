<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * LOAN.frequency. The two words a Botswana lender actually quotes, turned into the
 * arithmetic the schedule builder needs: periods per year and the step between due dates.
 */
enum LoanFrequency: string
{
    case Monthly = 'monthly';
    case Fortnightly = 'fortnightly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Fortnightly => 'Fortnightly',
        };
    }

    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Fortnightly => 26,
        };
    }

    public function stepDays(): int
    {
        return match ($this) {
            self::Monthly => 0,
            self::Fortnightly => 14,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
