<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\Models\FuelCard;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records one slip — the purchase the fleet paid for fuel (§4.3 FUEL_PURCHASE, §5.2, §6.8).
 *
 * The row is operational, not accounting, in this slice: `posted_to_ledger` stays false because
 * §6.8's `FuelPurchaseRecorded` entry (`Dr 5100 Fuel / Cr 3100 Supplier payable`) needs the supplier
 * bill or the pump-and-pay cash line, and both are Phase 5's. Writing a journal entry without the
 * liability would make the ledger claim the fleet paid cash for diesel it owes for.
 *
 * `source_uuid` is the idempotency key: a re-delivered cutover row or a retried card-feed entry is
 * the same purchase, and the Action returns the row it already made rather than a second slip.
 */
final class CreateFuelPurchase
{
    public function __invoke(FuelPurchaseDto $dto, ?int $actorId = null): FuelPurchase
    {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Recording a fuel purchase is workshop recording (§9.3).');
        }

        if ($dto->sourceUuid !== null && $dto->sourceUuid !== '') {
            $existing = FuelPurchase::query()->where('source_uuid', $dto->sourceUuid)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $this->assertStationBelongs($dto->stationId);
        $this->assertCardBelongs($dto->cardId);

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

        return FuelPurchase::create(array_merge($fx->toColumns(), [
            'id' => (string) Str::ulid(),
            'fuel_station_id' => $dto->stationId,
            'fuel_card_id' => $dto->cardId,
            'purchased_on' => $dto->purchasedOn->format('Y-m-d'),
            'litres' => $dto->litresX100,
            'price_per_litre_minor' => $dto->pricePerLitreMinor,
            'invoice_no' => $dto->invoiceNo === null || trim($dto->invoiceNo) === '' ? null : trim($dto->invoiceNo),
            'posted_to_ledger' => false,
            'source_uuid' => $dto->sourceUuid === '' ? null : $dto->sourceUuid,
            'recorded_by' => $actorId,
        ]));
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
