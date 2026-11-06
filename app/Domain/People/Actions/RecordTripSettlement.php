<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Puts the settlement sheet on the trip (the `TripSettled` paper half — no posting yet).
 *
 * The four numbers arrive as the accountant's inputs: the screen pre-fills them from the trip's
 * advance rows, its petty-cash costs and the driver's daily rate, but *this Action does not
 * recompute them from Operations* — People may not read across the module map backwards (
 * draws OPS → PEOPLE), and a settlement whose figures were silently re-derived at signing would
 * be a number nobody typed and nobody signed. What the Action does enforce is the arithmetic a
 * signature must not break: cleared money (expenses the advance funded + cash returned) can
 * never exceed the advance issued, and the outstanding receivable is exactly what is left.
 *
 * One live sheet per trip: the retry path is void the open sheet and record a new one — the
 * live-row unique index is the data-level backstop of this refusal.
 */
final class RecordTripSettlement
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        string $tripId,
        int $driverId,
        int $advanceTotal,
        int $expensesCovered,
        int $cashReturned,
        int $allowance,
        ?string $notes = null,
        ?int $actorId = null,
    ): TripSettlement {
        if (! Gate::allows('record', TripSettlement::class)) {
            throw new InvalidArgumentException('Recording a settlement needs the payroll.manage ability.');
        }

        if ($advanceTotal < 0 || $expensesCovered < 0 || $cashReturned < 0 || $allowance < 0) {
            throw new InvalidArgumentException('Settlement figures are positive amounts or zero — a negative line belongs on the other side of the sheet.');
        }

        if ($advanceTotal + $allowance === 0 && $expensesCovered + $cashReturned === 0) {
            throw new InvalidArgumentException('A settlement of nothing settles nothing — every figure is zero.');
        }

        if ($expensesCovered + $cashReturned > $advanceTotal) {
            throw new InvalidArgumentException(sprintf(
                'The advance issued %s minor units, but the sheet clears %s — money the driver never held cannot be covered or returned.',
                $advanceTotal,
                $expensesCovered + $cashReturned,
            ));
        }

        if ($advanceTotal > 0 && $expensesCovered + $cashReturned === 0 && $allowance === 0) {
            throw new InvalidArgumentException('An advance with nothing cleared and no allowance is not a settlement — it is the same unreconciled cash the cutover refuses.');
        }

        $live = TripSettlement::query()
            ->where('trip_id', $tripId)
            ->where('status', '!=', TripSettlementStatus::Void->value)
            ->exists();

        if ($live) {
            throw new InvalidArgumentException('This trip already has a live settlement — void it to redo the sheet, or settle it to post it.');
        }

        return DB::transaction(function () use ($tripId, $driverId, $advanceTotal, $expensesCovered, $cashReturned, $allowance, $notes, $actorId): TripSettlement {
            $reference = $this->numbers->allocate('settlement', now(), $actorId);

            $settlement = new TripSettlement([
                'id' => (string) Str::ulid(),
                'reference' => $reference,
                'status' => TripSettlementStatus::Open->value,
                'trip_id' => $tripId,
                'driver_id' => $driverId,
                'advance_total' => $advanceTotal,
                'expenses_covered' => $expensesCovered,
                'cash_returned' => $cashReturned,
                'outstanding_minor' => $advanceTotal - $expensesCovered - $cashReturned,
                'allowance' => $allowance,
                'notes' => $notes,
                'recorded_by' => $actorId,
            ]);

            $settlement->save();

            $this->audit->operational(
                'trip_settlement',
                $settlement->id,
                null,
                TripSettlementStatus::Open->value,
                reason: "sheet {$reference} recorded: advance {$advanceTotal}, covered {$expensesCovered}, returned {$cashReturned}, allowance {$allowance}",
                actorId: $actorId,
            );

            return $settlement;
        });
    }
}
