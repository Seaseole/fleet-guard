<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The `latest_positions` projection — one row per truck, the map's only read.
 *
 * Written inside the ingest transaction, so "where is the truck" and "the last fix is on file" are the
 * same commit. `fixed_at` is the fix's own clock and `updated_at` is when this projection last moved;
 * they differ when a delayed message arrives and matter to a reader deciding whether a truck is stale.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property string|null $trip_id
 * @property int|null $position_id
 * @property string $provider
 * @property string $lat_10_7
 * @property string $lng_10_7
 * @property int|null $speed_kph
 * @property int|null $heading_deg
 * @property int|null $odometer_km
 * @property int|null $accuracy_m
 * @property Carbon $fixed_at
 * @property Carbon $updated_at
 */
final class LatestPosition extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'trip_id',
        'position_id',
        'provider',
        'lat_10_7',
        'lng_10_7',
        'speed_kph',
        'heading_deg',
        'odometer_km',
        'accuracy_m',
        'fixed_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'fixed_at' => 'datetime',
            'updated_at' => 'datetime',
            'lat_10_7' => 'float',
            'lng_10_7' => 'float',
            'speed_kph' => 'integer',
            'heading_deg' => 'integer',
            'odometer_km' => 'integer',
            'accuracy_m' => 'integer',
        ];
    }
}
