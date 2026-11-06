<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Policies\SupplierPolicy;
use App\Domain\Accounting\SupplierCategory;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * SUPPLIER — the party that bills the fleet (`SUPPLIER ||--o{ BILL`).
 *
 * `category` is the bill vocabulary, so a supplier row can default a bill's expense account
 * instead of asking the clerk every time. It is not a constraint on what the supplier may sell —
 * a parts shop occasionally does labour — it is what most bills from them are.
 *
 * No bank details: paying a supplier is a Phase 5 concern with its own gate and trio.
 *
 * @property int $id
 * @property int $company_id
 * @property string|null $code
 * @property string $name
 * @property SupplierCategory $category
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $vat_number
 * @property string $payment_terms
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(SupplierPolicy::class)]
final class Supplier extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'category',
        'contact_name',
        'contact_email',
        'contact_phone',
        'vat_number',
        'payment_terms',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'category' => SupplierCategory::class,
            'active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->code !== null && $this->code !== ''
            ? $this->code.' · '.$this->name
            : $this->name;
    }

    public function paymentTermsLabel(): string
    {
        return PaymentTerms::label($this->payment_terms);
    }
}
