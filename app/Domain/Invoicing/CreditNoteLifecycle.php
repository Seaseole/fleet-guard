<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * §10.5 — the credit note's lifecycle: `draft → issued → applied → settled`, plus `draft → cancelled`.
 *
 * `issued → applied` happens **only when the note's whole balance has been allocated** — which is exactly
 * what §10.5's "partial application keeps `issued` with a derived remaining amount" means. A clerk applying
 * half a credit leaves the note issued and the invoice partly paid; the second application flips it. That is
 * why the balance, not the existence of an allocation row, decides the state.
 *
 * `settled` is unreachable in this phase by design, not by omission: a note is settled when there is nothing
 * further it can do to the account, which for a credit that exceeded the receivable means the refund was
 * actually paid — §15 phase 5's cash question. The enum and the CHECK carry it now so the state machine does
 * not change under the accountant later, the same way §10.3 carries `invoiced` and §15.2 keeps Phase 6's two
 * states named but unreachable.
 *
 * A credit note never rewinds an invoice (§10.4): the invoice stays issued and its derived settlement state
 * moves toward `refunded` as this note is allocated. The note has its own gap-free `credit_note` scope and
 * its own posting (§6.8), which is what makes the correction auditable instead of a silent edit.
 */
enum CreditNoteLifecycle: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Applied = 'applied';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Applied => 'Applied',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Only an unissued note may be edited or cancelled; issuing is the fiscal act. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** States where the note can still be applied against something. */
    public function isOpenItem(): bool
    {
        return $this === self::Issued;
    }
}
