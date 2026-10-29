<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\Money;
use App\Domain\Maintenance\PartCategory;
use InvalidArgumentException;

/**
 * The validated input behind the part Actions (D1).
 *
 * The cost arrives as **minor units** in the part's own currency, converted by the screen through
 * {@see Money::ofMajor()} — the same boundary as every other typed amount.
 * `reorderLevel` is a count of units, not money and not a decimal: the column is a smallint and a
 * level of 2.5 is meaningless.
 */
final readonly class PartDto
{
    public function __construct(
        public string $sku,
        public string $name,
        public PartCategory $category,
        public int $unitCostMinor,
        public string $currencyCode,
        public ?int $reorderLevel = null,
        public ?int $truckId = null,
        public bool $active = true,
    ) {
        if (trim($sku) === '') {
            throw new InvalidArgumentException('A part needs a SKU — it is how the stores clerk and the workshop order it.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('A part needs a name.');
        }

        if ($unitCostMinor < 0) {
            throw new InvalidArgumentException('A part cost is a non-negative amount in minor units.');
        }

        if ($reorderLevel !== null && $reorderLevel < 0) {
            throw new InvalidArgumentException('A reorder level is a non-negative number of units.');
        }

        if ($currencyCode === '') {
            throw new InvalidArgumentException('A part cost needs a currency.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $reorder = $input['reorder_level'] ?? null;
        $truck = $input['truck_id'] ?? null;

        return new self(
            sku: mb_strtoupper(trim((string) $input['sku'])),
            name: trim((string) $input['name']),
            category: PartCategory::from((string) $input['category']),
            unitCostMinor: (int) $input['unit_cost_minor'],
            currencyCode: Currency::normalise((string) $input['currency_code']),
            reorderLevel: $reorder === null || $reorder === '' ? null : (int) $reorder,
            truckId: $truck === null || $truck === '' ? null : (int) $truck,
            active: (bool) ($input['active'] ?? true),
        );
    }
}
