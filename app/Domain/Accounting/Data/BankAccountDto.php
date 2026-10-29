<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use InvalidArgumentException;

/**
 * The validated input behind the bank-account Actions (D1). A Livewire component builds this and
 * calls one Action; it never fills a model itself.
 *
 * `accountNumber` is the only plaintext that exists here, and only for the length of the request:
 * the Action turns it into the §9.5 trio and the DTO is discarded. It is not read back out of a
 * model, so a screen that re-submits an edit form cannot resend a masked value and store the mask
 * as the account number — hence {@see preservesMaskedNumber()}.
 */
final readonly class BankAccountDto
{
    public function __construct(
        public string $name,
        public string $institution,
        public string $currencyCode,
        public string $type,
        public ?string $accountNumber = null,
        public ?string $branch = null,
        public ?string $openedOn = null,
        public bool $active = true,
        public ?string $notes = null,
    ) {
        if (! in_array($this->type, BankAccountTypes::values(), true)) {
            throw new InvalidArgumentException("Account type '{$this->type}' is not one of ".implode(', ', BankAccountTypes::values()).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            name: (string) $input['name'],
            institution: (string) $input['institution'],
            currencyCode: (string) $input['currency_code'],
            type: (string) ($input['type'] ?? 'bank'),
            accountNumber: self::optionalString($input['account_number'] ?? null),
            branch: self::optionalString($input['branch'] ?? null),
            openedOn: self::optionalString($input['opened_on'] ?? null),
            active: (bool) ($input['active'] ?? true),
            notes: self::optionalString($input['notes'] ?? null),
        );
    }

    /**
     * A form that shows `••••4521` and posts it back unchanged must not re-encrypt the mask as if it
     * were a number. The UI sends nothing for the field unless a human actually typed one.
     */
    public function preservesMaskedNumber(): bool
    {
        return $this->accountNumber === null || str_contains($this->accountNumber, '•');
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
