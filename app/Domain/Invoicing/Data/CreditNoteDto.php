<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

/**
 * What a credit note says: why the invoice was wrong, and how much of which billed lines comes off.
 *
 * `lines` is keyed by **invoice line id** rather than expressed as one total, because the revenue accounts
 * require dimensions and a credit has to take the money back from the account and the leg it was credited to.
 * A single lump credit would post to `4900` and leave the freight leg still reading revenue it no longer has.
 *
 * Amounts are net minor units in the invoice's currency; VAT is derived per line from the rate that line was
 * billed under, never from today's rate.
 */
final readonly class CreditNoteDto
{
    /**
     * @param  array<string, int>  $lines  invoice line id => net minor units to credit
     */
    public function __construct(
        public string $reason,
        public array $lines,
        public ?string $narrative = null,
    ) {}
}
