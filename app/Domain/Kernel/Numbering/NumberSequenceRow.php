<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

/**
 * One `number_sequences` row, typed. The checker reads it straight from the query builder, so
 * this exists to keep `object` out of the analysis path — an untyped stdClass is where a typo in
 * a column name stops being an error.
 */
final readonly class NumberSequenceRow
{
    public function __construct(
        public int $id,
        public int $companyId,
        public string $scope,
        public string $periodKey,
        public int $nextValue,
        public string $format,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            companyId: (int) $row['company_id'],
            scope: (string) $row['scope'],
            periodKey: (string) $row['period_key'],
            nextValue: (int) $row['next_value'],
            format: (string) $row['format'],
        );
    }

    /** The count of documents this period implies were issued, i.e. values already spent. */
    public function issuedCount(): int
    {
        return max(0, $this->nextValue - 1);
    }
}
