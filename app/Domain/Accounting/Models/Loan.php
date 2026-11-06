<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Policies\LoanPolicy;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A financing facility (LOAN). The terms are frozen at approval — the schedule rows
 * carry the only truth about what each period charges (never recomputed at report time) —
 * and the two ledger facts a loan owns are its disbursement entry id and the entries that paid
 * each instalment.
 *
 * @property string $id
 * @property int $company_id
 * @property string $reference
 * @property LoanLifecycle $status
 * @property LoanPurpose $purpose
 * @property int|null $supplier_id
 * @property int|null $truck_id
 * @property int|null $bank_account_id
 * @property string $currency_code
 * @property int $principal
 * @property string $fx_rate
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $base_principal
 * @property string $interest_rate
 * @property LoanFrequency $frequency
 * @property Carbon $first_payment_on
 * @property int $repayment_count
 * @property Carbon|null $disbursed_on
 * @property string|null $disbursement_entry_id
 * @property string|null $notes
 * @property string|null $source_uuid
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(LoanPolicy::class)]
final class Loan extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'loans';

    protected $fillable = [
        'id',
        'reference',
        'status',
        'purpose',
        'supplier_id',
        'truck_id',
        'bank_account_id',
        'currency_code',
        'principal',
        'fx_rate',
        'fx_rate_source',
        'fx_rate_date',
        'fx_rate_ref',
        'base_currency_code',
        'base_principal',
        'interest_rate',
        'frequency',
        'first_payment_on',
        'repayment_count',
        'disbursed_on',
        'disbursement_entry_id',
        'notes',
        'source_uuid',
        'created_by',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'status' => LoanLifecycle::class,
            'purpose' => LoanPurpose::class,
            'frequency' => LoanFrequency::class,
            'supplier_id' => 'integer',
            'truck_id' => 'integer',
            'bank_account_id' => 'integer',
            'principal' => 'integer',
            'base_principal' => 'integer',
            'interest_rate' => 'string',
            'fx_rate' => 'string',
            'repayment_count' => 'integer',
            'fx_rate_date' => 'date',
            'first_payment_on' => 'date',
            'disbursed_on' => 'date',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<LoanInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class, 'loan_id')->orderBy('due_on')->orderBy('seq');
    }

    /**
     * The rows of the *current* plan that still owe money — restructured rows are history.
     *
     * @return HasMany<LoanInstallment, $this>
     */
    public function outstandingInstallments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class, 'loan_id')
            ->whereIn('status', [
                InstallmentStatus::Scheduled->value,
                InstallmentStatus::Partial->value,
                InstallmentStatus::Overdue->value,
            ]);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** Sum of the current plan's unpaid principal+interest, in base minor units. */
    public function outstandingMinor(): int
    {
        $row = $this->outstandingInstallments()
            ->selectRaw('coalesce(sum(principal_due - paid_principal), 0) as p, coalesce(sum(interest_due - paid_interest), 0) as i')
            ->first();

        return $row === null ? 0 : (int) $row->getAttribute('p') + (int) $row->getAttribute('i');
    }
}
