<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Post a journal that a person typed (Phase 0: "a manually posted balanced journal").
 *
 * This is the screen-facing door, and it takes flat scalars on purpose. The Livewire component
 * that collects the lines never imports {@see LineDto} or {@see PostingDto}: D3 forbids a
 * module reaching into the ledger's internals, and a component that assembles a DTO is doing
 * exactly that with a different file extension.
 */
final class PostManualJournal
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  list<array{account_id: int, direction: string, amount: int|float|string, note?: string|null}>  $lines
     */
    public function handle(
        string $description,
        DateTimeInterface|CarbonImmutable $postedOn,
        array $lines,
        int $userId,
        ?string $sourceEventId = null,
    ): JournalEntry {
        if ($lines === []) {
            throw PostingRejected::noLines();
        }

        $base = CurrentCompany::model()->base_currency_code;

        $dto = PostingDto::inBaseCurrency(
            description: $description,
            postedOn: $postedOn,
            baseCurrencyCode: $base,
            lines: $this->toLines($lines, $base),
            sourceType: 'manual_journal',
            sourceId: '',
            // A manual journal is not a replay of anything, so each submission needs its own
            // event id: with '' they would all collide on the idempotency unique index, and the
            // second journal a clerk typed would silently return the first one's entry.
            sourceEventId: $sourceEventId ?? (string) Str::ulid(),
            createdBy: $userId,
        );

        return $this->ledger->post($dto);
    }

    /**
     * @param  list<array{account_id: int, direction: string, amount: int|float|string, note?: string|null}>  $lines
     * @return list<LineDto>
     */
    private function toLines(array $lines, string $base): array
    {
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
                note: $line['note'] ?? null,
            );
        }

        return $dtos;
    }
}
