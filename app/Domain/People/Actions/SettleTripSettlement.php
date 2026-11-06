<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The signature — the `TripSettled` posting, one balanced entry per settlement sheet:
 *
 *   Dr 1110 Petty cash        (expenses_covered + cash_returned) — the cash the box really has:
 *                             the returned notes, *and* the diesel the advance bought that the
 *                             kiosk slip had provisionally credited against the office's own
 *                             till (the "office reconciles it later" — this is the later).
 *   Cr 1160 Driver advances   (same amount, driver dimension) — the receivable those notes
 *                             were: cleared because they are now provably spent or back.
 *   Dr 5200 Driver wages      (allowance, driver + trip dimensions) — what the trip earned the
 *   Cr 2140 Driver settlements (allowance, driver dimension)          driver, now owed by the book.
 *
 * The outstanding advance the sheet could not clear stays on 1160 — a real receivable against a
 * driver who still holds company cash, which is exactly what the cutover dataset calls
 * "unreconciled advances" and the question about cash-vs-card advances will one day settle
 * differently. Nothing here pretends the remainder away.
 *
 * Deviation from the row, stated as the did: the table names `5300`/`1250`/`2211`,
 * which COA v1 does not carry (5300 is Maintenance, 1250 does not exist, 2211 is not the driver
 * payable). 5200/1160/2140 are the accounts the chart actually has, and they are what the
 * dimension masks require on these lines.
 */
final class SettleTripSettlement
{
    private const PETTY_CASH_ACCOUNT = '1110';

    private const ADVANCES_ACCOUNT = '1160';

    private const WAGES_ACCOUNT = '5200';

    private const SETTLEMENTS_ACCOUNT = '2140';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(TripSettlement $settlement, ?int $actorId = null): TripSettlement
    {
        if (! Gate::allows('settle', $settlement)) {
            throw new InvalidArgumentException($settlement->status === TripSettlementStatus::Open
                ? 'Signing a settlement needs the payroll.manage ability.'
                : 'Only an open settlement can be settled.');
        }

        $cleared = $settlement->clearedMinor();
        $allowance = $settlement->allowance;

        if ($cleared === 0 && $allowance === 0) {
            throw new InvalidArgumentException('Nothing to post: no cleared money and no allowance.');
        }

        $accounts = $this->chart->byCode();
        $base = CurrentCompany::model()->base_currency_code;

        $petty = $accounts[self::PETTY_CASH_ACCOUNT] ?? null;
        $advances = $accounts[self::ADVANCES_ACCOUNT] ?? null;
        $wages = $accounts[self::WAGES_ACCOUNT] ?? null;
        $settlements = $accounts[self::SETTLEMENTS_ACCOUNT] ?? null;

        // Named refusals rather than a loop: a loop over `[code => ?account]` narrows nothing for
        // the analysis below, and four lines of the same three sentences is cheaper than a cast.
        foreach ([self::PETTY_CASH_ACCOUNT, self::ADVANCES_ACCOUNT, self::WAGES_ACCOUNT, self::SETTLEMENTS_ACCOUNT] as $code) {
            if (($accounts[$code] ?? null) === null) {
                throw new InvalidArgumentException("Account {$code} is not in this company's chart; the settlement cannot post against a code that does not exist.");
            }
        }

        if ($petty === null || $advances === null || $wages === null || $settlements === null) {
            throw new InvalidArgumentException('Chart lookups disagreed with themselves — nothing posted.');
        }

        return DB::transaction(function () use ($settlement, $cleared, $allowance, $petty, $advances, $wages, $settlements, $base, $actorId): TripSettlement {
            $lines = [];

            if ($cleared > 0) {
                $lines[] = new LineDto($petty->id, Side::Debit, Money::ofMinor($cleared, $base), note: 'advance reconciled: spent and returned', dimensions: ['trip_id' => $settlement->trip_id]);
                $lines[] = new LineDto($advances->id, Side::Credit, Money::ofMinor($cleared, $base), note: 'advance cleared', dimensions: ['trip_id' => $settlement->trip_id, 'driver_id' => $settlement->driver_id]);
            }

            if ($allowance > 0) {
                $lines[] = new LineDto($wages->id, Side::Debit, Money::ofMinor($allowance, $base), note: 'trip allowance', dimensions: ['trip_id' => $settlement->trip_id, 'driver_id' => $settlement->driver_id]);
                $lines[] = new LineDto($settlements->id, Side::Credit, Money::ofMinor($allowance, $base), note: 'owed to driver', dimensions: ['trip_id' => $settlement->trip_id, 'driver_id' => $settlement->driver_id]);
            }

            $entry = $this->ledger->post(PostingDto::inBaseCurrency(
                description: "Settlement {$settlement->reference} settled",
                postedOn: now(CurrentCompany::timezone()),
                baseCurrencyCode: $base,
                lines: $lines,
                sourceType: 'trip_settlement',
                sourceId: $settlement->id,
                sourceEventId: 'settled',
                createdBy: $actorId,
            ));

            $settlement->forceFill([
                'status' => TripSettlementStatus::Settled->value,
                'entry_id' => $entry->id,
                'settled_by' => $actorId,
                'settled_at' => now(),
            ])->save();

            $this->audit->operational(
                'trip_settlement',
                $settlement->id,
                TripSettlementStatus::Open->value,
                TripSettlementStatus::Settled->value,
                reason: "cleared {$cleared}, allowance {$allowance}, outstanding advance {$settlement->outstanding_minor}",
                context: ['entry_no' => $entry->entry_no],
                actorId: $actorId,
            );

            return $settlement;
        });
    }
}
