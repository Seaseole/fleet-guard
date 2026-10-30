<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\BulkTankMovement;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A dip reading against the book, and the variance posted where a variance belongs (§15.2 phase 3).
 *
 * Reconciliation is the point of keeping a stock value at all: the litrage in the tank is measured,
 * the book says what it should be, and the difference is a fact to record — not an error to hide by
 * editing the tank. The difference is valued at the tank's current average (the litres that went
 * missing were worth what the stock was worth) and posts `Dr 5110 Fuel stock variance / Cr 1180 Bulk
 * fuel stock` when short, the reverse when the dip finds more than the book knew about.
 *
 * A dip that agrees with the book is still recorded, with two zeroes and no entry: the confirmation
 * has a date and a person, which is what makes the next disagreement auditable.
 *
 * The row is locked while the reading is applied: two people counting the same tank at once should
 * not each subtract the same shortage.
 */
final class ReconcileBulkTank
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function __invoke(
        BulkTank $tank,
        int $physicalLitresX100,
        DateTimeInterface|CarbonImmutable $countedOn,
        ?string $note = null,
        ?int $actorId = null,
    ): BulkTankMovement {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Reconciling a bulk tank is workshop recording (§9.3).');
        }

        if ($physicalLitresX100 < 0) {
            throw new InvalidArgumentException('A dip reading cannot be negative.');
        }

        return DB::transaction(function () use ($tank, $physicalLitresX100, $countedOn, $note, $actorId): BulkTankMovement {
            $locked = BulkTank::query()->whereKey($tank->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('That bulk tank is not in this company any more.');

            $litresDelta = $physicalLitresX100 - $locked->stock_litres_x100;
            $valueDelta = $this->varianceValue($locked, $litresDelta);
            $bookLitres = $locked->stock_litres_x100;

            $movement = $locked->recordMovement(
                BulkTankMovementKind::Dip,
                $litresDelta,
                $valueDelta,
                $countedOn,
                note: $note,
                actorId: $actorId,
            );

            if ($valueDelta !== 0) {
                $this->post($movement, $locked, $valueDelta, $bookLitres, $countedOn, $actorId);
            }

            return $movement;
        }, 3);
    }

    /**
     * The variance in base minor units, signed with the litres. A dip to zero takes the value the
     * book says the tank holds, so an empty tank is worth nothing rather than whatever a rounded
     * average left behind.
     */
    private function varianceValue(BulkTank $tank, int $litresDelta): int
    {
        if ($litresDelta === 0) {
            return 0;
        }

        if ($tank->stock_litres_x100 + $litresDelta === 0) {
            return -$tank->stock_value_base;
        }

        $value = intdiv($tank->average_cost_per_litre_minor * abs($litresDelta) + 50, 100);

        return $litresDelta < 0 ? -$value : $value;
    }

    private function post(
        BulkTankMovement $movement,
        BulkTank $tank,
        int $valueDelta,
        int $bookLitresX100,
        DateTimeInterface|CarbonImmutable $countedOn,
        ?int $actorId,
    ): void {
        $accounts = $this->chart->byCode();
        $stock = $accounts['1180'] ?? throw new InvalidArgumentException('Account 1180 Bulk fuel stock is not in this company\'s chart. Seed the chart first (§6.2).');
        $variance = $accounts['5110'] ?? throw new InvalidArgumentException('Account 5110 Fuel stock variance is not in this company\'s chart. Seed the chart first (§6.2).');

        $base = CurrentCompany::model()->base_currency_code;
        $amount = Money::ofMinor(abs($valueDelta), $base);
        $short = $valueDelta < 0;

        $this->ledger->post(new PostingDto(
            description: sprintf(
                'Bulk tank dip · %s · book %s L, counted %s L',
                $tank->name,
                number_format($bookLitresX100 / 100, 2, '.', ''),
                number_format(($bookLitresX100 + $movement->litres_delta_x100) / 100, 2, '.', ''),
            ),
            postedOn: $countedOn,
            currencyCode: $base,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $countedOn,
            lines: [
                new LineDto(
                    accountId: $short ? $variance->id : $stock->id,
                    side: Side::Debit,
                    base: $amount,
                ),
                new LineDto(
                    accountId: $short ? $stock->id : $variance->id,
                    side: Side::Credit,
                    base: $amount,
                ),
            ],
            sourceType: 'bulk_tank_dip',
            sourceId: $movement->id,
            createdBy: $actorId,
        ));
    }
}
