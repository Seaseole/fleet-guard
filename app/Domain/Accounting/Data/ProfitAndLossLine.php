<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One revenue or expense account over a P&L window, in base minor units.
 *
 * The amount is stated on the account's own side — revenue positive as money earned, expense
 * positive as money spent — so the screen adds a column up without re-deciding which direction
 * a code runs. A negative figure means the window's net movement ran the other way (a credit on
 * 5100 is a refund), and the statement keeps that visible rather than clamping it.
 */
final class ProfitAndLossLine
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $amountMinor,
    ) {}
}
