<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money as BrickMoney;
use DomainException;
use JsonSerializable;
use RuntimeException;

/**
 * The only money type the domain is allowed to move around (ADR-0004 #1, #2, #4).
 *
 * It carries its currency with it on purpose: an amount without one is the bug exists to
 * prevent, and making the pair a single value means no call site can pass one without the other.
 */
final class Money implements JsonSerializable
{
    public readonly int $minorUnits;

    public readonly string $currencyCode;

    /**
     * Never negative: brick's CustomContext and BigDecimal::toScale() both demand a
     * non-negative scale, and the clamp in the constructor is also the honest guard against a
     * currencies row that says otherwise.
     *
     * @var int<0, max>
     */
    public readonly int $minorUnitCount;

    private function __construct(int $minorUnits, string $currencyCode, int $minorUnitCount)
    {
        $this->minorUnits = $minorUnits;
        $this->currencyCode = $currencyCode;
        $this->minorUnitCount = max(0, $minorUnitCount);
    }

    /**
     * `float` is in the signature deliberately so that a float is refused *here*, with a
     * message, rather than silently coerced: strict typing is decided by the calling file, and
     * Blade/Livewire files are not strict, so a float argument would otherwise arrive as a
     * truncated int. Measured on brick/math 1.0 too — `BigDecimal::of(0.1)` returns `0` and
     * `of(1.5)` returns `1`, so the library will not catch it either.
     */
    public static function ofMinor(int|string|float $minorUnits, Currency|string $currency): self
    {
        if (is_float($minorUnits)) {
            throw new RuntimeException(sprintf(
                'Money must be integer minor units; got float %s. Something upstream parsed a major-unit '
                .'amount — convert it at that boundary, not here.',
                var_export($minorUnits, true),
            ));
        }

        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        $units = is_int($minorUnits)
            ? $minorUnits
            : (preg_match('/^-?\d+$/', $minorUnits)
                ? (int) $minorUnits
                : throw new RuntimeException(
                    "Money must be integer minor units; got \"{$minorUnits}\". A decimal string means major "
                    .'units reached us unconverted, and brick/math truncates rather than rejecting.'
                ));

        return new self($units, $currency->code, $currency->minorUnits());
    }

    public static function zero(Currency|string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    /**
     * Parse what a person typed — a major-unit amount like "1 234.56" — into minor units.
     *
     * Screens are the boundary where a major-unit string becomes money, and this is the
     * only place allowed to do it. `ofMinor()` refuses a decimal string on purpose, so a screen
     * that tried to use it directly would fail on every valid input rather than on a wrong one.
     *
     * Sub-minor precision is refused rather than rounded: a clerk who typed P1.234 into a field
     * means something, and silently filing P1.23 is how a ledger stops matching the paper.
     */
    public static function ofMajor(string|float $major, Currency|string $currency): self
    {
        if (is_float($major)) {
            throw new RuntimeException(sprintf(
                'Money::ofMajor() takes the typed amount as a string; got float %s. A float has already '
                .'lost the digits you are trying to read (brick/math truncates rather than rejects).',
                var_export($major, true),
            ));
        }

        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);
        $scale = $currency->minorUnits();

        $decimalString = self::normaliseTypedAmount($major, $scale);
        $decimal = BigDecimal::of($decimalString);

        if ($decimal->isNegative()) {
            throw new DomainException('Enter a positive amount on the other side instead.');
        }

        $scaled = $decimal->toScale($scale, RoundingMode::Unnecessary);

        try {
            $minor = $scaled->withPointMovedRight($scale)->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new DomainException('Amount is too large.', previous: $exception);
        }

        return self::ofMinor($minor, $currency);
    }

