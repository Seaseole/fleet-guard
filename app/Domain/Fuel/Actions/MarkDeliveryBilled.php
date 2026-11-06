<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\Models\FuelPurchase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fuel's own stamp: the delivery's supplier paper has arrived (or has been released).
 *
 * The `bill_id` column lives on Fuel's row, and `RecordBill`/`VoidBill` may not write another
 * module's table (D5), so the link is made through this door: Accounting asks with the two ids
 * as **strings** — no model crosses the boundary — and Fuel locks its own row, refuses a delivery
 * that is not unbilled-posted stock, and stamps it.
 *
 * `$billId` is read back from `bills` by raw query only to prove the caller's claim (company and
 * recorded status); the bill model itself never enters Fuel. Passing null releases the stamp, which
 * is what lets a voided paper free the delivery to be billed again on a fresh row.
 */
final class MarkDeliveryBilled
{
    public function __invoke(string $purchaseId, ?string $billId, int $companyId): FuelPurchase
    {
        return DB::transaction(function () use ($purchaseId, $billId, $companyId): FuelPurchase {
            /** @var FuelPurchase $purchase */
            $purchase = FuelPurchase::query()
                ->where('company_id', $companyId)
                ->whereKey($purchaseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($billId === null) {
                $purchase->forceFill(['bill_id' => null])->save();

                return $purchase;
            }

            if ($purchase->bulk_tank_id === null || ! $purchase->posted_to_ledger) {
                throw new InvalidArgumentException(sprintf(
                    'Purchase %s is a pump slip, not a posted bulk delivery — a slip becomes an expense when the diesel is issued to a trip, and its payable arrives through the issue, not this door.',
                    $purchase->id,
                ));
            }

            if ($purchase->bill_id !== null && $purchase->bill_id !== $billId) {
                throw new InvalidArgumentException(sprintf(
                    'That delivery already has the bill %s against it. One paper per delivery, so one diesel cannot fund two payables.',
                    $purchase->bill_id,
                ));
            }

            $bill = DB::table('bills')
                ->where('company_id', $companyId)
                ->where('id', $billId)
                ->where('status', '<>', 'void')
                ->exists();

            if (! $bill) {
                throw new InvalidArgumentException('The bill to stamp is not a live bill in this company, so the delivery stays unbilled.');
            }

            $purchase->forceFill(['bill_id' => $billId])->save();

            return $purchase;
        }, 3);
    }
}
