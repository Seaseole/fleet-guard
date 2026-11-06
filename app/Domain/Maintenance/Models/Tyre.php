<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\TyreStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * TYRE — a casing with a serial, bought once and moved between positions until it is worn out.
 *
 * The tyre is the asset; {@see TyrePosition} rows are the life it leads. Keeping the cost on the tyre
 * rather than spreading it over fitments means a tyre retreaded and refitted still knows what it cost,
 * and the removal's cost-per-kilometre is a reading of the casing rather than of one stint.
 *
 * `status` is what the repairer says about the casing now, and it is written only by the Actions that
 * fit and remove it — `in_service` while a position is open, back to `on_spares` when it comes off in
 * one piece, `retread_due` when it comes off worn, `scrap` when it is done.
 *
 * @property int $id
 * @property int $company_id
 * @property string $serial
 * @property string|null $brand
 * @property string $size
 * @property int $purchase_cost_base
 * @property int|null $tread_depth_new_x100
 * @property TyreStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Tyre extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'serial',
        'brand',
        'size',
        'purchase_cost_base',
        'tread_depth_new_x100',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'purchase_cost_base' => 'integer',
            'tread_depth_new_x100' => 'integer',
            'status' => TyreStatus::class,
        ];
    }

    /** @return HasMany<TyrePosition, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(TyrePosition::class)->orderByDesc('fitted_at')->orderByDesc('id');
    }

    /** @return HasOne<TyrePosition, $this> */
    public function openPosition(): HasOne
    {
        return $this->hasOne(TyrePosition::class)->whereNull('removed_at');
    }

    public function isFitted(): bool
    {
        return $this->status === TyreStatus::InService;
    }

    public function purchaseCost(): Money
    {
        return Money::ofMinor($this->purchase_cost_base, CurrentCompany::model()->base_currency_code);
    }

    /** "TYR-00012 · 315/80R22.5" — how the register and the fit dialog name a casing. */
    public function label(): string
    {
        return $this->serial.' · '.$this->size;
    }

    public function treadLabel(): string
    {
        return $this->tread_depth_new_x100 === null
            ? '—'
            : number_format($this->tread_depth_new_x100 / 100, 2, '.', '').' mm';
    }
}
