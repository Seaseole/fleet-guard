<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Policies\RoutePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.1 ROUTE — a corridor the fleet prices against, e.g. GBE-WDH.
 *
 * The endpoints are stored as names for people and ISO codes for reports: Phase 6's per-country
 * summary groups by `origin_country_code`/`destination_country_code`, and no amount of string
 * matching on "Gaborone" is a substitute for that.
 *
 * `distance_km` is a decimal, not money and not an integer — the D6 rule is about currency, and a
 * per-km rate multiplied by 1 074.5 km must not silently become 1 074.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $origin
 * @property string $destination
 * @property string $origin_country_code
 * @property string $destination_country_code
 * @property string|float $distance_km
 * @property string|null $notes
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(RoutePolicy::class)]
final class Route extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'origin',
        'destination',
        'origin_country_code',
        'destination_country_code',
        'distance_km',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'distance_km' => 'float',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<RouteRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(RouteRate::class)->orderByDesc('valid_from');
    }

    /** "GBE-WDH · Gaborone → Windhoek" — the code first, because that is what dispatch says. */
    public function label(): string
    {
        return $this->code.' · '.$this->origin.' → '.$this->destination;
    }

    public function corridor(): string
    {
        return $this->origin.' → '.$this->destination;
    }

    /** True when the two endpoints sit in different countries — what Phase 6 will care about. */
    public function isCrossBorder(): bool
    {
        return $this->origin_country_code !== $this->destination_country_code;
    }
}
