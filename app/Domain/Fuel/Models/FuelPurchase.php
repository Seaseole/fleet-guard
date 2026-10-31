<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fleet\FuelType;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 FUEL_PURCHASE — one slip from one station, with §5.2's FX block frozen at write time.
 *
 * The amount is exposed as {@see Money} so a ZAR slip cannot be printed with a Pula symbol (§5.1),
 * and the per-litre side is kept as the two integer spec columns so the screen can show what the
 * pump said without re-deriving it. `posted_to_ledger` is §6.8's seam and is false until Phase 5's
 * supplier bills exist; nothing in this build sets it true.
 *
 * @property string $id
 * @property int $company_id
 * @property int|null $fuel_station_id
 * @property int|null $fuel_card_id
 * @property FuelType $fuel_type
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property FxRateSource $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_amount
 * @property Carbon $purchased_on
 * @property int $litres
 * @property int $price_per_litre_minor
 * @property string|null $invoice_no
 * @property bool $posted_to_ledger
 * @property string|null $source_uuid
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelPurchase extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** ULID key (§2): a purchase is named by anything that has to reference it from outside. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'fuel_station_id',
        'fuel_card_id',
        'bulk_tank_id',
        'fuel_type',
        'currency_code',
        'amount',
        'fx_rate',
        'fx_rate_source',
        'fx_rate_date',
        'fx_rate_ref',
        'base_currency_code',
        'base_amount',
        'purchased_on',
        'litres',
        'price_per_litre_minor',
        'invoice_no',
        'posted_to_ledger',
        'source_uuid',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'fuel_station_id' => 'integer',
            'fuel_card_id' => 'integer',
            'bulk_tank_id' => 'integer',
            'fuel_type' => FuelType::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'fx_rate_source' => FxRateSource::class,
            'fx_rate_date' => 'date',
            'purchased_on' => 'date',
            'litres' => 'integer',
            'price_per_litre_minor' => 'integer',
            'posted_to_ledger' => 'boolean',
            'recorded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<FuelStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'fuel_station_id');
    }

    /** @return BelongsTo<FuelCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(FuelCard::class, 'fuel_card_id');
    }

    /** @return BelongsTo<BulkTank, $this> */
    public function bulkTank(): BelongsTo
    {
        return $this->belongsTo(BulkTank::class, 'bulk_tank_id');
    }

    /** A delivery into stock rather than a slip the trucks draw from (§15.2 phase 3). */
    public function isBulkDelivery(): bool
    {
        return $this->bulk_tank_id !== null;
    }

    /** @return HasMany<FuelIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(FuelIssue::class, 'purchase_id');
    }

    public function amountMoney(): Money
    {
        return Money::ofMinor($this->amount, $this->currency_code);
    }

    public function baseMoney(): Money
    {
        return Money::ofMinor($this->base_amount, $this->base_currency_code);
    }

    /** Litres as a major-unit string with two decimals: the spec's "×100 = 2 dp" lifted for display. */
    public function litresMajor(): string
    {
        return number_format($this->litres / 100, 2, '.', '');
    }

    public function pricePerLitre(): Money
    {
        return Money::ofMinor($this->price_per_litre_minor, $this->currency_code);
    }

    /**
     * What is left on the slip after every issue that named it — the number the screen must show.
     * A delivery into a bulk tank has nothing left to issue: its litres are the tank's stock, and
     * offering them as a slip as well would let the same diesel be counted twice.
     *
     * A reversed issue puts its litres back on the slip: the reversal says the diesel never left,
     * so counting it against the slip would keep stock that the ledger has already forgiven.
     */
    public function litresRemainingX100(): int
    {
        if ($this->isBulkDelivery()) {
            return 0;
        }

        $dispensed = (int) $this->issues()
            ->where('status', '!=', FuelIssueStatus::Reversed->value)
            ->sum('litres_x100');

        return $this->litres - $dispensed;
    }
}
