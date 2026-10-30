<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Kernel\Money\Fx;
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
 * - the **cost** is the purchase's own price and rate, frozen onto the issue. Deriving it later
 *   would answer with today's conversion for money posted at the slip's.
 *
 * What this Action owns is the issue's own refusals. The trip must have a truck and the tank must be
 * that truck's; the purchase must exist, be in the same company, and still have litres left on it —
 * a slip cannot dispense more than it bought. Above everything, the issue is **on a trip**: §4.3
 * allows a null `trip_id` for the bulk tank, and account 5100 requires the trip dimension (§6.1), so
 * fuel that is not on a trip has no account to post to until the bulk tank's `1310` exists in 3b.
 *
 * Bulk-tank and kiosk sources are refused by name: the tank that would own a moving average, and the
 * kiosk screen that would raise one, are later slices. A row written now would be a cost with no
 * source of truth behind it.
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

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        // The slip's price is in the purchase's currency; the issue's cost is a minor-unit figure in
        // that currency, rounded half-up once, then converted at the purchase's own rate. Two
        // roundings would let the issue and the slip disagree by a cent that compounds per litre.
        $foreignMinor = intdiv($dto->litresX100 * $purchase->price_per_litre_minor + 50, 100);

        if ($foreignMinor <= 0) {
            throw new InvalidArgumentException('That many litres at the slip\'s price is less than one minor unit, which the ledger cannot carry.');
        }

        $fx = Fx::foreignToBase(
            $foreignMinor,
            $purchase->currency_code,
            $purchase->fx_rate,
            $baseCurrency,
            $purchase->fx_rate_source,
            $purchase->fx_rate_date,
        );

        return DB::transaction(function () use ($trip, $truckId, $tank, $purchase, $dto, $actorId, $foreignMinor, $fx, $baseCurrency): FuelIssue {
            $issue = FuelIssue::create([
                'id' => (string) Str::ulid(),
                'truck_id' => $truckId,
                'fuel_tank_id' => $tank->id,
                'trip_id' => $trip->id,
                'litres_x100' => $dto->litresX100,
                'odometer_km' => $dto->odometerKm,
                'purchase_id' => $purchase->id,
                'cost_base' => $fx->baseAmount()->minorUnits,
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
                amount: Money::ofMinor($foreignMinor, $purchase->currency_code),
                rate: $purchase->fx_rate,
                rateSource: $purchase->fx_rate_source,
                rateDate: $purchase->fx_rate_date,
                occurredOn: $dto->occurredOn,
                paidWith: $this->paidWith($dto->source),
                description: $this->description($tank, $purchase, $dto),
                sourceType: 'fuel_issue',
                sourceId: $issue->id,
            ), $actorId);

            $issue->forceFill([
                'trip_cost_id' => $cost->id,
            ])->save();

            $issue->moveTo(FuelIssueStatus::Posted);

            return $issue->refresh();
        }, 3);
    }

    private function assertSourceIsBuilt(FuelIssueSource $source): void
    {
        if ($source === FuelIssueSource::BulkTank) {
            throw new InvalidArgumentException('A bulk-tank issue is Phase 3b: the tank that would own its moving-average cost has not been built yet. Record the pump-card purchase and issue from that.');
        }

        if ($source === FuelIssueSource::Kiosk) {
            throw new InvalidArgumentException('Fuel captured at the kiosk arrives with the driver\'s fuel screen (Phase 3d). Record it on the office fuel panel until then.');
        }
    }

    private function paidWith(FuelIssueSource $source): TripCostPayment
    {
        return match ($source) {
            FuelIssueSource::PumpCard => TripCostPayment::SupplierCredit,
            FuelIssueSource::Advance, FuelIssueSource::Kiosk => TripCostPayment::PettyCash,
            FuelIssueSource::BulkTank => TripCostPayment::SupplierCredit,
        };
    }

    private function description(FuelTank $tank, FuelPurchase $purchase, FuelIssueDto $dto): string
    {
        $parts = [
            number_format($dto->litresX100 / 100, 2, '.', '').' L into '.$tank->kind->label(),
            $purchase->station?->label(),
            $purchase->invoice_no,
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
