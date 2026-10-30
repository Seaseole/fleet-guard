<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * §4.3 BULK_TANK — the yard tank diesel is delivered into (§15.2 phase 3, moving average).
 *
 * The moving average is **value over litres**, not an average of averages: a receipt adds its base
 * money and its litres, and the ratio is recomputed. `average_cost_per_litre_minor` is that ratio
 * rounded for display, while `stock_value_base` is what the stock is actually worth — deriving an
 * issue's cost from the rounded average and then subtracting an equally rounded value is how a cent
 * per issue turns into a tank that is worth money it does not hold.
 *
 * A drain therefore takes the *exact* remaining value (see {@see costForIssue()}), never a rounded
 * share of it, so a tank emptied to zero is worth zero and the CHECK on the table can insist on it.
 *
 * Not auditable: every change to this row is already a `bulk_tank_movements` row with its before and
 * after figures, and an audit trail duplicating a movement book is a second truth to reconcile.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $capacity_litres
 * @property int $stock_litres_x100
 * @property int $stock_value_base
 * @property int $average_cost_per_litre_minor
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BulkTank extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'capacity_litres',
        'stock_litres_x100',
        'stock_value_base',
        'average_cost_per_litre_minor',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'capacity_litres' => 'integer',
            'stock_litres_x100' => 'integer',
            'stock_value_base' => 'integer',
            'average_cost_per_litre_minor' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<BulkTankMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(BulkTankMovement::class);
    }

    public function stockLitres(): string
    {
        return number_format($this->stock_litres_x100 / 100, 2, '.', '');
    }

    public function valueMoney(): Money
    {
        return Money::ofMinor($this->stock_value_base, $this->baseCurrency());
    }

    public function averageMoney(): Money
    {
        return Money::ofMinor($this->average_cost_per_litre_minor, $this->baseCurrency());
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function isOverCapacity(): bool
    {
        return $this->stock_litres_x100 > $this->capacity_litres * 100;
    }

    /**
     * What taking this many litres costs at the tank's moving average, in base minor units, rounded
     * once (§5.5). A drain of everything left costs the value the tank actually holds, so no rounding
     * residue survives an emptiness the book says is worth something.
     */
    public function costForIssue(int $litresX100): int
    {
        if ($litresX100 === $this->stock_litres_x100) {
            return $this->stock_value_base;
        }

        return min(
            intdiv($this->average_cost_per_litre_minor * $litresX100 + 50, 100),
            $this->stock_value_base,
        );
    }

    /**
     * Apply one movement to the book and record it, with the figures the tank holds afterwards.
     *
     * Callers prove the stock is enough and the signs agree before getting here; the negative guard
     * below is the same invariant the table's CHECK states, so a caller that skipped the proof fails
     * on the row rather than writing a tank that owes litres.
     */
    public function recordMovement(
        BulkTankMovementKind $kind,
        int $litresDeltaX100,
        int $valueDeltaBase,
        DateTimeInterface $occurredOn,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $note = null,
        ?int $actorId = null,
    ): BulkTankMovement {
        $this->stock_litres_x100 += $litresDeltaX100;
        $this->stock_value_base += $valueDeltaBase;

        if ($this->stock_litres_x100 < 0 || $this->stock_value_base < 0) {
            throw new InvalidArgumentException('A bulk tank cannot hold less than nothing; the movement was refused.');
        }

        $this->recomputeAverage();
        $this->save();

        return $this->movements()->create([
            'id' => (string) Str::ulid(),
            'kind' => $kind,
            'litres_delta_x100' => $litresDeltaX100,
            'value_delta_base' => $valueDeltaBase,
            'stock_litres_x100_after' => $this->stock_litres_x100,
            'stock_value_base_after' => $this->stock_value_base,
            'average_cost_per_litre_minor_after' => $this->average_cost_per_litre_minor,
            'occurred_on' => Carbon::instance($occurredOn)->toDateString(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 160),
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * The cache with a warrant: value ÷ litres, HALF_UP, refreshed on every movement. Zero litres is
     * zero of everything — the table's CHECK states the same thing, so a drift here fails loudly.
     */
    public function recomputeAverage(): void
    {
        if ($this->stock_litres_x100 <= 0) {
            $this->stock_litres_x100 = 0;
            $this->stock_value_base = 0;
            $this->average_cost_per_litre_minor = 0;

            return;
        }

        $this->average_cost_per_litre_minor = intdiv(
            $this->stock_value_base * 100 + intdiv($this->stock_litres_x100, 2),
            $this->stock_litres_x100,
        );
    }
}
