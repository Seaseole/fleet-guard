<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One postable balance-sheet account at an as-at date, stated on its section's natural side:
 * an asset as money held (debit-positive), a liability or equity account as money owed to
 * someone (credit-positive). A contra that nets the wrong way (a bank in overdraft) keeps its
 * negative and stays visible — the same rule the P&L line states.
 */
final class BalanceSheetLine
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $amountMinor,
    ) {}
}
