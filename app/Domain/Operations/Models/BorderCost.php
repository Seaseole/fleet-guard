<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\BorderCostKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * BORDER_COST — one charge paid at one gate (duty, agent, road-user fee, scanning).
 *
 * The FX block is a copy of the trip cost this row posted. Storing it twice is deliberate: the
 * crossing screen must show what the gate charged without joining through the ledger, and copying
 * from the posted cost (rather than converting again) means the two figures are the same row's
 * numbers, not two computations that are expected to agree.
 *
 * @property string $id
 * @property int $company_id
 * @property string $border_crossing_id
 * @property BorderCostKind $kind
 * @property string|null $description
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_amount
 * @property Carbon $occurred_on
 * @property string|null $trip_cost_id
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BorderCost extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** ULID key: the row names itself as the `source_id` of the trip cost it posts. */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'border_crossing_id',
        'kind',
        'description',
        'currency_code',
        'amount',
        'fx_rate',
        'fx_rate_source',
        'fx_rate_date',
        'fx_rate_ref',
        'base_currency_code',
        'base_amount',
        'occurred_on',
        'trip_cost_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BorderCostKind::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'fx_rate_source' => FxRateSource::class,
            'fx_rate_date' => 'date',
            'occurred_on' => 'date',
        ];
    }

    /** @return BelongsTo<BorderCrossing, $this> */
    public function crossing(): BelongsTo
    {
        return $this->belongsTo(BorderCrossing::class, 'border_crossing_id');
    }

    /** @return BelongsTo<TripCost, $this> */
    public function tripCost(): BelongsTo
    {
        return $this->belongsTo(TripCost::class);
    }

    public function amountMoney(): Money
    {
        return Money::ofMinor($this->amount, $this->currency_code);
    }

    public function baseMoney(): Money
    {
        return Money::ofMinor($this->base_amount, $this->base_currency_code);
    }
}
