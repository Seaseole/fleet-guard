<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Invoicing\PaymentMethod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What a person at the desk knows when the money lands (§4.2, §10.4).
 *
 * There is deliberately no invoice id here. A receipt is bankable the day it arrives and applied later,
 * which is the whole reason `payment_allocations` is a table: naming the invoice at the door would make an
 * on-account receipt impossible and force every overpayment to "adjust" something (§6.8).
 */
final readonly class PaymentDto
{
    public function __construct(
        public int $customerId,
        public int $amountMinor,
        public PaymentMethod $method,
        public DateTimeInterface|CarbonImmutable $receivedOn,
        public ?string $reference = null,
        public ?string $sourceUuid = null,
    ) {}
}
