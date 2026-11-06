<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * Axis two — whether the invoice has been paid, computed from rows, never stored.
 *
 * This enum is a **read model**: `Invoice::settlementState()` derives it from the balance the allocation
 * rows maintain, and the `invoices:recompute-balances` command is the tie-out that rebuilds that balance
 * from those same rows. Nothing writes a column holding this value, which is the whole point — a stored
 * `paid` flag is a second truth that drifts the first time an allocation is reversed.
 *
 * `written_off` is deliberately unreachable in Phase 4: it needs phase 5's bad-debt posting and a
 * permission that does not exist yet. Carrying the case now, with the same hand as the unreachable job
 * badges, means the vocabulary is settled and the state machine does not change under the accountant later.
 */
enum SettlementState: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
            self::WrittenOff => 'Written off',
        };
    }

    /** The states that still represent money owed to the fleet. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Open, self::PartiallyPaid], true);
    }

    /**
     * From the balance the projection carries. A negative balance is not "overpaid" — it is a credit the
     * customer holds, which is what `refunded` means on this axis — and anything between zero and the full
     * amount is `partially_paid`, which is why the amount is an argument rather than a second query.
     */
    public static function fromBalance(int $balanceMinor, int $amountMinor): self
    {
        return match (true) {
            $balanceMinor < 0 => self::Refunded,
            $balanceMinor === 0 => self::Paid,
            $balanceMinor >= $amountMinor => self::Open,
            default => self::PartiallyPaid,
        };
    }
}
