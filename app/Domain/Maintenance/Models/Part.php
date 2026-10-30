<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\PartCategory;
use App\Domain\Maintenance\Policies\PartPolicy;
use App\Domain\Maintenance\StockMovementKind;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 PART — a line in the workshop's catalogue of what it buys and fits, and the shelf behind it.
 *
 * The class names Fleet's `Truck` because §4.3 gives a part an optional "specific part" vehicle
 * and §3's map already draws MAINT → FLEET; the edge is declared in `scripts/lint-architecture.php`
 * rather than hidden. Most parts leave it null and fit anything.
 *
 * Cost is exposed as {@see Money}, not as a raw integer, so the screen cannot print a ZAR part
 * with the Pula symbol (§5.1). The model owns no format of its own.
 *
 * The moving average is **value over units**, exactly as a bulk tank keeps it (§20.26): a receipt
 * adds its base money and its quantity, and the ratio is recomputed. `average_cost_minor` is that
 * ratio rounded for display, while `stock_value_base` is what the shelf is worth. An issue that takes
 * every unit left takes the *exact* remaining value, so a part emptied to nothing is worth nothing
 * and the CHECK on the table can insist on it.
 *
 * Stock columns are audit-excluded: every change to them is already a `stock_movements` row with its
 * before and after figures, and an audit trail duplicating a movement book is a second truth to
 * reconcile. The catalogue's own fields — name, price, shelf, reordering point — stay audited.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sku
 * @property string $name
 * @property PartCategory $category
 * @property int $unit_cost_minor
 * @property string $currency_code
 * @property int|null $reorder_level
 * @property int $stock_quantity_x100
 * @property int $stock_value_base
 * @property int $average_cost_minor
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
        'stock_quantity_x100',
        'stock_value_base',
        'average_cost_minor',
        'truck_id',
        'active',
    ];

    /** @var list<string> */
    protected $auditExclude = [
        'stock_quantity_x100',
        'stock_value_base',
        'average_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'category' => PartCategory::class,
            'unit_cost_minor' => 'integer',
            'reorder_level' => 'integer',
            'stock_quantity_x100' => 'integer',
            'stock_value_base' => 'integer',
            'average_cost_minor' => 'integer',
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

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function label(): string
    {
        return $this->sku.' · '.$this->name;
    }

    public function stockLabel(): string
    {
        return number_format($this->stock_quantity_x100 / 100, 2, '.', '');
    }

    public function valueMoney(): Money
    {
        return Money::ofMinor($this->stock_value_base, $this->baseCurrency());
    }

    public function averageMoney(): Money
    {
        return Money::ofMinor($this->average_cost_minor, $this->baseCurrency());
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    /** Whether the shelf is at or under the point the store said it should reorder at. */
    public function isLowStock(): bool
    {
        return $this->reorder_level !== null
            && $this->stock_quantity_x100 <= $this->reorder_level * 100;
    }

    /**
     * What taking this many units costs at the shelf's moving average, in base minor units, rounded
     * once (§5.5). Taking everything left costs the value the shelf actually holds, so no rounding
     * residue survives an emptiness the book says is worth something.
     */
    public function costForIssue(int $quantityX100): int
    {
        if ($quantityX100 === $this->stock_quantity_x100) {
            return $this->stock_value_base;
        }

        return min(
            intdiv($this->average_cost_minor * $quantityX100 + 50, 100),
            $this->stock_value_base,
        );
    }

    /**
     * Apply one movement to the book and record it, with the figures the shelf holds afterwards.
     *
     * Callers prove the stock is enough before getting here; the negative guard below is the same
     * invariant the table's CHECK states, so a caller that skipped the proof fails on the row rather
     * than writing a shelf that owes parts.
     */
    public function recordMovement(
        StockMovementKind $kind,
        int $quantityDeltaX100,
        int $valueDeltaBase,
        DateTimeInterface $movedOn,
        ?string $workOrderId = null,
        ?int $unitCostMinor = null,
        ?string $note = null,
        ?int $actorId = null,
    ): StockMovement {
        $this->stock_quantity_x100 += $quantityDeltaX100;
        $this->stock_value_base += $valueDeltaBase;

        if ($this->stock_quantity_x100 < 0 || $this->stock_value_base < 0) {
            throw new InvalidArgumentException('A part cannot go below nothing on the shelf; the movement was refused.');
        }

        $this->recomputeAverage();
        $this->save();

        return $this->movements()->create([
            'id' => (string) Str::ulid(),
            'kind' => $kind,
            'quantity_delta_x100' => $quantityDeltaX100,
            'value_delta_base' => $valueDeltaBase,
            'quantity_after_x100' => $this->stock_quantity_x100,
            'value_after_base' => $this->stock_value_base,
            'average_cost_minor_after' => $this->average_cost_minor,
            'work_order_id' => $workOrderId,
            'unit_cost_minor' => $unitCostMinor,
            'moved_on' => Carbon::instance($movedOn)->toDateString(),
            'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 160),
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * The cache with a warrant: value ÷ units, HALF_UP, refreshed on every movement. No units is no
     * value — the table's CHECK states the same thing, so a drift here fails loudly.
     */
    public function recomputeAverage(): void
    {
        if ($this->stock_quantity_x100 <= 0) {
            $this->stock_quantity_x100 = 0;
            $this->stock_value_base = 0;
            $this->average_cost_minor = 0;

            return;
        }

        $this->average_cost_minor = intdiv(
            $this->stock_value_base * 100 + intdiv($this->stock_quantity_x100, 2),
            $this->stock_quantity_x100,
        );
    }
}
