<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\AgeingBucket;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.2 INVOICE — the fiscal document, and the three axes the migration deliberately keeps apart.
 *
 * `status` here is only the lifecycle (`draft → issued → void`). Settlement is **derived** from
 * {@see self::settlementState()} over the balance the allocation rows decided, and ageing is a question
 * asked of `due_on` against today — neither is a column, because a stored `paid` would let payment
 * allocation mutate lifecycle state and quietly cost the gap-free number its meaning (§10.4).
 *
 * `balance_amount` is a projection (§10.4), not the truth: `invoices:recompute-balances` rebuilds it from
 * the allocations and credit notes. It is carried because AR totals and ageing must be a query rather than
 * a walk over every allocation in the company.
 *
 * `job_id` is stored as an id with no relation, like every other cross-module reference in this codebase:
 * a `belongsTo(Job::class)` here would make Invoicing depend on Operations' models (D5).
 *
 * Money is integer minor units throughout (§5.1); `fx_rate` is "1 foreign unit = X base" as a string
 * (§5.2), and `base_amount` is authoritative and frozen at write (§5.3).
 *
 * @property string $id
 * @property int $company_id
 * @property string|null $number
 * @property InvoiceLifecycle $status
 * @property int $customer_id
 * @property string|null $job_id
 * @property InvoiceKind $kind
 * @property Carbon $issue_date
 * @property Carbon $due_on
 * @property string $terms_snapshot
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
 * @property string|null $po_reference
 * @property string|null $note
 * @property string|null $source_uuid
 * @property int|null $issued_by
 * @property Carbon|null $issued_at
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Invoice extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'number', 'status', 'customer_id', 'job_id', 'kind',
        'issue_date', 'due_on', 'terms_snapshot',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount',
        'tax_amount', 'tax_base', 'balance_amount', 'balance_base',
        'po_reference', 'note', 'source_uuid',
        'issued_by', 'issued_at', 'void_reason', 'voided_at', 'voided_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => InvoiceLifecycle::class,
            'kind' => InvoiceKind::class,
            'customer_id' => 'integer',
            'issue_date' => 'date',
            'due_on' => 'date',
            'fx_rate_date' => 'date',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'balance_amount' => 'integer',
            'balance_base' => 'integer',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // There is deliberately no `job()` relation. `job_id` is Operations' row, and §13 D5 keeps Invoicing out
    // of its models — the invoice names the id, and a screen that needs the number asks Operations' directory.

    /**
     * §10.4 axis two, derived from what the allocations and credit notes actually did.
     *
     * Never stored: a `paid` column would have to be maintained by the allocator, the credit note and the
     * void path, and the day one of them forgot, an invoice would read paid while still owed.
     */
    public function settlementState(): SettlementState
    {
        return SettlementState::fromBalance(
            $this->balance_amount,
            $this->amount + $this->tax_amount,
        );
    }

    /**
     * §10.4 axis three, computed from `due_on` against today and stored nowhere.
     *
     * An ageing column is a number that is wrong every midnight, so the bucket is a question asked of the
     * row when the report runs. `disputed` is never returned: it is a customer's claim, not a date.
     */
    public function ageingState(?\DateTimeInterface $today = null): AgeingBucket
    {
        return AgeingBucket::for($this->due_on, $today);
    }

    /** Net + VAT in minor units: what the customer is asked for, before any allocation. */
    public function grossMinor(): int
    {
        return $this->amount + $this->tax_amount;
    }

    /** The number a screen may print; a draft is addressed by its ULID until §7.4's number is spent. */
    public function reference(): string
    {
        return $this->number ?? 'draft '.substr($this->id, -8);
    }
}
