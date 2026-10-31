<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.2 BILL — the supplier's own document for work the fleet had done for it.
 *
 * `status` carries only the lifecycle (§10.9); settlement is **derived** from the supplier payment
 * rows the way §10.4 keeps an invoice's axes apart ({@see self::settlementState()}). `amount` is
 * **gross** — net plus VAT, what the supplier asks for — and the net/tax split lives on the lines,
 * because VAT is computed per line and stored, never re-derived at report time (§5.5).
 *
 * `balance_amount` is a projection of `supplier_payment_allocations`, carried so AP ageing and the
 * payable total are a query rather than a walk over every payment (§10.4's rule, copied sideways).
 *
 * A bill is either free lines that post their own expense, or one linked obligation of Phase 3's that
 * posts nothing (§20.36) — `linked_source` says which, and the schema's CHECK says a bill cannot be both.
 *
 * `supplier_id` is Accounting's own row: this is the module that owns suppliers, so the relation is
 * intra-module, unlike the invoice's customer.
 *
 * @property string $id
 * @property int $company_id
 * @property string $number
 * @property BillLifecycle $status
 * @property int $supplier_id
 * @property string|null $supplier_doc_no
 * @property BillLinkedSource|null $linked_source
 * @property string|null $linked_source_id
 * @property Carbon $bill_on
 * @property Carbon $due_on
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_amount
 * @property int $tax_amount
 * @property int $tax_base
 * @property int $balance_amount
 * @property int $balance_base
 * @property string|null $note
 * @property string|null $source_uuid
 * @property int|null $recorded_by
 * @property Carbon|null $recorded_at
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Bill extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'number', 'status', 'supplier_id', 'supplier_doc_no',
        'linked_source', 'linked_source_id',
        'bill_on', 'due_on',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount',
        'tax_amount', 'tax_base', 'balance_amount', 'balance_base',
        'note', 'source_uuid',
        'recorded_by', 'recorded_at', 'void_reason', 'voided_at', 'voided_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => BillLifecycle::class,
            'linked_source' => BillLinkedSource::class,
            'supplier_id' => 'integer',
            'bill_on' => 'date',
            'due_on' => 'date',
            'fx_rate_date' => 'date',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'balance_amount' => 'integer',
            'balance_base' => 'integer',
            'recorded_by' => 'integer',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }

    /** @return HasMany<BillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('position');
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * §10.4 axis two, copied to the payable side: derived from the allocation rows' projection,
     * stored nowhere. `paid` on the status column would be a second truth (§20.36's migration note).
     */
    public function settlementState(): SettlementState
    {
        if ($this->status === BillLifecycle::Void) {
            return SettlementState::Open;
        }

        return SettlementState::fromBalance($this->balance_amount, $this->amount);
    }

    /** Any standing application — the answer that decides whether a void may proceed (§20.33's rule 1). */
    public function hasPayments(): bool
    {
        return $this->paymentAllocations()->where('reversed', false)->exists();
    }

    /**
     * §10.4's third axis on the payable side: a live bill whose due date has passed still owes money.
     *
     * Computed against the company's today and stored nowhere — a saved bucket is wrong every midnight,
     * which is exactly why an invoice's ageing is a question rather than a column.
     */
    public function isOverdue(?\DateTimeInterface $today = null): bool
    {
        if ($this->status !== BillLifecycle::Recorded || $this->balance_amount === 0) {
            return false;
        }

        $asOf = $today === null
            ? Carbon::today(CurrentCompany::timezone())
            : Carbon::instance($today)->startOfDay();

        return $this->due_on->startOfDay()->lessThan($asOf);
    }
}
