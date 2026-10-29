<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

/**
 * Which side of an account increases it (§4.2 `normal_balance`, `gl_journal_lines.direction`).
 *
 * One enum serves both columns because they take the same two values and mean the same thing —
 * the account's normal side and the line's side. Keeping them separate types would let a
 * direction be passed where a normal balance belongs, which is the bug this exists to prevent.
 */
enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
