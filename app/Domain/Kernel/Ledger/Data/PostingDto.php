<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

use App\Domain\Kernel\Money\FxRateSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The complete input to one posting (§6.3 layer 1: the only write path is
 * `LedgerService::post(PostingDto)`).
 *
 * `postedOn` is the business date that resolves the period (ADR-0001 #6) and `sourceEventId` is
 * the replay guard — a queued job that runs twice must not post twice. The entry's currency and
 * rate are here, not on the lines, because §6.1 puts the FX block on the entry: one document, one
 * input currency, one rate.
 */
final class PostingDto
{
    /**
     * @param  list<LineDto>  $lines
     */
    public function __construct(
        public readonly string $description,
        public readonly DateTimeInterface|CarbonImmutable $postedOn,
        public readonly string $currencyCode,
        public readonly string $fxRate,
        public readonly FxRateSource $fxRateSource,
        public readonly DateTimeInterface|CarbonImmutable $fxRateDate,
        public readonly array $lines,
        public readonly string $sourceType,
        public readonly string $sourceId = '',
        public readonly string $sourceEventId = '',
        public readonly ?int $createdBy = null,
        public readonly ?string $fxRateRef = null,
        public readonly ?string $reversesEntryId = null,
        public readonly ?string $nettingGroup = null,
    ) {}

    /**
     * A posting in the company's own base currency — the common case (a manual journal, a BWP
     * expense) and the reason rate 1 is spelled here rather than left to the caller.
     *
     * @param  list<LineDto>  $lines
     */
    public static function inBaseCurrency(
        string $description,
        DateTimeInterface|CarbonImmutable $postedOn,
        string $baseCurrencyCode,
        array $lines,
        string $sourceType,
        string $sourceId = '',
        string $sourceEventId = '',
        ?int $createdBy = null,
    ): self {
        return new self(
            description: $description,
            postedOn: $postedOn,
            currencyCode: $baseCurrencyCode,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $postedOn,
            lines: $lines,
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceEventId: $sourceEventId,
            createdBy: $createdBy,
        );
    }

    /** @return array{debit: int, credit: int} Minor units, in the company base currency. */
    public function totals(): array
    {
        $debit = 0;
        $credit = 0;

        foreach ($this->lines as $line) {
            if ($line->side === Side::Debit) {
                $debit += $line->base->minorUnits;
            } else {
                $credit += $line->base->minorUnits;
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }
}
