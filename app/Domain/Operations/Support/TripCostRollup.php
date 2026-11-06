<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripCostBucket;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The write-time projection: what one trip has earned and spent, read from the ledger itself.
 *
 * The rollup is **derived, never incremented**. `RecordTripCost` writes it inside the same
 * transaction that posts the entry, and `trips:recost` recomputes it later to prove the stored
 * figure still describes the lines — so a cost settled outside the trip screen (a fuel issue in
 * Phase 3, a border crossing in 2f) cannot leave the dashboard behind. Adding to a counter would
 * need every future posting path to remember to do it; reading the lines needs no such promise.
 *
 * Only ledger lines carrying this `trip_id` are counted, which is exactly the dimension the design makes
 * mandatory on every direct-cost account. An account the bucket map does not know is ignored rather
 * than folded into "other", because a new account silently joining a report is how a cost bucket
 * stops meaning what its label says.
 */
final class TripCostRollup
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{revenue_base: int, buckets: array<string, int>, direct_cost_base: int, contribution_base: int, line_count: int}
     */
    public function compute(Trip $trip): array
    {
        // The lines are read through the ledger's own door (D3), not its tables: The design is derived from
        // the ledger, and a projection that queries the line table itself is a second way in.
        $rows = $this->ledger->totalsByAccount(['trip_id' => $trip->id]);

        $revenue = 0;

        /*
         * Turnover arrives grouped by account *and* direction, but a cost is a net figure: a
         * reversal posts the mirror line — a credit on 5100 carrying the same trip — and
         * reading the two halves separately called every corrected cost a refund. The halves are
         * summed here before the sign is judged, so a mirrored pair nets to zero and only a trip
         * genuinely credited more than it was charged is refused below.
         *
         * @var array<string, int> $signed
         */
        $signed = [];

        foreach ($rows as $row) {
            $signed[$row->accountCode] = ($signed[$row->accountCode] ?? 0) + $row->signedDebit();
        }

        /** @var array<string, int> $buckets */
        $buckets = array_fill_keys(TripCostBucket::values(), 0);

        foreach ($signed as $code => $amount) {
            // PHP turns "5100" into an int array key; the chart's codes are strings.
            $code = (string) $code;

            if (str_starts_with($code, '4')) {
                $revenue -= $amount;

                continue;
            }

            $bucket = TripCostBucket::forAccountCode($code);

            if ($bucket === null) {
                continue;
            }

            if ($amount < 0) {
                throw new RuntimeException(sprintf(
                    'Trip %s has a net credit of %d on account %s (%s). A refund belongs in a reversing '
                    .'entry against a receivable or a contra account, not as a negative direct cost.',
                    $trip->trip_no,
                    -$amount,
                    $code,
                    $bucket->label(),
                ));
            }

            $buckets[$bucket->value] += $amount;
        }

        $direct = array_sum($buckets);

        return [
            'revenue_base' => $revenue,
            'buckets' => $buckets,
            'direct_cost_base' => $direct,
            'contribution_base' => $revenue - $direct,
            'line_count' => count($rows),
        ];
    }

    /**
     * Recompute and store. Called inside the posting transaction so the projection and the entry it
     * describes commit together, or not at all.
     *
     * @return array{revenue_base: int, buckets: array<string, int>, direct_cost_base: int, contribution_base: int, line_count: int}
     */
    public function write(Trip $trip): array
    {
        $totals = $this->compute($trip);

        $columns = [
            'revenue_base' => $totals['revenue_base'],
            'direct_cost_base' => $totals['direct_cost_base'],
            'contribution_base' => $totals['contribution_base'],
            // The moment the rollup last saw the ledger, not when the trip last changed. With no
            // lines yet it stays null: "never posted" and "posted at zero" are different facts.
            'ledger_posted_at' => $totals['line_count'] > 0 ? Carbon::now() : null,
        ];

        foreach (TripCostBucket::cases() as $bucket) {
            $columns[$bucket->column()] = $totals['buckets'][$bucket->value];
        }

        // forceFill because the rollup columns are the projection's own columns: no screen may
        // mass-assign them, and the only writer is this class inside a transaction.
        $trip->forceFill($columns)->save();

        return $totals;
    }
}
