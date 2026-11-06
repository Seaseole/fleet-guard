<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Phase 0.5's unreconciled advances — cash a driver holds that the fleet has not settled.
 *
 * `1160 Driver advances receivable` is a real asset: diesel bought with float that was never returned,
 * tolls the driver fronted from the advance, a slip the old system paid and never closed. The trial
 * balance can carry the *total* — and the validator refuses that line, because the account demands
 * a driver. So the advance arrives per driver, one entry per slip, with the driver dimension the
 * account insists on.
 *
 * **The money side is not the interesting part; the counterpart is.** An advance paid before cutover left
 * the old system's bank in the old year, so its other side is not `1110 Petty cash` — the cash on the
 * balance sheet already reflects that it is gone. `Cr 3010` Owner capital is the opening entry
 * and the design use for everything that arrives already-half-recorded: the asset exists, its expenditure
 * does not belong to this year's profit-and-loss.
 *
 * There is **no advance row** created here, and that is the honest shape rather than a shortcut: every
 * advance this application models belongs to a trip — `TripCostType::Advance` posts one against a leg,
 * and the settlement desk is the only door that clears one. A legacy slip has no leg in this
 * system, and inventing a trip to hang it on would put a phantom delivery in the fleet's own history —
 * worse than a receivable the ledger names and no desk shows. What the ledger does keep is the natural
 * key: the entry's `(source_type, source_id, source_event_id)` is the driver and the slip number, so a
 * retried file cannot put the same cash on the books twice (the replay guard doing the work of a
 * document row).
 *
 * **Two dates, and only one of them is the posting day** (borrowing the decision 2). The
 * slip's own day is the fact the accountant wrote — it travels in the description, the line note and
 * the audit context, and it decides which driver's story the slip belongs to. The entry is struck on
 * **cutover day**, because an opening balance is a statement about today's book: posting it backwards
 * would reach into a period the ledger may already have closed and would move cash this year's
 * balance sheet no longer has.
 */
final class RecordOpeningAdvance
{
    private const ADVANCES_ACCOUNT = '1160';

    private const OPENING_EQUITY_ACCOUNT = '3010';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        int $driverId,
        int $amountMinor,
        DateTimeInterface $advancedOn,
        string $legacySlipNumber,
        ?string $note = null,
        ?int $actorId = null,
        ?DateTimeInterface $postedOn = null,
    ): string {
        if (! Gate::allows('payroll.manage')) {
            throw new InvalidArgumentException('Carrying a driver\'s advance onto the ledger needs the payroll.manage ability.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An advance is money a driver holds; a zero or negative figure is a settlement, and the desk is where that belongs.');
        }

        $slip = trim($legacySlipNumber);

        if ($slip === '' || mb_strlen($slip) > 40) {
            throw new InvalidArgumentException('The legacy slip number is the natural key of an advance row — present, and no longer than 40 characters.');
        }

        $driver = Driver::query()->find($driverId)
            ?? throw new InvalidArgumentException('No driver in this company\'s register carries that id, so the receivable has nobody to stand against.');

        $employee = Employee::query()->find($driver->employee_id);

        // A driver whose employee row has gone is a register missing a page, not a reason to refuse
        // the money: the receivable belongs to the driver, and the label only has to say who.
        $driverLabel = $employee === null ? 'driver '.$driver->id : (string) $employee->name;

        $advancedOnDay = Carbon::instance($advancedOn)->startOfDay();
        $struckOn = Carbon::instance($postedOn ?? Carbon::now(CurrentCompany::timezone()))->startOfDay();
        $baseCurrency = CurrentCompany::model()->base_currency_code;

        // char(26) total: 'adv-' plus the slip's hash prefix is 23, which separates one slip from
        // another without pretending the hash is a document id (the replay key has to fit the column).
        $eventKey = 'adv-'.substr(sha1(mb_strtolower($slip)), 0, 19);

        $accounts = $this->chart->byCode();
        $advances = $this->account($accounts, self::ADVANCES_ACCOUNT);
        $equity = $this->account($accounts, self::OPENING_EQUITY_ACCOUNT);

        $posting = PostingDto::inBaseCurrency(
            description: sprintf('Opening advance · %s · slip %s · paid %s', $driverLabel, $slip, $advancedOnDay->toDateString()),
            postedOn: $struckOn,
            baseCurrencyCode: $baseCurrency,
            lines: [
                new LineDto(
                    accountId: $advances->id,
                    side: Side::Debit,
                    base: Money::ofMinor($amountMinor, $baseCurrency),
                    note: sprintf('%s · paid %s', $slip, $advancedOnDay->toDateString()),
                    dimensions: ['driver_id' => $driver->id],
                ),
                new LineDto(
                    accountId: $equity->id,
                    side: Side::Credit,
                    base: Money::ofMinor($amountMinor, $baseCurrency),
                    note: 'opening balance',
                    dimensions: ['driver_id' => $driver->id],
                ),
            ],
            sourceType: 'cutover_advance',
            sourceId: (string) $driver->id,
            sourceEventId: $eventKey,
            createdBy: $actorId,
        );

        // The replay question goes to the one door that answers it (D3): `findPosted` matches on
        // the source triple — driver plus slip — which is the natural key of the file's row, so a
        // retried batch returns the entry it already made instead of claiming the cash twice.
        $already = $this->ledger->findPosted($posting);

        if ($already !== null) {
            return (string) $already->id;
        }

        return DB::transaction(function () use ($driver, $slip, $note, $amountMinor, $advancedOnDay, $struckOn, $posting, $actorId): string {
            $entry = $this->ledger->post($posting);

            $this->audit->operational(
                aggregateType: 'driver_advance',
                aggregateId: $driver->id.'/'.$slip,
                fromState: null,
                toState: 'opened',
                reason: $note === null || trim($note) === ''
                    ? 'unreconciled advance carried forward from the legacy system'
                    : mb_substr(trim($note), 0, 250),
                context: [
                    'driver_id' => $driver->id,
                    'slip' => $slip,
                    'amount' => $amountMinor,
                    'advanced_on' => $advancedOnDay->toDateString(),
                    'posted_on' => $struckOn->toDateString(),
                    'entry_no' => $entry->entry_no,
                ],
                actorId: $actorId,
            );

            return (string) $entry->id;
        }, 3);
    }

    /**
     * @param  array<string, ChartAccount>  $accounts
     */
    private function account(array $accounts, string $code): ChartAccount
    {
        return $accounts[$code] ?? throw new InvalidArgumentException(sprintf(
            'Account %s is not in this company\'s chart, so an opening advance has nowhere to be posted.',
            $code,
        ));
    }
}
