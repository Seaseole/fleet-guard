<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

use App\Domain\Kernel\Ledger\Exceptions\UnknownDimension;

/**
 * The `gl_accounts.allowed_dimension_mask` bit flags and the column each one reads.
 *
 * SEMANTICS, stated because the column name is ambiguous: a bit set on an account means a line
 * posted to that account MUST carry the dimension. The design gives the reason — "this account must
 * carry a truck" catches the class of posting bug where a fuel line loses its truck and quietly
 * lands in overheads. A mask that merely declared what is *permitted* would not catch that: the
 * line would post, the truck would be null, and the overhead would absorb the cost.
 *
 * It is a bitmask rather than five booleans because the poster asserts one integer and a report
 * reads it the same way, and adding a sixth dimension is a case value rather than a migration of
 * the accounts table.
 */
enum Dimension: int
{
    case Truck = 1;
    case Trip = 2;
    case Customer = 4;
    case Driver = 8;
    case CostCentre = 16;

    /** The `gl_journal_lines` column that carries this dimension (typed columns). */
    public function column(): string
    {
        return match ($this) {
            self::Truck => 'truck_id',
            self::Trip => 'trip_id',
            self::Customer => 'customer_id',
            self::Driver => 'driver_id',
            self::CostCentre => 'cost_centre_id',
        };
    }

    /** @return list<string> */
    public static function columns(): array
    {
        return array_map(fn (self $d): string => $d->column(), self::cases());
    }

    public static function fromColumn(string $column): self
    {
        foreach (self::cases() as $case) {
            if ($case->column() === $column) {
                return $case;
            }
        }

        throw UnknownDimension::column($column);
    }

    /** @param  int  $mask  the bitwise OR of the {@see self} cases the account requires */
    public static function isIn(int $mask, self $dimension): bool
    {
        return ($mask & $dimension->value) === $dimension->value;
    }

    /**
     * @param  int  $mask  the bitwise OR of the {@see self} cases the account requires
     * @return list<self>
     */
    public static function listFrom(int $mask): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $d): bool => self::isIn($mask, $d),
        ));
    }

    /** @param  array<int, self>  $dimensions */
    public static function maskOf(array $dimensions): int
    {
        $mask = 0;

        foreach ($dimensions as $dimension) {
            $mask |= $dimension->value;
        }

        return $mask;
    }
}
