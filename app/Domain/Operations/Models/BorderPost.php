<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * BORDER_POST — a gate a trip crosses at, e.g. Kazungula (BW).
 *
 * It is master data, not a trip fact: the name, country and opening hours are the same for every
 * truck that uses it, and copying them onto each crossing would let two trips disagree about the
 * same gate. `country_code` is stored rather than derived from the name because Phase 6's per-country
 * cost summary groups on codes.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $country_code
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $open_from
 * @property string|null $open_to
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BorderPost extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'country_code',
        'lat',
        'lng',
        'open_from',
        'open_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** @return HasMany<BorderCrossing, $this> */
    public function crossings(): HasMany
    {
        return $this->hasMany(BorderCrossing::class);
    }

    public function label(): string
    {
        return $this->name.' ('.$this->country_code.')';
    }

    /**
     * The gate's hours as one phrase. `open_from` and `open_to` are plain time strings rather than a
     * schedule: a gate that closes for lunch is a note, not a column, and printing "06:00–22:00" from
     * two stored values beats six timetable rows that nobody fills in.
     */
    public function openingHours(): string
    {
        if ($this->open_from === null && $this->open_to === null) {
            return __('24 hours');
        }

        return ($this->open_from === null ? '—' : substr((string) $this->open_from, 0, 5))
            .'–'
            .($this->open_to === null ? '—' : substr((string) $this->open_to, 0, 5));
    }
}
