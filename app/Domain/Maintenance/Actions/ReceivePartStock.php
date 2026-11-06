<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Models\Part;
use App\Domain\Maintenance\Models\StockMovement;
use App\Domain\Maintenance\StockMovementKind;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A purchase arriving on the stores shelf (phase 3, slice 3e).
 *
 * The shelf is an asset the moment the parts are put away, so the receipt posts
 * `Dr 1190 Parts stock / Cr 2110 Supplier payable` in the same transaction that records the movement.
 * The payable is real, and Phase 5's bill will settle it against 2110 — this action does not pre-empt
 * the bill, it only stops the stock account from being a number nobody funded.
 *
 * The receipt is valued in the company's base currency. A part bought in ZAR needs the invoice's rate
 * and belongs on the supplier bill, which carries it; accepting a foreign figure here would
 * have to invent a rate, and an invented rate is a ledger entry that disagrees with the document.
 *
 * The row is locked while the receipt is applied: two people receiving the same delivery at once
 * should not each add it.
 */
final class ReceivePartStock
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function __invoke(
        Part $part,
        int $quantityX100,
        Money $unitCost,
        DateTimeInterface $receivedOn,
        ?string $note = null,
        ?int $actorId = null,
    ): StockMovement {
        if (! Gate::allows('parts.adjust')) {
            throw new InvalidArgumentException('Receiving stock adjusts the stores book, which this seat may not do.');
        }

        if ($quantityX100 <= 0) {
            throw new InvalidArgumentException('A receipt must bring at least one hundredth of a unit onto the shelf.');
        }

        $base = CurrentCompany::model()->base_currency_code;

        if ($unitCost->currencyCode !== $base) {
            throw new InvalidArgumentException(sprintf(
                'Stock is valued in %s. A part bought in %s arrives with the supplier bill, which carries its rate (Phase 5).',
                $base,
                $unitCost->currencyCode,
            ));
        }

        if ($unitCost->minorUnits < 0) {
            throw new InvalidArgumentException('A unit cost cannot be negative.');
        }

        $value = intdiv($quantityX100 * $unitCost->minorUnits + 50, 100);

        if ($value <= 0) {
            throw new InvalidArgumentException('That many units at that price is less than one minor unit, which the ledger cannot carry.');
        }

        return DB::transaction(function () use ($part, $quantityX100, $value, $unitCost, $receivedOn, $note, $actorId, $base): StockMovement {
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('That part is not in this company any more.');

            $movement = $locked->recordMovement(
                StockMovementKind::Receipt,
                $quantityX100,
                $value,
                $receivedOn,
                null,
                $unitCost->minorUnits,
                $note,
                $actorId,
            );

            $this->post($locked, $movement, $value, $receivedOn, $base, $actorId);

            return $movement;
        }, 3);
    }

    private function post(
        Part $part,
        StockMovement $movement,
        int $value,
        DateTimeInterface $receivedOn,
        string $base,
        ?int $actorId,
    ): void {
        $accounts = $this->chart->byCode();
        $stock = $accounts['1190']?? throw new InvalidArgumentException('Account 1190 Parts stock is not in this company\'s chart. Seed the chart first.');
        $payable = $accounts['2110']?? throw new InvalidArgumentException('Account 2110 Accounts payable is not in this company\'s chart. Seed the chart first.');

        $amount = Money::ofMinor($value, $base);

        $this->ledger->post(PostingDto::inBaseCurrency(
            description: sprintf(
                'Stock receipt · %s · %s units',
                $part->label(),
                number_format($movement->quantity_delta_x100 / 100, 2, '.', ''),
            ),
            postedOn: $receivedOn,
            baseCurrencyCode: $base,
            lines: [
                new LineDto(accountId: $stock->id, side: Side::Debit, base: $amount),
                new LineDto(accountId: $payable->id, side: Side::Credit, base: $amount),
            ],
            sourceType: 'stock_movement',
            sourceId: $movement->id,
            createdBy: $actorId,
        ));
    }
}
