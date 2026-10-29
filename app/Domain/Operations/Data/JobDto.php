<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use InvalidArgumentException;

/**
 * The enquiry a job is created from (§4.1 JOB).
 *
 * Only the customer, the corridor and the promise dates are required: a quote is a separate decision
 * ({@see JobQuoteDto}) so the enquiry can be booked by a dispatcher before anyone has priced it.
 *
 * @param  array<string, mixed>|null  $terms
 */
final readonly class JobDto
{
    /** @param array<string, mixed>|null $terms */
    public function __construct(
        public int $customerId,
        public int $routeId,
        public ?string $commodity = null,
        public ?float $tonnes = null,
        public ?string $requiredLoadOn = null,
        public ?string $requiredDeliveryOn = null,
        public ?array $terms = null,
        public ?string $notes = null,
    ) {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('A job needs a customer — it is a commercial promise, not a trip.');
        }

        if ($routeId <= 0) {
            throw new InvalidArgumentException('A job needs a route: the corridor decides both the rate and the crossing plan.');
        }

        if ($tonnes !== null && $tonnes <= 0) {
            throw new InvalidArgumentException('Tonnage must be a positive number when it is stated.');
        }

        if ($this->requiredLoadOn !== null && $this->requiredDeliveryOn !== null
            && $this->requiredDeliveryOn < $this->requiredLoadOn) {
            throw new InvalidArgumentException('The delivery promise cannot fall before the loading promise.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            customerId: (int) ($input['customer_id'] ?? 0),
            routeId: (int) ($input['route_id'] ?? 0),
            commodity: self::text($input['commodity'] ?? null),
            tonnes: self::number($input['tonnes'] ?? null),
            requiredLoadOn: self::text($input['required_load_on'] ?? null),
            requiredDeliveryOn: self::text($input['required_delivery_on'] ?? null),
            terms: is_array($input['terms'] ?? null) ? $input['terms'] : null,
            notes: self::text($input['notes'] ?? null),
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

    private static function number(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
