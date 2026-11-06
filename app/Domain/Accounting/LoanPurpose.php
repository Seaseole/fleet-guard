<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/** LOAN.purpose — the three things a corridor fleet finances. */
enum LoanPurpose: string
{
    case Vehicle = 'vehicle';
    case Equipment = 'equipment';
    case WorkingCapital = 'working_capital';

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Vehicle',
            self::Equipment => 'Equipment',
            self::WorkingCapital => 'Working capital',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
