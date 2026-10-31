<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\CreditNoteLifecycle;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §10.5 CREDIT_NOTE — the separate document that corrects an invoice without touching it.
 *
 * The invoice stays `issued`; this note moves its derived settlement state instead. That separation is why
 * the correction has its own gap-free number: a note that silently un-issued an invoice would leave a
 * fiscal number pointing at nothing.
 *
 * `balance_amount` is what is left to apply. §10.5's rule is stated as data: an `issued` note must have a
 * balance, so a fully applied note is `applied` — that is the CHECK in the migration, and it is why this
 * row is written with its balance and its status in one insert.
 *
 * @property string $id
 * @property int $company_id
 * @property string|null $number
 * @property CreditNoteLifecycle $status
 * @property int $customer_id
 * @property string $invoice_id
 * @property string|null $credit_for
 * @property Carbon $issue_date
 * @property string $reason
 * @property string $currency_code
 * @property int $amount
 * @property string $fx_rate
 * @property string $base_currency_code
 * @property int $base_amount
 * @property int $tax_amount
 * @property int $tax_base
 * @property int $balance_amount
 * @property int $balance_base
 * @property string|null $narrative
 * @property int|null $issued_by
 * @property Carbon|null $issued_at
 */
final class CreditNote extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'number', 'status', 'customer_id', 'invoice_id', 'credit_for',
        'issue_date', 'reason',
        'currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'base_amount',
        'tax_amount', 'tax_base', 'balance_amount', 'balance_base',
        'narrative', 'source_uuid', 'issued_by', 'issued_at', 'cancelled_at', 'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => CreditNoteLifecycle::class,
            'customer_id' => 'integer',
            'issue_date' => 'date',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'balance_amount' => 'integer',
            'balance_base' => 'integer',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('position');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Net + VAT: what this document takes off the customer's account, before it is applied. */
    public function grossMinor(): int
    {
        return $this->amount + $this->tax_amount;
    }
}
