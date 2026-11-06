<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Models\ReconcilingItem;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Accounting\ReconcilingItemDirection;
use App\Domain\Accounting\ReconcilingItemKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adds one reconciling line to an open run (Phase 5, slice 5d): the deposit the bank has
 * not processed, the cheque that has not cleared, the monthly charge nobody recorded.
 *
 * The direction is a stored choice, not a derivation from the kind, because "deposit in
 * transit" and "book error" can both run either way in real books, and a sign the code guesses
 * wrong about is a reconciliation that agrees by accident. Items live only while the run is
 * open: an approved run is a signed document and a voided one is evidence — neither edits.
 */
final class AddReconcilingItem
{
    public function execute(
        Reconciliation $reconciliation,
        ReconcilingItemKind $kind,
        ReconcilingItemDirection $direction,
        int $amountMinor,
        ?string $reference = null,
        ?string $note = null,
        Carbon|string|null $onDate = null,
        ?int $actorId = null,
    ): ReconcilingItem {
        if (! Gate::allows('mutate', $reconciliation)) {
            throw new InvalidArgumentException($reconciliation->status === ReconciliationStatus::Open
                ? 'Editing this run needs the bank.reconcile ability.'
                : 'A signed or voided run is not edited — void it and start a new one (ADR-0001\'s rule, one layer up).');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A reconciling line is a positive amount; the direction column carries the sign.');
        }

        $item = new ReconcilingItem([
            'id' => (string) Str::ulid(),
            'reconciliation_id' => $reconciliation->id,
            'kind' => $kind->value,
            'direction' => $direction->value,
            'currency_code' => $reconciliation->currency_code,
            'amount' => $amountMinor,
            'base_amount' => $amountMinor,
            'on_date' => $onDate === null ? null : Carbon::parse($onDate)->startOfDay()->toDateString(),
            'reference' => $reference,
            'note' => $note,
            'entered_by' => $actorId,
        ]);

        $item->save();

        return $item;
    }
}
