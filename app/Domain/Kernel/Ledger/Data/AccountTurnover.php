<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

/**
 * One account's summed lines, on one side, as the rollups read them.
 *
 * A read-only projection for callers that must total the ledger without touching its models (D3).
 * The amount is a magnitude in base minor units; the side says which way it moved, which is the
 * distinction a single net figure would erase — a credit on an expense account is a refund the
 * rollup has to refuse, not a smaller cost.
 */
final class AccountTurnover
{
    public function __construct(
        public readonly string $accountCode,
        public readonly Side $side,
        public readonly int $amountBase,
    ) {}

    /** The amount as it moves the account: positive on its normal (debit) side, negative on credit. */
    public function signedDebit(): int
    {
        return $this->side === Side::Debit ? $this->amountBase : -$this->amountBase;
    }
}
