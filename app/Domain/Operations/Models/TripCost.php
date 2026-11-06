<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * TRIP_COST — one direct cost against a trip, and the operational half of the journal entry that
 * carries it (the FX block is on the row, not only on the entry).
 *
 * `base_amount` is the authoritative figure and never recomputed: the rate and the date it was
 * struck travel with the row, so a report next year reads what the money actually cost rather than
 * what today's rate would make it look like. The journal link is `(source_type, source_id)`, and the
 * unique index on the pair is what makes a retried capture one cost.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_id
 * @property TripCostType $cost_type
 * @property TripCostPayment $paid_with
 * @property string|null $description
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property FxRateSource $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_amount
 * @property string|null $source_type
 * @property string|null $source_id
 * @property Carbon $occurred_on
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TripCost extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'trip_id', 'cost_type', 'paid_with', 'description',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount',
        'source_type', 'source_id', 'occurred_on', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'cost_type' => TripCostType::class,
            'paid_with' => TripCostPayment::class,
            'amount' => 'integer',
            // A rate is a dimensionless multiplier, so it is the one decimal in the design.
            'fx_rate' => 'decimal:8',
            'fx_rate_source' => FxRateSource::class,
            'fx_rate_date' => 'date',
            'base_amount' => 'integer',
            'occurred_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
