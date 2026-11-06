<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use DateTimeImmutable;

/**
 * One customer's line in the receivables ageing report.
 *
 * The five money figures are the same five `AgeingBucket` derives per invoice, summed here so the report
 * is a query rather than a walk from the screen. `oldestDueOn` is carried because the number a credit
 * controller acts on is "how long has this been outstanding", not only "how much".
 */
final readonly class AgeingRow
{
    public function __construct(
        public int $customerId,
        public string $customerName,
        public ?string $customerCode,
        public int $currentMinor,
        public int $days1to30Minor,
        public int $days31to60Minor,
        public int $days61to90Minor,
        public int $over90Minor,
        public int $invoiceCount,
        public ?DateTimeImmutable $oldestDueOn,
    ) {}

    public function totalMinor(): int
    {
        return $this->currentMinor + $this->days1to30Minor + $this->days31to60Minor
            + $this->days61to90Minor + $this->over90Minor;
    }

    /** Everything that is past its due date — what a chasing list actually contains. */
    public function overdueMinor(): int
    {
        return $this->days1to30Minor + $this->days31to60Minor + $this->days61to90Minor + $this->over90Minor;
    }
}
