<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Kernel\Commercial\PaymentTerms;
use InvalidArgumentException;

/**
 * The validated input behind the customer Actions (D1).
 *
 * The payment-terms format is checked **here**, once, because this is also the path the Phase 1
 * importer will use: a CSV row and a typed form must not be able to disagree about what a term is.
 * The vocabulary itself lives in {@see PaymentTerms} — shared with suppliers, so the two sides of
 * the ledger cannot drift apart on what `net_30` means.
 */
final readonly class CustomerDto
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $vatNumber = null,
        public string $paymentTerms = PaymentTerms::DEFAULT,
        public bool $active = true,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A customer needs a name — it is what an invoice is addressed to.');
        }

        if (! PaymentTerms::isValid($paymentTerms)) {
            throw new InvalidArgumentException("Payment terms '{$paymentTerms}' are not 'cash' or 'net_<days>'.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            name: trim((string) $input['name']),
            code: self::optionalString($input['code'] ?? null, 24, 'Customer code'),
            contactName: self::optionalString($input['contact_name'] ?? null, 80, 'Contact name'),
            contactEmail: self::optionalString($input['contact_email'] ?? null, 120, 'Contact email'),
            contactPhone: self::optionalString($input['contact_phone'] ?? null, 32, 'Contact phone'),
            vatNumber: self::optionalString($input['vat_number'] ?? null, 32, 'VAT number'),
            paymentTerms: PaymentTerms::normalise((string) ($input['payment_terms'] ?? PaymentTerms::DEFAULT)),
            active: (bool) ($input['active'] ?? true),
        );
    }

    private static function optionalString(mixed $value, int $max, string $label): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        // A silent truncation would change the reference a legacy export joins on, so an over-long
        // code is refused with the field named rather than cut to fit the column.
        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException("{$label} is longer than {$max} characters.");
        }

        return $trimmed;
    }
}
