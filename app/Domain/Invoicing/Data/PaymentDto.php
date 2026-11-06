<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a person at the desk knows when the money lands.
 *
 * There is deliberately no invoice id here. A receipt is bankable the day it arrives and applied later,
 * which is the whole reason `payment_allocations` is a table: naming the invoice at the door would make an
 * on-account receipt impossible and force every overpayment to "adjust" something.
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
        //, Opened by an empty currency means "base currency, rate 1" — the
        // pre-FX call sites stay untouched. A foreign receipt names its currency and the rate
        // the bank struck (or, with baseAmountMinor, the base the bank actually took, whose
        // implied rate becomes manual_override per ADR-0004 #6).
        public string $currencyCode = '',
        public ?string $fxRate = null,
        public string $fxRateSource = 'manual',
        public ?string $fxRateRef = null,
        public ?int $baseAmountMinor = null,
    ) {
        // A readonly promoted property cannot be re-written here, so the shape is checked rather
        // than repaired: {@see currency()} is the normalised view every reader must use, and a
        // lowercase code is refused instead of quietly mended — `payments_currency_upper` is a CHECK.
        if ($this->currencyCode !== '' && $this->currencyCode !== trim($this->currencyCode)) {
            throw new InvalidArgumentException('A receipt currency has no spaces around it.');
        }

        if ($this->currencyCode !== '' && preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new InvalidArgumentException('A receipt currency is the three-letter uppercase ISO code; "'.htmlentities($this->currencyCode, ENT_QUOTES).'" is not one.');
        }

        if ($this->fxRate !== null && $this->fxRate !== '' && (preg_match('/^\d{1,9}(\.\d{1,8})?$/', $this->fxRate) !== 1 || (float) $this->fxRate <= 0)) {
            throw new InvalidArgumentException('A receipt rate is a positive figure with at most 8 decimals — 1 foreign unit = X base.');
        }

        if ($this->baseAmountMinor !== null && $this->baseAmountMinor <= 0) {
            throw new InvalidArgumentException('The base a bank took is a positive figure; give the rate instead.');
        }
    }

    /** The currency as written on the paper, uppercased — the empty string means "the base currency". */
    public function currency(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    /** The typed rate, or null when the desk gave the base figure (or nothing at all). */
    public function rate(): ?string
    {
        return $this->fxRate === null || $this->fxRate === '' ? null : $this->fxRate;
    }

    public function isForeign(): bool
    {
        $base = CurrentCompany::model()->base_currency_code;

        return $this->currency() !== '' && $this->currency() !== $base;
    }
}
