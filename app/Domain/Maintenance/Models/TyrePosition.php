<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\TyreSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §4.3 TYRE_POSITION — one stint of one casing on one wheel of one truck.
 *
 * A row is opened when a tyre is fitted and closed when it is taken off, never edited afterwards. The
 * two unique constraints (through the generated `open_position`/`open_tyre_id` columns) are what make
 * "a slot holds one tyre" and "a tyre sits on one slot" true at the table, not just in the Action: an
 * open row's slot and tyre are unique, a closed row's are null and exempt, and history can pile up.
 *
 * `cost_per_km_x10000` is written at removal, not while the stint runs: until the odometer is read a
 * cost per kilometre is a guess. Four decimal places is the scale that keeps a thebe-per-kilometre
 * reading honest on a tyre that cost a thousand.
 *
 * @property int $id
 * @property int $company_id
 * @property int $tyre_id
 * @property int $truck_id
 * @property TyreSlot $position
 * @property Carbon $fitted_at
 * @property int $fitted_odometer_km
 * @property Carbon|null $removed_at
 * @property int|null $removed_odometer_km
 * @property int $retread_count
 * @property int|null $cost_per_km_x10000
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TyrePosition extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'tyre_id',
        'truck_id',
        'position',
        'fitted_at',
        'fitted_odometer_km',
        'removed_at',
        'removed_odometer_km',
        'retread_count',
        'cost_per_km_x10000',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'tyre_id' => 'integer',
            'truck_id' => 'integer',
            'position' => TyreSlot::class,
            'fitted_at' => 'datetime',
            'fitted_odometer_km' => 'integer',
            'removed_at' => 'datetime',
            'removed_odometer_km' => 'integer',
            'retread_count' => 'integer',
            'cost_per_km_x10000' => 'integer',
        ];
    }

    /** @return BelongsTo<Tyre, $this> */
    public function tyre(): BelongsTo
    {
        return $this->belongsTo(Tyre::class);
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function isOpen(): bool
    {
        return $this->removed_at === null;
    }

    /** How far the casing ran on this position; null until it is taken off. */
    public function kilometresRun(): ?int
    {
        if ($this->removed_odometer_km === null) {
            return null;
        }

        return $this->removed_odometer_km - $this->fitted_odometer_km;
    }

    /** "0.0200 /km" — four places, because a thebe over a kilometre is 0.0100 exactly. */
    public function costPerKmLabel(): string
    {
        return $this->cost_per_km_x10000 === null
            ? '—'
            : number_format($this->cost_per_km_x10000 / 10000, 4, '.', '');
    }
}
