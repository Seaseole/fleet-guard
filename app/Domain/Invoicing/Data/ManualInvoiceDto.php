<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Invoicing\Actions\IssueManualInvoice;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What the office types when it raises a document that has no delivery behind it (phase 4's
 * manual door, `InvoiceKind::Manual`).
 *
 * The shape is deliberately small: a customer, lines, one currency, and the note. Everything else —
 * the number, the VAT, the posting, the due date — is decided at issue by
 * {@see IssueManualInvoice}, because the rule is that a draft has
 * no fiscal existence yet.
 *
 * **Currency.** A manual document may be raised in a corridor currency, and the desk then
 * states the conversion the same two ways ADR-0004 #6 allows everywhere: type the rate, or type the
 * base the money is worth to this company. Both freeze at draft time — each line is written with its
 * own `base_amount`, so editing the document later never re-reads a rate feed. When neither
 * is typed, the Action supplies the stored bulletin rate and names its source; nothing here invents
 * a rate silently.
 */
final readonly class ManualInvoiceDto
{
    /**
     * @param  list<ManualInvoiceLineDto>  $lines
     */
    public function __construct(
        public int $customerId,
        public array $lines,
        public string $currencyCode = '',
        public ?string $fxRate = null,
        public ?int $baseAmountMinor = null,
        public string $fxRateSource = FxRateSource::Manual->value,
        public ?string $fxRateRef = null,
        public ?string $note = null,
        public ?string $poReference = null,
    ) {
        // Readonly promoted properties are assigned before this body runs, so the shape is checked
        // here and normalised by {@see currency()} / {@see rate()} on the way out. A lowercase code
        // is refused rather than mended, because `invoices_currency_upper` is a database CHECK and a
        // silent fix would let a bad paste reach the row anyway.
        if ($this->currencyCode !== '' && ! preg_match('/^[A-Z]{3}$/', $this->currencyCode)) {
            throw new InvalidArgumentException('An invoice currency is the three-letter uppercase ISO code; "'.htmlspecialchars($this->currencyCode, ENT_QUOTES).'" is not one.');
        }

        if ($this->fxRate !== null && $this->fxRate !== '' && (preg_match('/^\d{1,9}(\.\d{1,8})?$/', $this->fxRate) !== 1 || (float) $this->fxRate <= 0)) {
            throw new InvalidArgumentException('An invoice rate is a positive figure with at most 8 decimals — 1 foreign unit = X base.');
        }

        if ($this->baseAmountMinor !== null && $this->baseAmountMinor <= 0) {
            throw new InvalidArgumentException('The base figure a document is worth is positive; give the rate instead.');
        }

        if ($this->rate() !== null && $this->baseAmountMinor !== null) {
            throw new InvalidArgumentException(
                'A foreign document is raised with either the rate the desk agreed or the base the money is worth — typing '
                .'both asks which one the books must believe (ADR-0004 #6).',
            );
        }

        if ($this->lines === []) {
            throw new InvalidArgumentException('A manual invoice with no lines is a blank page; nothing can be posted from it.');
        }

        if (count($this->lines) > 20) {
            throw new InvalidArgumentException('A hand-raised document takes at most twenty lines. More than that is a billing run, not a note.');
        }

        foreach ($this->lines as $line) {
            if ($line->amountMinor <= 0) {
                throw new InvalidArgumentException(sprintf('The line "%s" is not a positive amount.', $line->description === '' ? '(no description)' : $line->description));
            }

            if ($line->description === '' || mb_strlen($line->description) > 160) {
                throw new InvalidArgumentException('Every manual line needs a description the customer can read — between 1 and 160 characters.');
            }
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $lines = array_map(
            static fn (array $line): ManualInvoiceLineDto => ManualInvoiceLineDto::fromArray($line),
            array_values($input['lines'] ?? []),
        );

        return new self(
            customerId: (int) ($input['customer_id'] ?? 0),
            lines: $lines,
            currencyCode: is_string($input['currency_code'] ?? null) ? strtoupper(trim((string) $input['currency_code'])) : '',
            fxRate: isset($input['fx_rate']) && $input['fx_rate'] !== '' ? trim((string) $input['fx_rate']) : null,
            baseAmountMinor: isset($input['base_amount_minor']) && $input['base_amount_minor'] !== '' ? (int) $input['base_amount_minor'] : null,
            fxRateSource: (string) ($input['fx_rate_source'] ?? FxRateSource::Manual->value),
            fxRateRef: isset($input['fx_rate_ref']) && $input['fx_rate_ref'] !== '' ? (string) $input['fx_rate_ref'] : null,
            note: isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
            poReference: isset($input['po_reference']) && $input['po_reference'] !== '' ? (string) $input['po_reference'] : null,
        );
    }

    public function currency(): string
    {
        return strtoupper(trim($this->currencyCode));
    }

    public function rate(): ?string
    {
        return $this->fxRate === null || $this->fxRate === '' ? null : $this->fxRate;
    }

    /** What the document asks for, in the currency it names. */
    public function foreignTotalMinor(): int
    {
        return array_sum(array_map(static fn (ManualInvoiceLineDto $line): int => $line->amountMinor, $this->lines));
    }

    public function isForeign(string $baseCurrency): bool
    {
        return $this->currency() !== '' && $this->currency() !== $baseCurrency;
    }

    /**
     * The rate this document converts at, or `'1'` when it is a base-currency document.
     *
     * Either the desk typed it, or it is back-solved from the base the desk typed (ADR-0004 #6's
     * second edit). Nothing here reaches for a rate the caller did not put in the DTO — that
     * lookup belongs to the Action, which can say where the bulletin came from.
     */
    public function resolvedRate(string $baseCurrency): string
    {
        if (! $this->isForeign($baseCurrency)) {
            return '1';
        }

        $rate = $this->rate();

        if ($rate !== null) {
            return $rate;
        }

        if ($this->baseAmountMinor === null) {
            throw new InvalidArgumentException('A foreign document needs a rate or a base amount — one of the two, never neither.');
        }

        return Money::ofMinor($this->foreignTotalMinor(), $this->currency())
            ->rateToBaseImpliedBy(Money::ofMinor($this->baseAmountMinor, $baseCurrency));
    }

    /** Which named edit produced the rate — the base figure always wins the `manual_override` label. */
    public function resolvedSource(): FxRateSource
    {
        $source = FxRateSource::tryFrom($this->fxRateSource);

        if ($source === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not a rate source the books recognise.', $this->fxRateSource));
        }

        return $this->rate() === null && $this->baseAmountMinor !== null ? FxRateSource::ManualOverride : $source;
    }

    /**
     * A copy carrying a rate the Action resolved (from the stored bulletin) rather than one
     * a human typed. The DTO is immutable, so pre-filling means a new value object — which is also
     * what keeps a Livewire property bag from being mutated underneath a validation message.
     */
    public function withRate(string $rate, FxRateSource $source, ?string $ref = null): self
    {
        return new self(
            customerId: $this->customerId,
            lines: $this->lines,
            currencyCode: $this->currencyCode,
            fxRate: $rate,
            baseAmountMinor: null,
            fxRateSource: $source->value,
            fxRateRef: $ref ?? $this->fxRateRef,
            note: $this->note,
            poReference: $this->poReference,
        );
    }

    /**
     * One frozen base amount per line, in the order the lines arrived.
     *
     * Each line converts on its own and is rounded on its own — the "sum of rounded lines, never
     * round-of-sum" — and when the desk typed the base total rather than a rate, the **last line**
     * absorbs the residue so the document's base is exactly the figure a human confirmed. That is
     * the same rule that lets a paid invoice reach zero, applied to the conversion instead of the
     * allocation.
     *
     * @return list<int>
     */
    public function baseAmounts(string $baseCurrency, DateTimeInterface $on): array
    {
        if (! $this->isForeign($baseCurrency)) {
            return array_map(static fn (ManualInvoiceLineDto $line): int => $line->amountMinor, $this->lines);
        }

        $rate = $this->resolvedRate($baseCurrency);
        $source = $this->resolvedSource();

        /** @var list<int> $bases */
        $bases = [];

        foreach ($this->lines as $line) {
            $bases[] = Fx::foreignToBase(
                $line->amountMinor,
                $this->currency(),
                $rate,
                $baseCurrency,
                $source,
                $on,
            )->baseAmount()->minorUnits;
        }

        if ($this->baseAmountMinor !== null) {
            $index = count($bases) - 1;
            $bases[$index] += $this->baseAmountMinor - array_sum($bases);

            if ($bases[$index] <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'The base figure typed (%d minor units) leaves nothing for the last line once the others are converted — '
                    .'check the rate or the total.',
                    $this->baseAmountMinor,
                ));
            }
        }

        return $bases;
    }
}
