<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\StockMovement;
use App\Domain\Maintenance\StockMovementKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The stores' own stamp: the supplier paper behind a shelf receipt has arrived (or was released).
 *
 * The mirror of Fuel's `MarkDeliveryBilled`: the receipt posted `Dr 1190 / Cr 2110` at the movement
 *, so the bill documents it through this door and never re-expenses it. Accounting asks with
 * two strings; Maintenance locks its own row and answers. An issue or a count has no supplier paper of
 * its own, so only receipts pass.
 */
final class MarkStockReceiptBilled
{
    public function __invoke(string $movementId, ?string $billId, int $companyId): StockMovement
    {
        return DB::transaction(function () use ($movementId, $billId, $companyId): StockMovement {
            /** @var StockMovement $movement */
            $movement = StockMovement::query()
                ->where('company_id', $companyId)
                ->whereKey($movementId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($billId === null) {
                $movement->forceFill(['bill_id' => null])->save();

                return $movement;
            }

            if ($movement->kind !== StockMovementKind::Receipt) {
                throw new InvalidArgumentException(sprintf(
                    'Movement %s is a %s, not a receipt — only a receipt has supplier paper behind it; an issue consumed stock the shelf already funded.',
                    $movement->id,
                    mb_strtolower($movement->kind->label()),
                ));
            }

            if ($movement->bill_id !== null && $movement->bill_id !== $billId) {
                throw new InvalidArgumentException(sprintf(
                    'That receipt already has the bill %s against it. One paper per receipt, so one shelf cannot fund two payables.',
                    $movement->bill_id,
                ));
            }

            $bill = DB::table('bills')
                ->where('company_id', $companyId)
                ->where('id', $billId)
                ->where('status', '<>', 'void')
                ->exists();

            if (! $bill) {
                throw new InvalidArgumentException('The bill to stamp is not a live bill in this company, so the receipt stays unbilled.');
            }

            $movement->forceFill(['bill_id' => $billId])->save();

            return $movement;
        }, 3);
    }
}