    /** A single dot or comma is decimal, never a fallback thousands separator. */
    private static function normaliseTypedAmount(string $typed, int $scale): string
    {
        $clean = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $typed));
        $formats = [
            '/\A(-?(?:[0-9]+|[0-9]{1,3}(?: [0-9]{3})+))(?:[.,]([0-9]+))?\z/',
            '/\A(-?[0-9]{1,3}(?:,[0-9]{3})+)\.([0-9]+)\z/',
            '/\A(-?[0-9]{1,3}(?:\.[0-9]{3})+),([0-9]+)\z/',
            '/\A(-?[0-9]{1,3}(?:(?:,[0-9]{3}){2,}|(?:\.[0-9]{3}){2,}))\z/',
            '/\A(-?)[.,]([0-9]+)\z/',
        ];

        foreach ($formats as $format) {
            if (! preg_match($format, $clean, $parts)) {
                continue;
            }

            $fraction = $parts[2] ?? '';

            if (strlen($fraction) > $scale) {
                throw new DomainException($scale === 0
                    ? 'Use whole amounts; use spaces for thousands.'
                    : sprintf('Use at most %d decimal places; use spaces for thousands.', $scale));
            }

            $whole = str_replace([' ', ',', '.'], '', $parts[1]);

            if ($whole === '' || $whole === '-') {
                $whole .= '0';
            }

            return $whole.($fraction === '' ? '' : '.'.$fraction);
        }

        throw new DomainException('Enter a complete amount with valid grouping, without symbols.');
    }

    public function currency(): Currency
    {
        return Currency::of($this->currencyCode);
    }

    public function add(self $other): self
    {
        if (! $this->isSameCurrencyAs($other)) {
            throw new RuntimeException(
                "Refusing to add {$other->currencyCode} to {$this->currencyCode}. Convert first, at an explicit rate.",
            );
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currencyCode, $this->minorUnitCount);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currencyCode, $this->minorUnitCount);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isSameCurrencyAs(self $other): bool
    {
        return $this->currencyCode === $other->currencyCode;
    }

    /**
     * ADR-0004 #4/#5 — `$rate` is always "1 foreign unit = X base", as a string. The result is
     * the `base_amount` the ledger stores and every report reads.
     */
    public function toBase(string $rate, Currency|string $baseCurrency): self
    {
        $base = $baseCurrency instanceof Currency ? $baseCurrency : Currency::of($baseCurrency);
        $rateDecimal = BigDecimal::of($rate);

        if ($base->isSameAs($this->currencyCode)) {
            if (! $rateDecimal->isEqualTo(1)) {
                throw new RuntimeException(sprintf(
                    'A %s amount carries fx rate %s. Converting a currency to itself is rate 1, always — '
                    .'a rate that is not 1 here means the direction convention has been inverted somewhere.',
                    $this->currencyCode,
                    $rate,
                ));
            }

            return new self($this->minorUnits, $base->code, $base->minorUnits());
        }

        $converted = BrickMoney::ofMinor(
            $this->minorUnits,
            $this->currencyCode,
            new CustomContext($this->minorUnitCount),
        )->convertedTo(
            $base->code,
            $rateDecimal,
            new CustomContext($base->minorUnits()),
            RoundingMode::HalfUp,
        );

        return new self($converted->getMinorAmount()->toInt(), $base->code, $base->minorUnits());
    }

    /**
     * ADR-0004 #6, the back-solve: the bank charged exactly this in base currency, so the rate
     * moves and `fx_rate_source` becomes manual_override. Kept here rather than in a screen so
     * the direction convention cannot be inverted by whoever builds the form.
     *
     * @return string the rate at 8 dp, HALF_UP — "1 foreign unit = X base"
     */
    public function rateToBaseImpliedBy(self $baseAmount): string
    {
        if ($this->minorUnits === 0) {
            throw new RuntimeException('Cannot derive an fx rate from a zero foreign amount.');
        }

        if ($baseAmount->isSameCurrencyAs($this)) {
            throw new RuntimeException(sprintf(
                'rateToBaseImpliedBy() needs the foreign amount as the receiver and a %s amount as the argument; '
                .'both sides are %s.',
                $baseAmount->currencyCode,
                $this->currencyCode,
            ));
        }

        return $baseAmount->toBigDecimal()
            ->dividedBy($this->toBigDecimal(), 8, RoundingMode::HalfUp)
            ->toString();
    }

    public function toBigDecimal(): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($this->minorUnits, $this->minorUnitCount);
    }

    /**
     * ADR-0004 #2: money leaves as strings, never as JSON numbers, so a client cannot lose
     * precision in a float it never sees.
     *
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => (string) $this->minorUnits,
            'currency' => $this->currencyCode,
        ];
    }

    public function format(?string $baseCurrencyCode = null): string
    {
        $currency = Currency::of($this->currencyCode);
        $scale = $this->minorUnitCount;
        $unit = 10 ** $scale;
        $sign = $this->minorUnits < 0 ? '−' : '';
        $whole = self::groupThousands((string) intdiv(abs($this->minorUnits), $unit));
        $fraction = $scale === 0 ? '' : ','.str_pad((string) (abs($this->minorUnits) % $unit), $scale, '0', STR_PAD_LEFT);
        $symbol = $currency->symbol ?: $currency->code;
        $showCode = $currency->symbol !== ''
            && $baseCurrencyCode !== null && $baseCurrencyCode !== $currency->code;
        $code = $showCode ? ' '.$currency->code : '';

        return $sign.$symbol.' '.$whole.$fraction.$code;
    }

    private static function groupThousands(string $digits): string
    {
        return strrev(implode(' ', str_split(strrev($digits), 3)));
    }
}
