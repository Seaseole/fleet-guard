<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §10.5 CREDIT_NOTE_LINE — the line a credit is for, and the invoice line it corrects.
 *
 * Dimensions are **copied from the invoice line at write time**, not read back at report time: a credit
 * raised in March against a leg corrected in June must still debit the same truck and trip, or that leg's
 * profitability silently gains the money back. `invoice_line_id` is unique per company, so one billed line
 * can be credited once — a second credit of it would drive revenue negative through a door nobody watches.
 *
 * @property string $id
 * @property int $company_id
 * @property string $credit_note_id
 * @property string|null $invoice_line_id
 * @property string $description
 * @property InvoiceLineKind $line_kind
 * @property string|null $trip_id
 * @property string|null $job_id
 * @property int|null $truck_id
 * @property string $revenue_account_code
 * @property string $currency_code
 * @property int $amount
 * @property int $base_amount
 * @property string|null $tax_rate_id
 * @property TaxRateKind|null $tax_kind
 * @property int $tax_amount
 * @property int $tax_base
 * @property int $position
 */
final class CreditNoteLine extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'credit_note_id', 'invoice_line_id', 'description', 'line_kind',
        'trip_id', 'job_id', 'truck_id', 'revenue_account_code',
        'currency_code', 'amount', 'base_amount',
        'tax_rate_id', 'tax_kind', 'tax_amount', 'tax_base', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'line_kind' => InvoiceLineKind::class,
            'truck_id' => 'integer',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'tax_kind' => TaxRateKind::class,
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
