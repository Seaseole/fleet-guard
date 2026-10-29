<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\SupplierCategory;
use App\Domain\Kernel\Commercial\PaymentTerms;
use InvalidArgumentException;

/**
 * The validated input behind the supplier Actions (D1).
 *
 * The category is accepted as an enum or its string value so a CSV importer and a form can both
 * call the same constructor without one of them having to know how the other spells it.
 */
final readonly class SupplierDto
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public SupplierCategory $category = SupplierCategory::Other,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $vatNumber = null,
        public string $paymentTerms = PaymentTerms::DEFAULT,
        public bool $active = true,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A supplier needs a name — it is what a bill is addressed to.');
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
            code: self::optionalString($input['code'] ?? null, 24, 'Supplier code'),
            category: SupplierCategory::from((string) ($input['category'] ?? SupplierCategory::Other->value)),
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

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException("{$label} is longer than {$max} characters.");
        }

        return $trimmed;
    }
}
