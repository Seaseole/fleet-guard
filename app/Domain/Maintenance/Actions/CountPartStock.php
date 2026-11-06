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
 * A physical count of a part, and the variance posted where a variance belongs.
 *
 * Reconciliation is the point of keeping a stock value at all: the units on the shelf are counted,
 * the book says what should be there, and the difference is a fact to record — not an error to hide
 * by editing the part. The difference is valued at the shelf's current average (the units that went
 * missing were worth what the stock was worth) and posts `Dr 5315 Parts stock variance / Cr 1190 Parts
 * stock` when short, the reverse when the count finds more than the book knew about.
 *
 * A count that agrees with the book is still recorded, with two zeroes and no entry: the confirmation
 * has a date and a person, which is what makes the next disagreement auditable.
 *
 * The row is locked while the count is applied: two people counting the same shelf at once should
 * not each subtract the same shortage.
 */
final class CountPartStock
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function __invoke(
        Part $part,
        int $countedQuantityX100,
        DateTimeInterface $countedOn,
        ?string $note = null,
        ?int $actorId = null,
    ): StockMovement {
        if (! Gate::allows('parts.count')) {
            throw new InvalidArgumentException('Counting the stores shelf is the workshop’s count.');
        }

        if ($countedQuantityX100 < 0) {
            throw new InvalidArgumentException('A count cannot be negative.');
        }

        $base = CurrentCompany::model()->base_currency_code;

        return DB::transaction(function () use ($part, $countedQuantityX100, $countedOn, $note, $actorId, $base): StockMovement {
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('That part is not in this company any more.');

            $delta = $countedQuantityX100 - $locked->stock_quantity_x100;
            $value = $this->varianceValue($locked, $delta);

            $movement = $locked->recordMovement(
                StockMovementKind::Count,
                $delta,
                $value,
                $countedOn,
                null,
                null,
                $note,
                $actorId,
            );

            if ($value !== 0) {
                $this->post($locked, $movement, $value, $countedOn, $base, $actorId);
            }

            return $movement;
        }, 3);
    }

    /**
     * The variance in base minor units, signed with the units. A count to zero takes the value the
     * book says the shelf holds, so an empty shelf is worth nothing rather than whatever a rounded
     * average left behind.
     */
    private function varianceValue(Part $part, int $delta): int
    {
        if ($delta === 0) {
            return 0;
        }

        if ($part->stock_quantity_x100 + $delta === 0) {
            return -$part->stock_value_base;
        }

        $value = intdiv($part->average_cost_minor * abs($delta) + 50, 100);

        return $delta < 0 ? -$value : $value;
    }

    private function post(
        Part $part,
        StockMovement $movement,
        int $value,
        DateTimeInterface $countedOn,
        string $base,
        ?int $actorId,
    ): void {
        $accounts = $this->chart->byCode();
        $stock = $accounts['1190']?? throw new InvalidArgumentException('Account 1190 Parts stock is not in this company\'s chart. Seed the chart first.');
        $variance = $accounts['5315']?? throw new InvalidArgumentException('Account 5315 Parts stock variance is not in this company\'s chart. Seed the chart first.');

        $amount = Money::ofMinor(abs($value), $base);
        $short = $value < 0;

        $this->ledger->post(PostingDto::inBaseCurrency(
            description: sprintf(
                'Stock count · %s · book %s, counted %s',
                $part->label(),
                number_format(($movement->quantity_after_x100 - $movement->quantity_delta_x100) / 100, 2, '.', ''),
                number_format($movement->quantity_after_x100 / 100, 2, '.', ''),
            ),
            postedOn: $countedOn,
            baseCurrencyCode: $base,
            lines: [
                new LineDto(accountId: $short ? $variance->id : $stock->id, side: Side::Debit, base: $amount),
                new LineDto(accountId: $short ? $stock->id : $variance->id, side: Side::Credit, base: $amount),
            ],
            sourceType: 'stock_movement',
            sourceId: $movement->id,
            createdBy: $actorId,
        ));
    }
}
