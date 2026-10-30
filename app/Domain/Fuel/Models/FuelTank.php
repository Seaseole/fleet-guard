<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §4.3 FUEL_TANK — the tank an issue fills, with the capacity the truck row states.
 *
 * Deliberately not auditable: the row carries no money and no decision, and a tank whose capacity
 * was corrected is a master-data edit the truck timeline does not need to explain. The issues it
 * holds are audited, and they are the record that matters.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property FuelTankKind $kind
 * @property int $capacity_litres
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelTank extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'kind',
        'capacity_litres',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'kind' => FuelTankKind::class,
            'capacity_litres' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function label(): string
    {
        return $this->truck?->label().' · '.$this->kind->label();
    }
}
