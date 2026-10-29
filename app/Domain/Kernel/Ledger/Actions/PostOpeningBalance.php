<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The ledger door for §15.2's opening trial balance and its rehearsal rollback.
 *
 * It lives in the Ledger rather than in Cutover because assembling a `LineDto` is exactly the
 * ledger internals D3 keeps outside modules. Cutover hands over flat scalars and the source triple;
 * this class is the only place that knows the opening-balance source names and how a reversal is
 * spelled (§6.4: opposite sides, a reference to the original, today's date).
 */
final class PostOpeningBalance
{
    public const SOURCE_TYPE = 'opening_balance';

    public const REVERSAL_SOURCE_TYPE = 'opening_balance_reversal';

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  list<array{account_id: int, direction: string, amount: int|string, note?: string|null}>  $lines
     */
    public function post(
        string $description,
        CarbonImmutable $postedOn,
        array $lines,
        string $sourceId,
        string $sourceEventId,
        ?int $userId,
    ): JournalEntry {
        return $this->write(
            sourceType: self::SOURCE_TYPE,
            description: $description,
            postedOn: $postedOn,
            lines: $lines,
            sourceId: $sourceId,
            sourceEventId: $sourceEventId,
            userId: $userId,
        );
    }

    /**
     * §6.4: a posted set is corrected by a mirror entry, never by editing it. The mirror carries
     * `reverses_entry_id` and the batch's `netting_group`, so a report can net the pair to zero
     * without knowing which rows the accountant changed.
     *
     * @param  list<array{account_id: int, direction: string, amount: int|string, note?: string|null}>  $lines
     */
    public function reverse(
        string $description,
        CarbonImmutable $postedOn,
        array $lines,
        string $sourceId,
        string $sourceEventId,
        ?int $userId,
        string $reversesEntryId,
        string $nettingGroup,
    ): JournalEntry {
        return $this->write(
            sourceType: self::REVERSAL_SOURCE_TYPE,
            description: $description,
            postedOn: $postedOn,
            lines: $this->swap($lines),
            sourceId: $sourceId,
            sourceEventId: $sourceEventId,
            userId: $userId,
            reversesEntryId: $reversesEntryId,
            nettingGroup: $nettingGroup,
        );
    }

    /**
     * @param  list<array{account_id: int, direction: string, amount: int|string, note?: string|null}>  $lines
     */
    private function write(
        string $sourceType,
        string $description,
        CarbonImmutable $postedOn,
        array $lines,
        string $sourceId,
        string $sourceEventId,
        ?int $userId,
        ?string $reversesEntryId = null,
        ?string $nettingGroup = null,
    ): JournalEntry {
        if ($lines === []) {
            throw PostingRejected::noLines();
        }

        $base = CurrentCompany::model()->base_currency_code;
        $dtos = [];

        foreach ($lines as $line) {
            $amount = $line['amount'];

            if (is_float($amount)) {
                throw PostingRejected::floatAmount($amount);
            }

            $dtos[] = new LineDto(
                accountId: (int) $line['account_id'],
                side: Side::from(strtolower($line['direction'])),
                base: Money::ofMinor(is_string($amount) ? $amount : (int) $amount, $base),
                note: isset($line['note']) && $line['note'] !== '' ? Str::limit($line['note'], 255, '') : null,
            );
        }

        return $this->ledger->post(new PostingDto(
            description: Str::limit($description, 255, ''),
            postedOn: $postedOn,
            currencyCode: $base,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $postedOn,
            lines: $dtos,
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceEventId: $sourceEventId,
            createdBy: $userId,
            reversesEntryId: $reversesEntryId,
            nettingGroup: $nettingGroup,
        ));
    }

    /**
     * @param  list<array{account_id: int, direction: string, amount: int|string, note?: string|null}>  $lines
     * @return list<array{account_id: int, direction: string, amount: int|string, note?: string|null}>
     */
    private function swap(array $lines): array
    {
        return array_map(function (array $line): array {
            $note = $line['note'] ?? null;

            return [
                'account_id' => $line['account_id'],
                'direction' => strtolower($line['direction']) === 'debit' ? 'credit' : 'debit',
                'amount' => $line['amount'],
                'note' => $note === null ? 'Reversal' : Str::limit("Reversal: {$note}", 255, ''),
            ];
        }, $lines);
    }
}
