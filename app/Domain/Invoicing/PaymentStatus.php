<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * The PAYMENT machine: a receipt is `recorded`, and the only way out is `void` with a reason.
 *
 * There is no `draft`: money that has arrived is a bank fact, which is why the "number at issue" rule
 * does not apply here — the receipt number is allocated at creation and the migration's store entry
 * declares no draft status. There is no `applied` either: an allocation is a **separate row**, so a
 * payment's applied-ness is the sum of its allocations, never a state this row adopts.
 */
enum PaymentStatus: string
{
    case Recorded = 'recorded';
    case Void = 'void';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Void => 'Void',
        };
    }
}
