<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * §17 Q4 — a VAT rate the ledger can be held to, with the window it applied in.
 *
 * A rate is read as of a **date**, never as of today: an invoice issued in December under the old
 * rate must print and post the old rate in March, which is what `effectiveOn()` is for and the
 * reason the window is data rather than a single editable row.
 *
 * `rate_x10000` is the §5.5 scale (12.00% is 120_000), so the arithmetic below is integer
 * throughout — a percentage computed as a float is how a VAT line stops agreeing with the return.
 *
 * @property string $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property TaxRateKind $kind
 * @property int $rate_x10000
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_active
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TaxRate extends Model
{
    use BelongsToCompany;

    /**
     * A ULID primary key, not an integer one. Without these two lines Eloquent reads `id` as an int and
     * `$rate->id` answers `1` for a row whose real key is `01M6…` — which is how an invoice line came to
     * reference a tax rate that does not exist (§20.32).
     */
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The scale a rate is stored at: a **percentage** with two decimals, so 12.00% is `12 × 10 000 = 120 000`.
     *
     * The percentage is itself per hundred, so turning a stored rate into a fraction of the amount is a
     * division by `RATE_SCALE × 100`. Writing that second hundred out is the whole point of this constant
     * pair: dividing by `RATE_SCALE` alone turns 12% into 1200%, which the invoice's own
     * `tax_amount <= amount` CHECK then refuses — see §20.32's defect note, where exactly that happened.
     */
    public const RATE_SCALE = 10_000;

    /** `RATE_SCALE × 100` — the divisor that turns a stored percentage into a fraction. */
    private const PERCENT_DIVISOR = self::RATE_SCALE * 100;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'kind',
        'rate_x10000',
        'starts_on',
        'ends_on',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'kind' => TaxRateKind::class,
            'rate_x10000' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The VAT on a line: the line's own amount × the rate, rounded HALF_UP once (§5.5 — VAT is
     * computed per line, never on the invoice total, so the invoice adds up on the page).
     *
     * Zero-rated and exempt lines answer 0 rather than refusing: the distinction between them is a
     * return-filing question, and both produce no VAT.
     */
    public function taxOn(int $amountMinor): int
    {
        if (! $this->kind->chargesTax() || $this->rate_x10000 === 0) {
            return 0;
        }

        return intdiv($amountMinor * $this->rate_x10000 + self::PERCENT_DIVISOR / 2, self::PERCENT_DIVISOR);
    }

    /** The base-currency VAT, from the line's already-converted base amount. */
    public function taxOnBase(int $baseAmountMinor): int
    {
        return $this->taxOn($baseAmountMinor);
    }

    public function percentageLabel(): string
    {
        $whole = intdiv($this->rate_x10000, self::RATE_SCALE);
        $fraction = $this->rate_x10000 % self::RATE_SCALE;

        if ($fraction === 0) {
            return $whole.'%';
        }

        return rtrim(sprintf('%s.%04d', $whole, $fraction), '0').'%';
    }

    public function label(): string
    {
        return $this->name.' · '.$this->percentageLabel();
    }

    /**
     * The rate that applied on a date: active, in window, and preferred by `is_default` when the
     * company has more than one standard rate open on the same day (a per-commodity regime).
     */
    public static function effectiveOn(DateTimeInterface|string $on, ?TaxRateKind $kind = null): ?self
    {
        $date = $on instanceof DateTimeInterface
            ? Carbon::instance($on)->toDateString()
            : Carbon::parse($on)->toDateString();

        return self::query()
            ->where('company_id', CurrentCompany::id())
            ->where('is_active', true)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->where('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>', $date))
            ->orderByDesc('is_default')
            ->orderByDesc('starts_on')
            ->first();
    }

    public static function make(
        string $code,
        string $name,
        TaxRateKind $kind,
        int $rateX10000,
        DateTimeInterface|string $startsOn,
        DateTimeInterface|string|null $endsOn = null,
        bool $isDefault = false,
    ): self {
        $rate = new self([
            'company_id' => CurrentCompany::id(),
            'code' => strtoupper($code),
            'name' => $name,
            'kind' => $kind,
            'rate_x10000' => $rateX10000,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'is_active' => $endsOn === null,
            'is_default' => $isDefault,
        ]);

        // The key is set outside mass assignment because `id` is deliberately not fillable: a row a screen
        // creates must not choose its own identity. Without this the seeder inserts a NULL primary key.
        $rate->id = (string) Str::ulid();

        return $rate;
    }
}
