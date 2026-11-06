<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INVOICE_LINE — one billed line, and the leg of the fleet it earned money on.
 *
 * The line carries `trip_id` and `truck_id` because `4100 Haulage revenue` requires truck, trip **and**
 * customer (the mask): a consolidated invoice cannot post one lump credit to it, so the dimension has
 * to survive at line level or the revenue block names a customer and no leg. `revenue_account_code` is
 * copied at write time — if the chart is re-coded next year, rows already posted must still say what they
 * posted to.
 *
 * VAT is stored per line rather than derived at print time (sum of rounded line amounts, never
 * round-of-sum), so an invoice can never disagree with the return filed from it.
 *
 * @property string $id
 * @property int $company_id
 * @property string $invoice_id
 * @property string $description
 * @property InvoiceLineKind $line_kind
 * @property string|null $chargeable_id
 * @property string|null $trip_id
 * @property string|null $job_id
 * @property int|null $truck_id
 * @property string $revenue_account_code
 * @property int $quantity_x100
 * @property int $unit_price_minor
 * @property string $currency_code
 * @property int $amount
 * @property int $base_amount
 * @property string|null $tax_rate_id
 * @property TaxRateKind|null $tax_kind
 * @property int $tax_amount
 * @property int $tax_base
 * @property int $position
 */
final class InvoiceLine extends Model
{
    use BelongsToCompany;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'invoice_id', 'description', 'line_kind',
        'chargeable_id', 'trip_id', 'job_id', 'truck_id', 'revenue_account_code',
        'quantity_x100', 'unit_price_minor', 'currency_code', 'amount', 'base_amount',
        'tax_rate_id', 'tax_kind', 'tax_amount', 'tax_base', 'position',
        // Set by a void, so the line stops competing for its chargeable. Never mass-assigned by a
        // screen: `VoidInvoice` is the only writer.
        'released_at',
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
            'quantity_x100' => 'integer',
            'unit_price_minor' => 'integer',
            'amount' => 'integer',
            'base_amount' => 'integer',
            'tax_kind' => TaxRateKind::class,
            'tax_amount' => 'integer',
            'tax_base' => 'integer',
            'position' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // `chargeable_id`, `trip_id` and `job_id` are Operations' rows and carry no relations here (D5): the
    // line names the charge it billed and the leg it earned on, and Invoicing never holds another module's
    // model. The one relation below is its own.

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
