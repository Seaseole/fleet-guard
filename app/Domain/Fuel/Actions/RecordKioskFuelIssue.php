<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\Data\KioskFuelDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Kiosk\KioskAuthority;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\TripAccessScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * §9.6 — the driver's fuel screen, written through the doors the office uses.
 *
 * A driver at a pump has no slip in the register: they paid cash, and the receipt in their hand is
 * the only paperwork. So this Action records that slip (`CreateFuelPurchase`, unposted until the
 * diesel is issued, exactly as the office does) and then issues its litres onto the trip
 * (`RecordFuelIssue`), which posts the 5100 cost against the trip's truck, moves the odometer and
 * raises the trip cost — one transaction, because a slip with no issue is diesel the driver paid for
 * and the truck never got credit for.
 *
 * The kiosk source settles as petty cash (`TripCostPayment::PettyCash`): the driver's own money or
 * their advance left the yard, and the office reconciles it later. Nothing here pre-empts that
 * settlement, and nothing here edits an issue — §10.9's correction remains a reversal.
 *
 * **The retry is the normal case** (§9.6). The screen mints a `client_uuid` per page load, and the
 * same uuid on a second attempt returns the row it already made instead of buying the diesel twice;
 * the purchase and the issue share the key so an interrupted first attempt is completed rather than
 * duplicated.
 */
final class RecordKioskFuelIssue
{
    public function __construct(
        private readonly CreateFuelPurchase $purchases,
        private readonly RecordFuelIssue $issues,
    ) {}

    public function __invoke(
        TripAccessToken $token,
        string $secret,
        string $pin,
        KioskFuelDto $dto,
        ?string $clientUuid = null,
    ): FuelIssue {
        $authority = KioskAuthority::verified($token, $secret, $pin, TripAccessScope::Fuel);

        $trip = Trip::query()->find($authority->token->trip_id)
            ?? throw new InvalidArgumentException('That driver link names a trip that is no longer here.');

        $uuid = $clientUuid === null || trim($clientUuid) === '' ? null : trim($clientUuid);

        return DB::transaction(function () use ($authority, $trip, $dto, $uuid): FuelIssue {
            if ($uuid !== null) {
                $seen = FuelIssue::query()->where('source_uuid', $uuid)->first();

                if ($seen !== null) {
                    return $seen;
                }
            }

            $baseCurrency = CurrentCompany::model()->base_currency_code;

            // The slip's product is the truck's, read from the register rather than from the phone:
            // the driver has no reason to pick it, and a client that could would let a petrol receipt
            // be recorded as diesel.
            $truck = $trip->truck_id === null
                ? null
                : Truck::query()->whereKey($trip->truck_id)->first();

            if ($truck === null) {
                throw new InvalidArgumentException('That trip names no truck, so the slip cannot say what fuel it bought.');
            }

            $purchase = ($this->purchases)(new FuelPurchaseDto(
                amount: Money::ofMinor($dto->amountMinor(), $baseCurrency),
                rate: '1',
                rateSource: FxRateSource::Manual,
                rateDate: $dto->occurredOn,
                purchasedOn: $dto->occurredOn,
                litresX100: $dto->litresX100,
                pricePerLitreMinor: $dto->pricePerLitreMinor,
                fuelType: $truck->fuel_type,
                stationId: $dto->stationId,
                invoiceNo: $dto->invoiceNo,
                sourceUuid: $uuid,
            ), null, $authority);

            return ($this->issues)($trip, new FuelIssueDto(
                tankId: $dto->tankId,
                litresX100: $dto->litresX100,
                odometerKm: $dto->odometerKm,
                occurredOn: $dto->occurredOn,
                purchaseId: $purchase->id,
                source: FuelIssueSource::Kiosk,
                note: $dto->note,
                sourceUuid: $uuid,
            ), null, $authority);
        }, 3);
    }
}
