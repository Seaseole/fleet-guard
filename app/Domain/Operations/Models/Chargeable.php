<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\ChargeableBasis;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\ChargeableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.1 CHARGEABLE — what a trip earns, held between the delivery and the invoice.
 *
 * The row is generated from the POD's own payload (§16: chargeables are built from the event, not
 * re-read later), and `source_uuid` is the idempotency key that makes a second approval a lookup
 * rather than a second bill. `base_amount` is the base-currency figure; `amount` is what the customer
 * will see, in the currency the job was quoted in.
 *
 * There is no `invoice_id` here. §4.1's INVOICE_LINE names the chargeable, not the other way round,
 * because one chargeable is billed once and an invoice can bundle many.
 *
 * @property string $id
 * @property int $company_id
 * @property string $trip_id
 * @property string $job_id
 * @property int $customer_id
 * @property ChargeableType $type
 * @property ChargeableBasis $basis
 * @property string|null $description
 * @property string $currency_code
 * @property int $amount
 * @property int $base_amount
 * @property ChargeableStatus $status
 * @property string $source_uuid
 */
final class Chargeable extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'trip_id', 'job_id', 'customer_id', 'type', 'basis', 'description',
        'currency_code', 'amount', 'base_amount', 'status', 'source_uuid',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'customer_id' => 'integer',
            'type' => ChargeableType::class,
            'basis' => ChargeableBasis::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'status' => ChargeableStatus::class,
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
