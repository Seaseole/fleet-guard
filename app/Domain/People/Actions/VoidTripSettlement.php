<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Actions\ReversePosting;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Discards a settlement sheet — and if it had been signed, reverses its journal first (the
 * correction rule, the same discipline the reconciliation voids and the cancelled
 * disbursements follow). An open sheet just goes; a settled one leaves both entries standing,
 * netted by the same `trip_settlement/{id}` source pair so a report can see the pair cancel.
 *
 * The reason is mandatory: a settlement that disappears without a sentence is the hole an
 * auditor finds first, and the sheet keeps its numbers either way — void is a state, not an
 * delete.
 */
final class VoidTripSettlement
{
    public function __construct(
        private readonly ReversePosting $reverse,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(TripSettlement $settlement, string $reason, ?int $actorId = null): TripSettlement
    {
        if (! Gate::allows('void', $settlement)) {
            throw new InvalidArgumentException($settlement->status === TripSettlementStatus::Void
                ? 'The sheet is already void.'
                : 'Voiding a settlement needs the payroll.manage ability.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A void needs its sentence.');
        }

        $wasSettled = $settlement->status === TripSettlementStatus::Settled;

        if ($wasSettled) {
            ($this->reverse)(
                originalSourceType: 'trip_settlement',
                originalSourceId: $settlement->id,
                sourceType: 'trip_settlement_reversal',
                sourceId: $settlement->id,
                // netting_group is char(26): the sheet's own id is the group — the pair the
                // reports can net to zero is exactly original + mirror for this one document.
                nettingGroup: $settlement->id,
                description: "Void of settlement {$settlement->reference}",
                actorId: $actorId,
            );
        }

        $from = $settlement->status->value;

        $settlement->forceFill(['status' => TripSettlementStatus::Void->value])->save();

        $this->audit->operational('trip_settlement', $settlement->id, $from, TripSettlementStatus::Void->value, reason: $reason, actorId: $actorId);

        return $settlement;
    }
}
