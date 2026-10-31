<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Queries;

use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\Data\UnbilledObligation;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Fuel's read door for slice 5b: the bulk deliveries that already posted `Cr 2110` at the movement
 * (§20.26) and are still waiting for the supplier's paper.
 *
 * Accounting asks rather than reaches: the rows are Fuel's, the projection is Accounting's vocabulary
 * (`UnbilledObligation`), and §3.1's D5 stays intact — the picker on the bills screen and the link
 * check in `RecordBill` both read through this one door. Raw columns rather than an Eloquent row: a
 * read door owes a projection, so nothing can be written back through it.
 */
final class UnbilledDeliveries
{
    /** @return list<UnbilledObligation> */
    public function all(): array
    {
        $rows = DB::table('fuel_purchases')
            ->join('bulk_tanks', 'bulk_tanks.id', '=', 'fuel_purchases.bulk_tank_id')
            ->where('fuel_purchases.company_id', CurrentCompany::id())
            ->where('fuel_purchases.posted_to_ledger', true)
            ->whereNull('fuel_purchases.bill_id')
            ->orderBy('fuel_purchases.purchased_on')
            ->get([
                'fuel_purchases.id',
                'fuel_purchases.base_amount',
                'fuel_purchases.purchased_on',
                'fuel_purchases.litres',
                'bulk_tanks.name as tank_name',
            ]);

        return array_values($rows
            ->map(static function (stdClass $row): UnbilledObligation {
                $on = Carbon::parse((string) $row->purchased_on);

                return new UnbilledObligation(
                    kind: BillLinkedSource::FuelDelivery,
                    id: (string) $row->id,
                    label: sprintf(
                        '%s · %s L · %s',
                        (string) $row->tank_name,
                        number_format(((int) $row->litres) / 100, 2, '.', ''),
                        $on->format('d M Y'),
                    ),
                    amountBase: (int) $row->base_amount,
                    occurredOn: $on->toDateString(),
                );
            })
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
