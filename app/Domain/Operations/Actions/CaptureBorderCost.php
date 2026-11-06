<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Data\BorderCostDto;
use App\Domain\Operations\Data\TripCostDto;
use App\Domain\Operations\Models\BorderCost;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\TripCostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Captures one charge at one gate and posts it, in one transaction (BORDER_COST).
 *
 * The row and the money are two records of the same act: `border_costs` says what the gate charged
 * and why, and the trip cost says what left the cash box. The order is the accounting one — the
 * crossing row is given its ULID first so its trip cost can name it as `(source_type, source_id)`,
 * then the eight columns are **copied from the posted cost** rather than converted a second
 * time. Two conversions of one amount is two chances to disagree; a copy cannot.
 *
 * Capture is allowed while the truck is still at the gate (`entered`) as well as after it left
 * (`exited`): duty and scanning are normally paid before the barrier lifts, and refusing until exit
 * would invite a false exit timestamp. A `costs_captured` crossing is sealed — a charge discovered
 * after the clerk finished belongs on the trip cost door, where it is visible as an ordinary cost
 * rather than as a hole in a reconciled gate.
 *
 * `RecordTripCost` still owns every refusal that matters here: the seat, the frozen trip, the
 * positivity of the amount, and the driver rule. This Action adds only the crossing's own state.
 */
final class CaptureBorderCost
{
    public function __construct(private readonly RecordTripCost $record) {}

    public function __invoke(BorderCrossing $crossing, BorderCostDto $dto, ?int $actorId = null): BorderCost
    {
        if (! Gate::allows('crossborder.manage')) {
            throw new InvalidArgumentException('Capturing a border charge is cross-border management.');
        }

        if (! in_array($crossing->status, [BorderCrossingStatus::Entered, BorderCrossingStatus::Exited], true)) {
            throw new InvalidArgumentException(sprintf(
                'The crossing at %s is %s. A charge is captured while the truck is at the gate or after it left, before the costs are finished.',
                $crossing->label(),
                $crossing->status->label(),
            ));
        }

        $trip = $crossing->trip
            ?? throw new InvalidArgumentException('The crossing is not attached to a trip, so there is nothing to charge it to.');

        $description = $dto->description === null || trim($dto->description) === ''
            ? $dto->kind->label()
            : trim($dto->description);

        return DB::transaction(function () use ($crossing, $dto, $actorId, $trip, $description): BorderCost {
            $id = (string) Str::ulid();

            $tripCost = ($this->record)($trip, new TripCostDto(
                type: TripCostType::Border,
                amount: $dto->amount,
                rate: $dto->rate,
                rateSource: $dto->rateSource,
                rateDate: $dto->rateDate,
                occurredOn: $dto->occurredOn,
                paidWith: $dto->paidWith,
                description: $description,
                sourceType: 'border_cost',
                sourceId: $id,
            ), $actorId);

            $cost = BorderCost::create(array_merge(
                $tripCost->only([
                    'currency_code',
                    'amount',
                    'fx_rate',
                    'fx_rate_source',
                    'fx_rate_date',
                    'fx_rate_ref',
                    'base_currency_code',
                    'base_amount',
                ]),
                [
                    'id' => $id,
                    'border_crossing_id' => $crossing->id,
                    'kind' => $dto->kind->value,
                    'description' => $description,
                    'occurred_on' => $tripCost->occurred_on,
                    'trip_cost_id' => $tripCost->id,
                    'recorded_by' => $actorId,
                ],
            ));

            return $cost->refresh();
        }, 3);
    }
}
