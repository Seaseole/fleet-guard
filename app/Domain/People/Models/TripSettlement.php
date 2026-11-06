<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\People\Policies\TripSettlementPolicy;
use App\Domain\People\TripSettlementStatus;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One trip's settlement sheet (`TRIP_SETTLEMENT`, the `TripSettled`). The advance total
 * is what the trip's advance rows issued; `expenses_covered`, `cash_returned` and `allowance`
 * are the accountant's judgement at signing — which is why they are stored, and why the
 * outstanding receivable is a CHECK'd subtraction rather than a report-time guess.
 *
 * `trip_id` is a names-only reference (draws OPS → PEOPLE and never the reverse): People
 * answers to the trip's story without owning a second edge back into it.
 *
 * @property string $id
 * @property int $company_id
 * @property string $reference
 * @property TripSettlementStatus $status
 * @property string $trip_id
 * @property int $driver_id
 * @property int $advance_total
 * @property int $expenses_covered
 * @property int $cash_returned
 * @property int $outstanding_minor
 * @property int $allowance
 * @property string|null $entry_id
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property int|null $settled_by
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(TripSettlementPolicy::class)]
final class TripSettlement extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'trip_settlements';

    protected $fillable = [
        'id',
        'reference',
        'status',
        'trip_id',
        'driver_id',
        'advance_total',
        'expenses_covered',
        'cash_returned',
        'outstanding_minor',
        'allowance',
        'entry_id',
        'notes',
        'recorded_by',
        'settled_by',
        'settled_at',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => TripSettlementStatus::class,
            'driver_id' => 'integer',
            'advance_total' => 'integer',
            'expenses_covered' => 'integer',
            'cash_returned' => 'integer',
            'outstanding_minor' => 'integer',
            'allowance' => 'integer',
            'recorded_by' => 'integer',
            'settled_by' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** Cleared money: what the advance actually funded plus what the driver brought back. */
    public function clearedMinor(): int
    {
        return $this->expenses_covered + $this->cash_returned;
    }
}
