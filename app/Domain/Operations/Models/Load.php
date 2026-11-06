<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Policies\LoadPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * LOAD — one parcel of freight on a trip's leg.
 *
 * The tonnage is a decimal (`tonnes_10_2`), not an integer, for the reason Phase 1 recorded for
 * `trucks.capacity_tonnes_10_2`: D6's integer rule exists for money, and a load is not money. Rounding
 * 3.5 t up to 4 t to satisfy it would make the capacity gate lie by an eighth of a load.
 *
 * Auto-increment id, not a ULID: a load is never addressed from outside its trip — reserves ULIDs
 * for externally-referenced aggregates, and the POD that references a load reaches it through the trip.
 *
 * @property int $id
 * @property int $company_id
 * @property string $trip_id
 * @property string $commodity
 * @property string|float $tonnes_10_2
 * @property string|float|null $cubic_meters_10_2
 * @property string|null $consignee
 * @property string|null $origin
 * @property string|null $destination
 * @property string|null $stop_sequence
 * @property string|null $waybill_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(LoadPolicy::class)]
final class Load extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'commodity',
        'tonnes_10_2',
        'cubic_meters_10_2',
        'consignee',
        'origin',
        'destination',
        'stop_sequence',
        'waybill_document_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'tonnes_10_2' => 'float',
            'cubic_meters_10_2' => 'float',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'waybill_document_id');
    }

    /** The label the loading schedule prints: what it is, how much, and for whom. */
    public function label(): string
    {
        $tonnes = rtrim(rtrim(number_format((float) $this->tonnes_10_2, 2, '.', ''), '0'), '.');

        return $this->consignee === null
            ? $this->commodity.' · '.$tonnes.' t'
            : $this->commodity.' · '.$tonnes.' t · '.$this->consignee;
    }
}
