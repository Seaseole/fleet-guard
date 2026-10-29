<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\Money;
use App\Domain\Operations\RouteRateType;
use InvalidArgumentException;

/**
 * The validated input behind the rate-card Actions (D1).
 *
 * The amount arrives as **minor units** in the card's currency, converted by the screen through
 * {@see Money::ofMajor()} — the same boundary §5.1 draws for every other typed amount. It is not
 * the company base currency: a cross-border lane is routinely agreed in ZAR or USD, and converting
 * the agreed figure into Pula would lose the number the customer actually signed.
 */
final readonly class RouteRateDto
{
    public function __construct(
        public int $routeId,
        public int $customerId,
        public RouteRateType $rateType,
        public int $rateMinorUnits,
        public string $currencyCode,
        public string $validFrom,
        public ?string $validTo = null,
    ) {
        if ($routeId <= 0 || $customerId <= 0) {
            throw new InvalidArgumentException('A rate needs a route and a customer.');
        }

        if ($rateMinorUnits < 0) {
            throw new InvalidArgumentException('A rate is a non-negative amount in minor units.');
        }

        if ($currencyCode === '') {
            throw new InvalidArgumentException('A rate needs a currency.');
        }

        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('A rate window cannot end before it starts.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            routeId: (int) $input['route_id'],
            customerId: (int) $input['customer_id'],
            rateType: RouteRateType::from((string) $input['rate_type']),
            rateMinorUnits: (int) $input['rate_minor_units'],
            currencyCode: Currency::normalise((string) $input['currency_code']),
            validFrom: (string) $input['valid_from'],
            validTo: is_string($input['valid_to'] ?? null) && $input['valid_to'] !== '' ? $input['valid_to'] : null,
        );
    }
}
