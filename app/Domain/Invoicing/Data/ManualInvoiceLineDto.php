<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Invoicing\InvoiceLineKind;
use InvalidArgumentException;

/**
 * One hand-typed line of a manual invoice (the `manual` line kind).
 *
 * There is no quantity and no unit price here on purpose: a chargeable already carries the agreed
 * amount for a delivered quantity, and a manual line has no delivery behind it. Asking the desk for
 * "27.55 × 350.00" would invent a rate the paper never stated — the product rule stays with the
 * billing run, and a manual line is the one case where a single amount is the honest shape.
 */
final readonly class ManualInvoiceLineDto
{
    /**
     * The kinds an office may raise by hand. Every one of them is credited to `4900`, whose only
     * required dimension is the customer — a manual line cannot name a truck or a trip it
     * did not deliver, so a kind that demanded one could never post.
     */
    public const KINDS = [InvoiceLineKind::Manual, InvoiceLineKind::Other, InvoiceLineKind::Accessories];

    public function __construct(
        public string $description,
        public int $amountMinor,
        public InvoiceLineKind $kind = InvoiceLineKind::Manual,
    ) {
        if (! in_array($this->kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf(
                'A hand-raised line cannot be "%s": freight, detention, customs and fuel lines are billed from a '
                .'delivery, and their accounts demand a trip or a truck this document does not have.',
                $this->kind->label(),
            ));
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            description: trim((string) ($input['description'] ?? '')),
            amountMinor: (int) ($input['amount_minor'] ?? 0),
            kind: InvoiceLineKind::tryFrom((string) ($input['kind'] ?? InvoiceLineKind::Manual->value))
                ?? InvoiceLineKind::Manual,
        );
    }
}
