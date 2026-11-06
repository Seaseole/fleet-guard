<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FUEL_STATION — a pump the fleet buys from, usually also a supplier (draws no edge here,
 * so the supplier is named by id and the relation is the read the register needs; D5's
 * allowlist gains `Fuel => Accounting` for it).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $supplier_id
 * @property string $name
 * @property string|null $town
 * @property string $country_code
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelStation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'supplier_id',
        'name',
        'town',
        'country_code',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'supplier_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function label(): string
    {
        return $this->town === null || $this->town === ''
            ? $this->name
            : $this->name.' · '.$this->town;
    }
}
