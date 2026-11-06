<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\JournalLine;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The correction, behind the ledger door: a posted entry is mirrored from its own lines, never
 * edited or deleted.
 *
 * It lives in the Ledger rather than in the module that corrects something because reading
 * `gl_journal_lines` is exactly the internals D3 keeps outside modules. The caller hands over the
 * entry it is correcting and the source triple for the mirror; this class decides the sides, the
 * date and the reference (opposite sides, `reverses_entry_id`, posted today), and the
 * dimensions travel with each line so the mirror lands in the same truck/trip buckets the original
 * did — a reversal that lost its trip would net the account but leave the trip reading a cost that
 * no longer exists.
 */
final class ReversePosting
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function __invoke(
        string $originalSourceType,
        string $originalSourceId,
        string $sourceType,
        string $sourceId,
        string $nettingGroup,
        string $description,
        ?int $actorId = null,
    ): JournalEntry {
        $original = JournalEntry::query()
            ->where('source_type', $originalSourceType)
            ->where('source_id', $originalSourceId)
            ->where('status', JournalEntry::Posted)
            ->first()
            ?? throw new InvalidArgumentException('The journal entry to reverse is missing or no longer posted, so there is nothing to mirror.');

        $base = CurrentCompany::model()->base_currency_code;
        $today = Carbon::now(CurrentCompany::timezone());

        return $this->ledger->post(new PostingDto(
            description: mb_substr($description, 0, 255),
            postedOn: $today,
            currencyCode: $base,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $today,
            lines: $this->mirror($original, $base),
            sourceType: $sourceType,
            sourceId: $sourceId,
            createdBy: $actorId,
            reversesEntryId: $original->id,
            nettingGroup: $nettingGroup,
        ));
    }

    /**
     * @return list<LineDto>
     */
    private function mirror(JournalEntry $original, string $base): array
    {
        return array_values($original->lines()->get()->map(function (JournalLine $line) use ($base): LineDto {
            $dimensions = [];

            foreach (LineDto::typedColumns() as $column) {
                $value = $line->getAttribute($column);

                if ($value !== null && $value !== '') {
                    $dimensions[$column] = $value;
                }
            }

            return new LineDto(
                accountId: (int) $line->account_id,
                side: $line->direction === Side::Debit ? Side::Credit : Side::Debit,
                base: Money::ofMinor((int) $line->amount_base, $base),
                note: $line->line_note === null ? 'Reversal' : mb_substr('Reversal: '.$line->line_note, 0, 255),
                dimensions: $dimensions,
                dimensionExtra: $line->dimension_extra ?? [],
            );
        })->all());
    }
}
