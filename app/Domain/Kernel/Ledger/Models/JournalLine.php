<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Models;

use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\ImmutableLedgerException;
use App\Domain\Kernel\Money\MinorUnits;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a journal entry (§4.2 GL_JOURNAL_LINE, §6.2) — append-only with no exception: a
 * line is never rewritten, not even to correct it, because the entry's own totals would then stop
 * describing the lines underneath them.
 *
 * `direction` + a single amount rather than debit and credit columns, so "a line carrying both"
 * is unrepresentable instead of checked (§6.1). `amount_base` is authoritative; `amount_foreign`
 * is the number as the source document stated it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $entry_id
 * @property int $account_id
 * @property Side $direction
 * @property int $amount_base
 * @property int|null $amount_foreign
 * @property Carbon $posted_on
 * @property string $currency_code
 * @property string|null $line_note
 * @property array<string, mixed>|null $dimension_extra
 * @property Carbon|null $created_at
 */
final class JournalLine extends Model
{
    use BelongsToCompany;

    protected $table = 'gl_journal_lines';

    public $timestamps = false;

    protected $fillable = [
        'entry_id', 'account_id', 'direction', 'amount_foreign', 'amount_base', 'posted_on',
        'currency_code', 'line_note', 'truck_id', 'trailer_id', 'trip_id', 'job_id',
        'customer_id', 'supplier_id', 'driver_id', 'employee_id', 'work_order_id', 'invoice_id',
        'bill_id', 'fuel_issue_id', 'border_crossing_id', 'country_code', 'cost_centre_id',
        'dimension_extra', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'company_id' => 'integer',
            'account_id' => 'integer',
            'direction' => Side::class,
            'amount_base' => MinorUnits::class,
            // Not MinorUnits: that cast answers null with 0 (§20.4), and "no foreign amount" is a
            // different fact from "zero". The float guard belongs where the value is created —
            // LedgerService builds both amounts from Money, which already refuses floats.
            'amount_foreign' => 'integer',
            'posted_on' => 'date',
            'dimension_extra' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            throw ImmutableLedgerException::cannotUpdate($line, 'lines are append-only');
        });

        self::deleting(function (self $line): void {
            throw ImmutableLedgerException::cannotDelete($line);
        });
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function isDebit(): bool
    {
        return $this->direction === Side::Debit;
    }
}
