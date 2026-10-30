<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\RecordTripCost;
use App\Domain\Operations\Data\TripCostDto;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues fuel onto a trip, and posts it, and measures the truck — in one transaction (§4.3, §6.8).
 *
 * This is the fuel module's one write door for the fleet's diesel, and it composes Phase 2's
 * machinery rather than re-implementing it:
 *
 * - the **trip cost** is `RecordTripCost`'s, so the frozen-trip rule, the `expenses.create` seat and
 *   the rollup move in exactly the way a toll does;
 * - the **odometer** is `RecordOdometerReading`'s, so the plausibility rule (a reading below the
 *   truck's projection is stored `suspect`, never silently accepted as truth) is the same one the
 *   departure and arrival readings get;
 * - the **cost** is the slip's own price and rate, or the bulk tank's moving average, frozen onto the
 *   issue. Deriving it later would answer with today's conversion for money posted at the slip's.
 *
 * What this Action owns is the issue's own refusals. The trip must have a truck and the tank must be
 * that truck's; a slip must exist, be in the same company, and still have litres left on it — a slip
 * cannot dispense more than it bought. A bulk tank must hold the litres asked for: stock may not go
 * negative, because a tank that owes litres is not a number anybody can reconcile.
 *
 * Above everything, the issue is **on a trip**: §4.3 allows a null `trip_id` for stock consumed off a
 * trip, but account 5100 requires the trip dimension (§6.1), so this door still demands one. The
 * kiosk source arrives with its own screen in slice 3d and is refused by name until then.
 */
final class RecordFuelIssue
{
    public function __construct(
        private readonly RecordTripCost $recordCost,
        private readonly RecordOdometerReading $recordOdometer,
    ) {}

    public function __invoke(Trip $trip, FuelIssueDto $dto, ?int $actorId = null): FuelIssue
    {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Issuing fuel is workshop recording (§9.3).');
        }

