<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelCard;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Kiosk\KioskAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records one slip — the purchase the fleet paid for fuel (FUEL_PURCHASE).
 *
 * A slip has two possible destinations, and the difference is what the ledger records:
 *
 * - **A pump-card slip** the trucks will drain. The row is operational, not accounting:
 *   `posted_to_ledger` stays false because the `FuelPurchaseRecorded` entry needs the supplier
 *   bill, and supplier bills are Phase 5's. Writing a journal entry without the liability would make
 *   the ledger claim the fleet paid cash for diesel it owes for.
 * - **A delivery into a bulk tank**. It becomes stock the moment it is unloaded, so
 *   it posts `Dr 1180 Bulk fuel stock / Cr 2110 Supplier payable` in the same transaction that
 *   records the movement. The payable is real and Phase 5's bill will settle it against 2110 — this
 *   action does not pre-empt the bill, it only stops the stock account from going negative.
 *
 * `source_uuid` is the idempotency key: a re-delivered cutover row or a retried card-feed entry is
 * the same purchase, and the Action returns the row it already made rather than a second slip.
 */
final class CreateFuelPurchase
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function __invoke(FuelPurchaseDto $dto, ?int $actorId = null, ?KioskAuthority $kiosk = null): FuelPurchase
    {
        if ($kiosk === null && ! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Recording a fuel purchase is workshop recording.');
        }

        if ($dto->sourceUuid !== null && $dto->sourceUuid !== '') {
            $existing = FuelPurchase::query()->where('source_uuid', $dto->sourceUuid)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $this->assertStationBelongs($dto->stationId);
        $this->assertCardBelongs($dto->cardId);

        $tank = $this->resolveTank($dto->bulkTankId);

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        $fx = Fx::foreignToBase(
            $dto->amount->minorUnits,
            $dto->amount->currencyCode,
            $dto->rate,
            $baseCurrency,
            $dto->rateSource,
            $dto->rateDate,
            $dto->fxRef,
        );

        if ($fx->baseAmount()->minorUnits <= 0) {
            throw new InvalidArgumentException(sprintf(
                '%s at the stated rate converts to less than one %s minor unit, which the register cannot carry.',
                $dto->amount->format($baseCurrency),
                $baseCurrency,
            ));
        }

        return DB::transaction(function () use ($dto, $actorId, $fx, $tank, $baseCurrency): FuelPurchase {
            $purchase = FuelPurchase::create(array_merge($fx->toColumns(), [
                'id' => (string) Str::ulid(),
                'fuel_station_id' => $dto->stationId,
                'fuel_card_id' => $dto->cardId,
                'bulk_tank_id' => $tank?->id,
                'fuel_type' => $dto->fuelType->value,
                'purchased_on' => $dto->purchasedOn->format('Y-m-d'),
                'litres' => $dto->litresX100,
                'price_per_litre_minor' => $dto->pricePerLitreMinor,
                'invoice_no' => $dto->invoiceNo === null || trim($dto->invoiceNo) === '' ? null : trim($dto->invoiceNo),
                // The table's CHECK requires it: a delivery into stock is an asset and its payable
                // the moment it is recorded, so the flag is true in the same insert.
                'posted_to_ledger' => $tank !== null,
                'source_uuid' => $dto->sourceUuid === '' ? null : $dto->sourceUuid,
                'recorded_by' => $actorId,
            ]));

            if ($tank !== null) {
                $this->postReceipt($purchase, $tank, $baseCurrency, $actorId);
            }

            return $purchase;
        }, 3);
    }

    private function resolveTank(?int $tankId): ?BulkTank
    {
        if ($tankId === null) {
            return null;
        }

        $tank = BulkTank::query()->whereKey($tankId)->first()
            ?? throw new InvalidArgumentException('That bulk tank is not in this company\'s register.');

        if (! $tank->active) {
            throw new InvalidArgumentException(sprintf('%s is no longer in use, so a delivery cannot go into it.', $tank->name));
        }

        return $tank;
    }

    private function postReceipt(FuelPurchase $purchase, BulkTank $tank, string $baseCurrency, ?int $actorId): void
    {
        $accounts = $this->chart->byCode();
        $stock = $accounts['1180']?? throw new InvalidArgumentException('Account 1180 Bulk fuel stock is not in this company\'s chart. Seed the chart first.');
        $payable = $accounts['2110']?? throw new InvalidArgumentException('Account 2110 Accounts payable is not in this company\'s chart. Seed the chart first.');

        $base = $purchase->baseMoney();
        $foreign = $purchase->currency_code === $baseCurrency ? null : $purchase->amountMoney();

        $this->ledger->post(new PostingDto(
            description: sprintf('Bulk fuel delivery · %s · %s L', $tank->name, $purchase->litresMajor()),
            postedOn: $purchase->purchased_on,
            currencyCode: $purchase->currency_code,
            fxRate: (string) $purchase->fx_rate,
            fxRateSource: $purchase->fx_rate_source,
            fxRateDate: $purchase->fx_rate_date,
            lines: [
                new LineDto(accountId: $stock->id, side: Side::Debit, base: $base, foreign: $foreign),
                new LineDto(accountId: $payable->id, side: Side::Credit, base: $base, foreign: $foreign),
            ],
            sourceType: 'fuel_purchase',
            sourceId: $purchase->id,
            createdBy: $actorId,
            fxRateRef: $purchase->fx_rate_ref,
        ));

        // R7: a delivery that would take the tank past its stated capacity is a figure to look at,
        // not a delivery to refuse — the capacity is what somebody measured once, the litres are what
        // the pump delivered, and refusing would push the difference into a wrong-but-accepted number.
        $projected = $tank->stock_litres_x100 + $purchase->litres;
        $note = $projected > $tank->capacity_litres * 100
            ? sprintf(
                '%s L takes the tank past its %s L capacity.',
                number_format($projected / 100, 2, '.', ''),
                $tank->capacity_litres,
            )
            : null;

        $tank->recordMovement(
            BulkTankMovementKind::Receipt,
            $purchase->litres,
            $purchase->base_amount,
            $purchase->purchased_on,
            'fuel_purchase',
            $purchase->id,
            $note ?? $purchase->invoice_no,
            $actorId,
        );
    }

    private function assertStationBelongs(?int $stationId): void
    {
        if ($stationId === null) {
            return;
        }

        // The global company scope already filters this; the explicit read makes a cross-company id
        // a refusal with a sentence instead of a foreign-key failure at insert.
        if (! FuelStation::query()->whereKey($stationId)->exists()) {
            throw new InvalidArgumentException('That fuel station is not in this company\'s register.');
        }
    }

    private function assertCardBelongs(?int $cardId): void
    {
        if ($cardId === null) {
            return;
        }

        if (! FuelCard::query()->whereKey($cardId)->exists()) {
            throw new InvalidArgumentException('That fuel card is not in this company\'s register.');
        }
    }
}
