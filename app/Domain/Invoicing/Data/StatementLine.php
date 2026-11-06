<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Invoicing\AgeingBucket;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\SettlementState;
use DateTimeImmutable;

/**
 * One invoice as a customer statement prints it.
 *
 * A statement is a *snapshot handed to the customer*, so every figure is copied out of the rows at the
 * moment the page renders — the number, the gross, what has been settled against it and what is left.
 * It deliberately carries no model: a queued email must not re-read a company-scoped invoice once the
 * tenant has been dropped (the defect), and the printed PDF must not change because somebody
 * allocated a payment while the mail sat in the queue.
 */
final readonly class StatementLine
{
    public function __construct(
        public string $invoiceId,
        public string $number,
        public DateTimeImmutable $issuedOn,
        public DateTimeImmutable $dueOn,
        public int $grossMinor,
        public int $balanceMinor,
        public InvoiceLifecycle $status,
        public SettlementState $settlement,
        public AgeingBucket $ageing,
    ) {}

    /** What has been settled against this invoice so far, by allocations and credit notes together. */
    public function settledMinor(): int
    {
        return $this->grossMinor - $this->balanceMinor;
    }
}
