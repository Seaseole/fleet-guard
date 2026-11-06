<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rate someone entered and can be pointed at later. ADR-0004 #7: this is a lookup, never a
 * join — a historical amount carries its own copied rate precisely so that correcting today's
 * rate cannot reach back into a closed period.
 *
 * @property int $company_id
 * @property string $currency_code
 * @property CarbonImmutable $rate_date
 * @property string $rate_to_base
 * @property FxRateSource $source
 */
final class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'currency_code',
        'rate_date',
        'rate_to_base',
        'source',
        'ref',
        'entered_at',
    ];

    protected function casts(): array
    {
        return [
            // A rate is a dimensionless multiplier, so it is the one decimal in this design
            //. Read and written as a string: a float cannot hold the 8 dp this corridor needs.
            'rate_to_base' => 'decimal:8',
            'rate_date' => 'date',
            'entered_at' => 'datetime',
            'source' => FxRateSource::class,
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function rate(): string
    {
        return (string) $this->rate_to_base;
    }

    /**
     * The staleness warning, counted from a given date rather than from now: a Friday rate
     * read against a Monday document is 3 days old, which is what the owner wants to see before
     * quoting a ZAR-denominated job across a weekend. Negative means the rate is dated in the
     * future, which is a data-entry error rather than a warning.
     *
     * Takes DateTimeInterface and normalises with Carbon::instance(): the app boots with
     * `Date::use(CarbonImmutable::class)`, so `now()` is a CarbonImmutable, which is not a Carbon.
     */
    public function ageInDays(DateTimeInterface|CarbonImmutable|null $asOf = null): int
    {
        $struckOn = Carbon::instance($this->rate_date)->startOfDay();
        $reference = Carbon::instance($asOf ?? now())->startOfDay();

        return (int) $struckOn->diffInDays($reference, false);
    }
}
