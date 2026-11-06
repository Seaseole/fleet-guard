<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Operations\RouteRateType;
use InvalidArgumentException;

/**
 * The price offered on a job (JOB: rate type, quoted amount, currency, base amount).
 *
 * The amount arrives as the **typed string** a person put in the field, never a float — the rule
 * that a float has already lost the digits, enforced once at the screen boundary by `Money::ofMajor()`.
 *
 * A base-currency quote needs no rate: The `base_amount == round(amount × fx_rate)` with
 * `fx_rate = 1`. Anything else must say which rate converted it and on which date, because a quote
 * re-priced at today's rate would quietly rewrite what the customer was offered.
 *
 * @param  array<string, mixed>|null  $terms
 */
final readonly class JobQuoteDto
{
    /**
     * @param  string  $amountMajor  the quoted figure in major units, e.g. "81 250.00"
     * @param  string|null  $rate  the 1-foreign-to-base rate; required when the currency is not the base
     * @param  array<string, mixed>|null  $terms
     */
    public function __construct(
        public RouteRateType $rateType,
        public string $amountMajor,
        public string $currencyCode,
        public ?string $rate = null,
        public ?string $rateDate = null,
        public ?string $requiredLoadOn = null,
        public ?string $requiredDeliveryOn = null,
        public ?array $terms = null,
    ) {
        if (trim($amountMajor) === '') {
            throw new InvalidArgumentException('A quote needs an amount — an unpriced job cannot be accepted.');
        }

        if (! preg_match('/\A[0-9][0-9 ,.]*\z/', trim($amountMajor))) {
            throw new InvalidArgumentException('The quoted amount is not a number I can read.');
        }

        $currency = strtoupper(trim($this->currencyCode));

        if (! preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new InvalidArgumentException(sprintf('Currency "%s" is not a three-letter ISO code.', $this->currencyCode));
        }

        if ($rate !== null && $rateDate === null) {
            throw new InvalidArgumentException('A stated rate must name the date it was struck.');
        }
    }

    public function currency(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $rateType = RouteRateType::tryFrom((string) ($input['rate_type'] ?? ''))
            ?? throw new InvalidArgumentException('A quote must name a rate basis: '.implode(', ', RouteRateType::values()).'.');

        return new self(
            rateType: $rateType,
            amountMajor: (string) ($input['amount'] ?? ''),
            currencyCode: (string) ($input['currency_code'] ?? ''),
            rate: self::text($input['fx_rate'] ?? null),
            rateDate: self::text($input['fx_rate_date'] ?? null),
            requiredLoadOn: self::text($input['required_load_on'] ?? null),
            requiredDeliveryOn: self::text($input['required_delivery_on'] ?? null),
            terms: is_array($input['terms'] ?? null) ? $input['terms'] : null,
        );
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
