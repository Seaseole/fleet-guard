<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use DateTimeImmutable;

/**
 * The customer statement's whole content, gathered before anything is rendered.
 *
 * The window is the one the caller asked for; the outstanding figure is *not* windowed, because a
 * statement that hides an old debt is the document a customer quotes back at you. `unappliedMinor` is
 * money sitting on account (the `2150`), which is shown separately so the customer can see that a
 * receipt has been banked even though no invoice was named for it.
 */
final readonly class CustomerStatement
{
    /**
     * @param  list<StatementLine>  $lines
     * @param  list<array{number: string, receivedOn: DateTimeImmutable, method: string, amountMinor: int, unappliedMinor: int}>  $receipts
     */
    public function __construct(
        public int $customerId,
        public string $customerName,
        public ?string $customerCode,
        public ?string $customerVatNumber,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $lines,
        public array $receipts,
        public int $invoicedMinor,
        public int $receivedMinor,
        public int $outstandingMinor,
        public int $unappliedMinor,
        public string $currencyCode,
    ) {}

    /** @return list<array{number: string, receivedOn: DateTimeImmutable, method: string, amountMinor: int, unappliedMinor: int}> */
    public function receipts(): array
    {
        return $this->receipts;
    }
}
