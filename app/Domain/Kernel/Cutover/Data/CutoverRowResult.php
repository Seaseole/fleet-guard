<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Data;

/**
 * One parsed upload line and every reason it cannot be taken. A row with an error is stored, not
 * discarded: the error report is a deliverable and the accountant corrects against its line number.
 *
 * `values` is the dataset's normalised row, keyed by the file's own column names. The trial balance
 * carries `debit_minor`, `credit_minor`, `account_code`, `account_name`, `description` and
 * `gl_account_id` there; master data carries its typed fields, with money in minor units and dates
 * as ISO strings. A stored secret is present only as `*_encrypted` plus its mask — never in clear.
 */
final class CutoverRowResult
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $lineNumber,
        public readonly array $values = [],
        public readonly array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}
