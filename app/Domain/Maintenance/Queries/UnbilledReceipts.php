<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Queries;

use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\Data\UnbilledObligation;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The stores' read door for slice 5b: receipts that already posted `Dr 1190 / Cr 2110` (§20.29) and
 * are still waiting for the supplier's paper. Fuel's twin is `Fuel\Queries\UnbilledDeliveries`; both
 * answer Accounting's `UnbilledObligation` so the bills picker lists one shape (§3.1 D5).
 */
final class UnbilledReceipts
{
    /** @return list<UnbilledObligation> */
    public function all(): array
    {
        $rows = DB::table('stock_movements')
            ->join('parts', 'parts.id', '=', 'stock_movements.part_id')
            ->where('stock_movements.company_id', CurrentCompany::id())
            ->where('stock_movements.kind', 'receipt')
            ->whereNull('stock_movements.bill_id')
            ->orderBy('stock_movements.moved_on')
            ->get([
                'stock_movements.id',
                'stock_movements.quantity_delta_x100',
                'stock_movements.value_delta_base',
                'stock_movements.moved_on',
                'parts.sku',
                'parts.name',
            ]);

        return array_values($rows
            ->map(static fn (stdClass $row): UnbilledObligation => new UnbilledObligation(
                kind: BillLinkedSource::StockReceipt,
                id: (string) $row->id,
                label: sprintf(
                    '%s · %s · %s units · %s',
                    (string) $row->sku,
                    (string) $row->name,
                    number_format(((int) $row->quantity_delta_x100) / 100, 2, '.', ''),
                    Carbon::parse((string) $row->moved_on)->format('d M Y'),
                ),
                amountBase: (int) $row->value_delta_base,
                occurredOn: Carbon::parse((string) $row->moved_on)->toDateString(),
            ))
            ->values()
            ->all());
    }

    public function find(string $id): ?UnbilledObligation
    {
        foreach ($this->all() as $obligation) {
            if ($obligation->id === $id) {
                return $obligation;
            }
        }

        return null;
    }
}
