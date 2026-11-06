<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

use App\Domain\Kernel\Ledger\Exceptions\UnknownDimension;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;

/**
 * One line of a proposed posting. Immutable value object: the ledger's inputs are DTOs,
 * and the only door that turns them into rows is {@see LedgerService}.
 *
 * `$base` is the authoritative amount (ADR-0004 #3) and is always in the company base currency;
 * `$foreign` is the amount as the invoice, bill or fuel slip actually stated it, in the entry's
 * input currency. A line with only `$foreign` is not accepted — the poster converts, because
 * conversion is where the rounding rule lives.
 */
final class LineDto
{
    /**
     * @param  array<string, scalar|null>  $dimensions  keyed by the column name: truck_id, trip_id, …
     * @param  array<string, scalar|null>  $dimensionExtra  the long tail (permit_id, tyre_id, route_id)
     */
    public function __construct(
        public readonly int $accountId,
        public readonly Side $side,
        public readonly Money $base,
        public readonly ?Money $foreign = null,
        public readonly ?string $note = null,
        public readonly array $dimensions = [],
        public readonly array $dimensionExtra = [],
    ) {
        // Fail at construction rather than at the DB: a mistyped dimension key would otherwise be
        // silently dropped, and a dimension that quietly disappears is the fuel-line bug.
        foreach (array_keys($dimensions) as $column) {
            if (! in_array($column, self::typedColumns(), true)) {
                throw UnknownDimension::key((string) $column);
            }
        }
    }

    /**
     * Every typed dimension column in wider than the {@see Dimension} mask, which only
     * covers the five dimensions an account can *require*.
     *
     * @return list<string>
     */
    public static function typedColumns(): array
    {
        return [
            'truck_id', 'trailer_id', 'trip_id', 'job_id', 'customer_id', 'supplier_id',
            'driver_id', 'employee_id', 'work_order_id', 'invoice_id', 'bill_id',
            'fuel_issue_id', 'border_crossing_id', 'country_code', 'cost_centre_id',
        ];
    }

    public function dimensionValue(string $column): mixed
    {
        return $this->dimensions[$column] ?? null;
    }
}
