<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * §10.4 axis one — the invoice's lifecycle, and the only invoice axis that is stored.
 *
 * Three values, and the temptation to add a fourth is the bug the section warns about. `paid`,
 * `partially_paid` and `refunded` are **derived from the allocation rows**, and ageing comes from `due_on`
 * against today; a single conflated status carrying both `void` and `paid` means payment allocation
 * mutates lifecycle state, and gap-free numbering plus audit semantics die quietly in that coupling.
 *
 * `draft → issued` is the only transition that allocates a number and posts revenue (§7.4), which is why
 * the enum has no "pending approval" step: an invoice is either a draft nobody has committed to or a
 * fiscal document, and a middle state would let a number be spent on a document nobody signed.
 */
enum InvoiceLifecycle: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';

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
            self::Void => 'Void',
        };
    }

    /** Only a draft may be edited or deleted; an issued invoice is corrected by a credit note. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** The states that count as receivable money — a void invoice is owed nothing. */
    public function isOpenItem(): bool
    {
        return $this === self::Issued;
    }
}
