<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One row of the amortisation plan (LOAN_INSTALLMENT). The frozen `principal_due`/
 * `interest_due` split is the answer to every "how much of that payment was interest" question,
 * and the paid counters make partial payments data rather than re-derivation — the same
 * write-time-decides principle as the FX block and the trip rollups.
 *
 * @property int $id
 * @property int $company_id
 * @property string $loan_id
 * @property int $seq
 * @property Carbon $due_on
 * @property int $principal_due
 * @property int $interest_due
 * @property int $total_due
 * @property int $paid_principal
 * @property int $paid_interest
 * @property InstallmentStatus $status
 * @property Carbon|null $paid_at
 * @property string|null $entry_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LoanInstallment extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'loan_installments';

    protected $fillable = [
        'loan_id',
        'seq',
        'due_on',
        'principal_due',
        'interest_due',
        'total_due',
        'paid_principal',
        'paid_interest',
        'status',
        'paid_at',
        'entry_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'seq' => 'integer',
            'due_on' => 'date',
            'principal_due' => 'integer',
            'interest_due' => 'integer',
            'total_due' => 'integer',
            'paid_principal' => 'integer',
            'paid_interest' => 'integer',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function outstandingMinor(): int
    {
        return ($this->principal_due - $this->paid_principal) + ($this->interest_due - $this->paid_interest);
    }

    /** True when the plan says money is still owed and the date has passed (the arrears trigger). */
    public function isPastDue(CarbonInterface $today): bool
    {
        return $this->status->isOutstanding() && $this->due_on->lessThan($today);
    }
}