        if ($dto->sourceUuid !== null && $dto->sourceUuid !== '') {
            $existing = FuelIssue::query()->where('source_uuid', $dto->sourceUuid)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $this->assertSourceIsBuilt($dto->source);

        $truckId = $trip->truck_id ?? throw new InvalidArgumentException(sprintf(
            'Trip %s has no truck, so there is no tank to fill. Plan the trip first (§10.2).',
            $trip->trip_no,
        ));

        $tank = FuelTank::query()
            ->whereKey($dto->tankId)
            ->where('truck_id', $truckId)
            ->where('active', true)
            ->first()
            ?? throw new InvalidArgumentException(sprintf(
                'That tank is not an active tank on the truck assigned to trip %s. Fuel goes into the trip\'s own truck.',
                $trip->trip_no,
            ));

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        return DB::transaction(function () use ($trip, $truckId, $tank, $dto, $actorId, $baseCurrency): FuelIssue {
            $purchase = null;
            $bulkTank = null;

            if ($dto->source === FuelIssueSource::BulkTank) {
                $bulkTank = BulkTank::query()
                    ->whereKey($dto->bulkTankId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first()
                    ?? throw new InvalidArgumentException('That bulk tank is not an active tank in this company\'s register.');

                if ($dto->litresX100 > $bulkTank->stock_litres_x100) {
                    throw new InvalidArgumentException(sprintf(
                        '%s holds %s litres and this issue asks for %s. A tank cannot give what it does not have.',
                        $bulkTank->name,
                        $bulkTank->stockLitres(),
                        number_format($dto->litresX100 / 100, 2, '.', ''),
                    ));
                }

                $costMoney = Money::ofMinor($bulkTank->costForIssue($dto->litresX100), $baseCurrency);
                $baseMinor = $costMoney->minorUnits;
                $rate = '1';
                $rateSource = FxRateSource::Manual;
                $rateDate = $dto->occurredOn;
                $paidWith = TripCostPayment::Stock;
            } else {
                $purchase = FuelPurchase::query()->whereKey($dto->purchaseId)->first()
                    ?? throw new InvalidArgumentException('That fuel purchase is not in this company\'s register, so the issue has no cost to carry.');

                $remaining = $purchase->litresRemainingX100();

                if ($dto->litresX100 > $remaining) {
                    throw new InvalidArgumentException(sprintf(
                        'Purchase %s has %s litres left and this issue asks for %s. Record a second slip, or correct the figure.',
                        $purchase->invoice_no ?? substr($purchase->id, -8),
                        number_format($remaining / 100, 2, '.', ''),
                        number_format($dto->litresX100 / 100, 2, '.', ''),
                    ));
                }

                // The slip's price is in the purchase's currency; the issue's cost is a minor-unit
                // figure in that currency, rounded half-up once, then converted at the purchase's own
                // rate. Two roundings would let the issue and the slip disagree by a cent per litre.
                $foreignMinor = intdiv($dto->litresX100 * $purchase->price_per_litre_minor + 50, 100);

                if ($foreignMinor <= 0) {
                    throw new InvalidArgumentException('That many litres at the slip\'s price is less than one minor unit, which the ledger cannot carry.');
                }

                $costMoney = Money::ofMinor($foreignMinor, $purchase->currency_code);

                $fx = Fx::foreignToBase(
                    $foreignMinor,
                    $purchase->currency_code,
                    (string) $purchase->fx_rate,
                    $baseCurrency,
                    $purchase->fx_rate_source,
                    $purchase->fx_rate_date,
                );

                if ($fx->baseAmount()->minorUnits <= 0) {
                    throw new InvalidArgumentException('That many litres at the slip\'s price converts to less than one base minor unit, which the ledger cannot carry.');
                }

                $baseMinor = $fx->baseAmount()->minorUnits;
                $rate = (string) $purchase->fx_rate;
                $rateSource = $purchase->fx_rate_source;
                $rateDate = $purchase->fx_rate_date;
                $paidWith = $this->paidWith($dto->source);
            }

            $issue = FuelIssue::create([
                'id' => (string) Str::ulid(),
                'truck_id' => $truckId,
                'fuel_tank_id' => $tank->id,
                'trip_id' => $trip->id,
                'litres_x100' => $dto->litresX100,
                'odometer_km' => $dto->odometerKm,
                'purchase_id' => $purchase?->id,
                'bulk_tank_id' => $bulkTank?->id,
                'cost_base' => $baseMinor,
                'base_currency_code' => $baseCurrency,
                'source' => $dto->source,
                'status' => FuelIssueStatus::Recorded,
                'exception_reason' => $this->exceptionReason($dto, $tank),
                'source_uuid' => $dto->sourceUuid === '' ? null : $dto->sourceUuid,
                'recorded_by' => $actorId,
            ]);

            ($this->recordOdometer)(new OdometerReadingDto(
                truckId: $truckId,
                odometerKm: $dto->odometerKm,
                readOn: $dto->occurredOn->format('Y-m-d'),
                source: OdometerSource::FuelIssue,
                tripId: $trip->id,
            ));

            $cost = ($this->recordCost)($trip, new TripCostDto(
                type: TripCostType::Fuel,
                amount: $costMoney,
                rate: $rate,
                rateSource: $rateSource,
                rateDate: $rateDate,
                occurredOn: $dto->occurredOn,
                paidWith: $paidWith,
                description: $this->description($tank, $purchase, $bulkTank, $dto),
                sourceType: 'fuel_issue',
                sourceId: $issue->id,
            ), $actorId);

            if ($bulkTank !== null) {
                // The stock falls by exactly the money the ledger just credited to 1180, so the
                // account's balance and the tank's value cannot drift by a rounding cent.
                $bulkTank->recordMovement(
                    BulkTankMovementKind::Issue,
                    -$dto->litresX100,
                    -$cost->base_amount,
                    $dto->occurredOn,
                    'fuel_issue',
                    $issue->id,
                    $dto->note,
                    $actorId,
                );
            }

            $issue->forceFill([
                'trip_cost_id' => $cost->id,
            ])->save();

            $issue->moveTo(FuelIssueStatus::Posted);

            return $issue->refresh();
        }, 3);
    }

    private function assertSourceIsBuilt(FuelIssueSource $source): void
    {
        if ($source === FuelIssueSource::Kiosk) {
            throw new InvalidArgumentException('Fuel captured at the kiosk arrives with the driver\'s fuel screen (Phase 3d). Record it on the office fuel panel until then.');
        }
    }

    private function paidWith(FuelIssueSource $source): TripCostPayment
    {
        return match ($source) {
            FuelIssueSource::PumpCard => TripCostPayment::SupplierCredit,
            FuelIssueSource::Advance, FuelIssueSource::Kiosk => TripCostPayment::PettyCash,
            FuelIssueSource::BulkTank => TripCostPayment::Stock,
        };
    }

    private function description(FuelTank $tank, ?FuelPurchase $purchase, ?BulkTank $bulkTank, FuelIssueDto $dto): string
    {
        $parts = [
            number_format($dto->litresX100 / 100, 2, '.', '').' L into '.$tank->kind->label(),
            $bulkTank !== null ? 'from '.$bulkTank->name : $purchase?->station?->label(),
            $purchase?->invoice_no,
        ];

        $text = implode(' · ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));

        if ($dto->note !== null && trim($dto->note) !== '') {
            $text .= ' · '.trim($dto->note);
        }

        return mb_substr($text, 0, 160);
    }

    /**
     * §7 R7: a tank that takes more litres than it holds is a datum to question, not a write to
     * refuse — refusing would push the discrepancy into a wrong-but-accepted number. The row keeps
     * the reading and says why it looks wrong.
     */
    private function exceptionReason(FuelIssueDto $dto, FuelTank $tank): ?string
    {
        if ($dto->litresX100 <= $tank->capacity_litres * 100) {
            return null;
        }

        return sprintf(
            '%s L exceeds the %s L capacity of the %s.',
            number_format($dto->litresX100 / 100, 2, '.', ''),
            $tank->capacity_litres,
            $tank->kind->label(),
        );
    }
}
