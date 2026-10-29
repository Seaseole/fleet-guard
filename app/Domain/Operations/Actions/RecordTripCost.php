<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Data\TripCostDto;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\Support\TripCostRollup;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record one direct cost against a trip, and post it, and move the projection — in one transaction.
 *
 * The three writes are one act on purpose (§6.6): a cost row without its journal entry is a screen
 * that disagrees with the ledger, and an entry whose trip projection was not refreshed is a
 * dashboard that answers yesterday's question. The transaction boundary is the sentence "the money
 * is recorded", and it either holds all of it or none.
 *
 * Two refusals carry most of the design. A **frozen** trip (`closed`) is final: §10.2 freezes costs
 * so a settled month cannot be edited from a trip screen, and the only way to correct one is to
 * re-open the trip or post a reversing entry. A cost whose account needs a driver on a trip with
 * none named is refused before the row is written, because §6.1's dimension rule would refuse it
 * at the ledger anyway — better to say why on the form than as a posting error.
 */
final class RecordTripCost
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TripCostRollup $rollup,
    ) {}

    public function __invoke(Trip $trip, TripCostDto $dto, ?int $actorId = null): TripCost
    {
        if (! Gate::allows('expenses.create')) {
            throw new InvalidArgumentException('Recording a trip cost is an expense, and this seat may not raise one (§9.3).');
        }

        if ($trip->costs_frozen || in_array($trip->status, [TripStatus::Cancelled, TripStatus::Aborted], true)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s and its costs are final. Re-open the trip, or correct the ledger with a reversing entry (§10.2).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        if ($dto->amount->minorUnits <= 0) {
            throw new InvalidArgumentException('A trip cost must be a positive amount.');
        }

        if ($dto->description !== null && mb_strlen($dto->description) > 160) {
            throw new InvalidArgumentException('Keep the cost description to 160 characters — it is a label, not the invoice.');
        }

        // A retried capture names the row it belongs to; the unique pair on the table is the real
        // guard, but returning the first one here keeps the common retry free of an exception.
        if ($dto->sourceType !== null && $dto->sourceId !== null) {
            $existing = TripCost::query()
                ->where('source_type', $dto->sourceType)
                ->where('source_id', $dto->sourceId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

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
                '%s at the stated rate converts to less than one %s minor unit, which the ledger cannot carry.',
                $dto->amount->format($baseCurrency),
                $baseCurrency,
            ));
        }

        $this->assertDriverPresent($trip, $dto->type);

        $debit = $this->account($dto->type->accountCode());
        $credit = $this->account($dto->paidWith->accountCode());

        $dimensions = array_filter([
            'truck_id' => $trip->truck_id,
            'trip_id' => $trip->id,
            'job_id' => $trip->job_id,
            'driver_id' => $trip->driver_id,
        ], static fn (mixed $value): bool => $value !== null);

        $foreign = $fx->foreignAmount()->currencyCode === $baseCurrency ? null : $fx->foreignAmount();

        return DB::transaction(function () use ($trip, $dto, $actorId, $fx, $debit, $credit, $dimensions, $foreign): TripCost {
            $cost = TripCost::create(array_merge($fx->toColumns(), [
                'id' => (string) Str::ulid(),
                'trip_id' => $trip->id,
                'cost_type' => $dto->type->value,
                'paid_with' => $dto->paidWith->value,
                'description' => $dto->description === null || trim($dto->description) === '' ? null : trim($dto->description),
                'source_type' => $dto->sourceType,
                'source_id' => $dto->sourceId,
                'occurred_on' => Carbon::instance($dto->occurredOn),
                'recorded_by' => $actorId,
            ]));

            $this->ledger->post(new PostingDto(
                description: $this->description($trip, $dto),
                postedOn: $dto->occurredOn,
                currencyCode: $fx->foreignAmount()->currencyCode,
                fxRate: $fx->rate,
                fxRateSource: $fx->source,
                fxRateDate: $fx->rateDate,
                lines: [
                    new LineDto(
                        accountId: $debit->id,
                        side: Side::Debit,
                        base: $fx->baseAmount(),
                        foreign: $foreign,
                        note: $cost->description,
                        dimensions: $dimensions,
                    ),
                    new LineDto(
                        accountId: $credit->id,
                        side: Side::Credit,
                        base: $fx->baseAmount(),
                        foreign: $foreign,
                        note: $dto->paidWith->label(),
                        dimensions: ['trip_id' => $trip->id, 'job_id' => $trip->job_id],
                    ),
                ],
                sourceType: 'trip_cost',
                sourceId: $cost->id,
                createdBy: $actorId,
                fxRateRef: $fx->ref,
            ));

            // Refreshed so the rollup reads the trip as the schema has it, and so a caller receives
            // the projection that now includes this cost.
            $this->rollup->write($trip->refresh());

            return $cost->refresh();
        }, 3);
    }

    private function assertDriverPresent(Trip $trip, TripCostType $type): void
    {
        if ($type->requiresDriver() && $trip->driver_id === null) {
            throw new InvalidArgumentException(sprintf(
                'A %s cost posts against the driver and %s has none named. Name the driver on the trip first (§6.1).',
                mb_strtolower($type->label()),
                $trip->trip_no,
            ));
        }
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function description(Trip $trip, TripCostDto $dto): string
    {
        $label = sprintf('Trip %s · %s', $trip->trip_no, $dto->type->label());

        return $dto->description === null || trim($dto->description) === ''
            ? $label
            : $label.' · '.trim($dto->description);
    }
}
