<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\BulkTankMovement;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Phase 0.5's opening fuel readings — the litres in the yard on cutover day.
 *
 * A bulk tank is the one place in this system where a quantity and a value have to agree with each
 * other: the moving average on the tank row is what prices every issue until the next delivery, and
 * `1180 Bulk fuel stock` is what the balance sheet carries. So the opening cannot be a number typed
 * into a spreadsheet column — it has to move the book *and* post the entry, in the same transaction,
 * or the first dip after cutover disagrees with a tank the ledger never heard of and the variance
 * arithmetic measures a shortage that is really an absent opening fill.
 *
 * The counterpart is `3010` Owner capital, for the same reason the opening documents credit it:
 * the diesel was bought by the old system, in the old year, and its purchase — with its input VAT and
 * its supplier — stays in those books. What comes forward is the asset.
 *
 * It is not a `receipt`. A receipt belongs to a purchase that names a supplier, an invoice number and
 * a price per litre; this row has none of those, and pretending otherwise would put a supplier on the
 * ledger for fuel nobody here bought. `opening` is the fifth kind, and the natural key is the
 * tank itself: `(source_type = 'cutover_opening', source_id = tank)` is unique on the movements table,
 * so a retried intake returns the movement it already wrote rather than filling the tank twice.
 *
 * One refusal is worth stating: litres above the tank's capacity are refused, not warned about. A
 * delivery may note an over-fill because a driver can be told to wait; an opening figure that exceeds
 * the steel in the yard is a counting error, and the accountant's sheet is the place to fix it.
 */
final class OpenBulkTankStock
{
    /** The asset the litres are. */
    private const STOCK_ACCOUNT = '1180';

    /** The other side of everything the old system leaves behind (the rule). */
    private const OPENING_EQUITY_ACCOUNT = '3010';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  int  $litresX100  the physical count, in hundredths of a litre (the scale)
     * @param  int  $valueBaseMinor  what those litres are worth, in the company's currency
     */
    public function execute(
        BulkTank $tank,
        int $litresX100,
        int $valueBaseMinor,
        DateTimeInterface $on,
        ?string $note = null,
        ?int $actorId = null,
    ): BulkTankMovement {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Opening the stock a tank starts life with needs the fuel.record ability.');
        }

        if ($litresX100 <= 0) {
            throw new InvalidArgumentException('An empty tank has nothing to open — leave it out of the file and the first delivery will fill the book.');
        }

        if ($valueBaseMinor <= 0) {
            throw new InvalidArgumentException(sprintf(
                '%s litres cannot be opened at a value of zero: the moving average prices every issue until the next delivery, '
                .'and a free litre is a trip that reports no fuel cost.',
                number_format($litresX100 / 100, 2, '.', ''),
            ));
        }

        $countedOn = Carbon::instance($on)->startOfDay();

        if ($litresX100 > $tank->capacity_litres * 100) {
            throw new InvalidArgumentException(sprintf(
                '%s litres will not fit in %s: the tank holds %s litres. Fix the count on the schedule rather than open a book '
                .'the yard cannot read.',
                number_format($litresX100 / 100, 2, '.', ''),
                $tank->name,
                number_format($tank->capacity_litres, 0, '.', ''),
            ));
        }

        // The retry check comes before everything that follows: a batch that dies on the second of
        // two tanks has already opened the first, and the only way to be re-runnable is to recognise
        // the fill it already made before asking whether the tank can accept one (the contract).
        $existing = BulkTankMovement::query()
            ->where('bulk_tank_id', $tank->id)
            ->where('source_type', 'cutover_opening')
            ->where('source_id', $tank->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($tank->stock_litres_x100 > 0) {
            throw new InvalidArgumentException(sprintf(
                '%s already holds %s litres, so this is not an opening fill — reconcile the dip instead.',
                $tank->name,
                number_format($tank->stock_litres_x100 / 100, 2, '.', ''),
            ));
        }

        $accounts = $this->chart->byCode();
        $stock = $this->account($accounts, self::STOCK_ACCOUNT);
        $equity = $this->account($accounts, self::OPENING_EQUITY_ACCOUNT);
        $baseCurrency = CurrentCompany::model()->base_currency_code;

        return DB::transaction(function () use ($tank, $litresX100, $valueBaseMinor, $countedOn, $note, $actorId, $stock, $equity, $baseCurrency): BulkTankMovement {
            $movement = $tank->recordMovement(
                BulkTankMovementKind::Opening,
                $litresX100,
                $valueBaseMinor,
                $countedOn,
                'cutover_opening',
                (string) $tank->id,
                $note,
                $actorId,
            );

            $this->ledger->post(PostingDto::inBaseCurrency(
                description: sprintf('Opening tank stock · %s · %s L', $tank->name, number_format($litresX100 / 100, 2, '.', '')),
                postedOn: $countedOn,
                baseCurrencyCode: $baseCurrency,
                lines: [
                    new LineDto(
                        accountId: $stock->id,
                        side: Side::Debit,
                        base: Money::ofMinor($valueBaseMinor, $baseCurrency),
                        note: $tank->name,
                    ),
                    new LineDto(
                        accountId: $equity->id,
                        side: Side::Credit,
                        base: Money::ofMinor($valueBaseMinor, $baseCurrency),
                        note: 'opening balance',
                    ),
                ],
                sourceType: 'bulk_tank_opening',
                sourceId: $movement->id,
                sourceEventId: 'opening-'.substr(sha1('tank:'.$tank->id), 0, 18),
                createdBy: $actorId,
            ));

            $this->audit->operational(
                aggregateType: 'bulk_tank',
                aggregateId: (string) $tank->id,
                fromState: null,
                toState: 'opened',
                reason: 'opening litres and value carried forward from the legacy system',
                context: [
                    'name' => $tank->name,
                    'litres_x100' => $litresX100,
                    'value_base' => $valueBaseMinor,
                    'counted_on' => $countedOn->toDateString(),
                ],
                actorId: $actorId,
            );

            return $movement->refresh();
        }, 3);
    }

    /**
     * @param  array<string, ChartAccount>  $accounts
     */
    private function account(array $accounts, string $code): ChartAccount
    {
        return $accounts[$code] ?? throw new InvalidArgumentException(sprintf(
            'Account %s is not in this company\'s chart, so opening stock has nowhere to be posted.',
            $code,
        ));
    }
}
