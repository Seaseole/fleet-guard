<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\PartCategory;
use App\Domain\Maintenance\Policies\PartPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 PART — a line in the workshop's catalogue of what it buys and fits.
 *
 * The class names Fleet's `Truck` because §4.3 gives a part an optional "specific part" vehicle
 * and §3's map already draws MAINT → FLEET; the edge is declared in `scripts/lint-architecture.php`
 * rather than hidden. Most parts leave it null and fit anything.
 *
 * Cost is exposed as {@see Money}, not as a raw integer, so the screen cannot print a ZAR part
 * with the Pula symbol (§5.1). The model owns no format of its own.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sku
 * @property string $name
 * @property PartCategory $category
 * @property int $unit_cost_minor
 * @property string $currency_code
 * @property int|null $reorder_level
 * @property int|null $truck_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(PartPolicy::class)]
final class Part extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit_cost_minor',
        'currency_code',
        'reorder_level',
        'truck_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'category' => PartCategory::class,
            'unit_cost_minor' => 'integer',
            'reorder_level' => 'integer',
            'truck_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function unitCost(): Money
    {
        return Money::ofMinor($this->unit_cost_minor, $this->currency_code);
    }

    public function label(): string
    {
        return $this->sku.' · '.$this->name;
    }
}
