<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Plausibility;
use App\Domain\Fleet\Policies\OdometerReadingPolicy;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 ODOMETER_READING — one measurement, kept as measured.
 *
 * The truck's `odometer_km` is a projection of the highest plausible reading, maintained by
 * {@see RecordOdometerReading}. It is never the source of truth: a reading
 * that runs backwards stays on the timeline marked `suspect`, so the question "why did the odometer
 * drop in March" has a row to open.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property string|null $trip_id
 * @property int $odometer_km
 * @property string|float|null $engine_hours_10_2
 * @property Carbon $read_on
 * @property OdometerSource $source
 * @property Plausibility $plausibility
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(OdometerReadingPolicy::class)]
final class OdometerReading extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'trip_id',
        'odometer_km',
        'engine_hours_10_2',
        'read_on',
        'source',
        'plausibility',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'odometer_km' => 'integer',
            'engine_hours_10_2' => 'float',
            'read_on' => 'date',
            'source' => OdometerSource::class,
            'plausibility' => Plausibility::class,
            'recorded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }
}
