<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * GPS_POSITION — one normalised fix, append-only.
 *
 * There is no update path in the design: a fix that was wrong is wrong, and the provider's corrected
 * message arrives as a new row with its own id. The map reads {@see LatestPosition}; this table is
 * history, a trip's track and the record of what the provider actually said (`raw_json`).
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property string $provider_message_id
 * @property int $truck_id
 * @property string|null $trip_id
 * @property Carbon $fixed_at
 * @property string $lat_10_7
 * @property string $lng_10_7
 * @property int|null $speed_kph
 * @property int|null $heading_deg
 * @property int|null $odometer_km
 * @property int|null $accuracy_m
 * @property array<string, mixed>|null $raw_json
 * @property Carbon $ingested_at
 */
final class GpsPosition extends Model
{
    use BelongsToCompany;

    /** The row is an event with its own two clocks; an `updated_at` would be a column that never moves. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_message_id',
        'truck_id',
        'trip_id',
        'fixed_at',
        'lat_10_7',
        'lng_10_7',
        'speed_kph',
        'heading_deg',
        'odometer_km',
        'accuracy_m',
        'raw_json',
        'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'fixed_at' => 'datetime',
            'ingested_at' => 'datetime',
            'lat_10_7' => 'float',
            'lng_10_7' => 'float',
            'speed_kph' => 'integer',
            'heading_deg' => 'integer',
            'odometer_km' => 'integer',
            'accuracy_m' => 'integer',
            'raw_json' => 'array',
        ];
    }
}
