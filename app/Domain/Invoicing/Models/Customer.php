<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Policies\CustomerPolicy;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * CUSTOMER — the party an invoice is addressed to, and whose agreed rates a job is priced at.
 *
 * `payment_terms` is a business string (`cash` or `net_<days>`) rather than an enum, because terms
 * are negotiated per customer and an owner moving someone from 30 to 45 days must not need a code
 * change. The format is validated in {@see CustomerDto}; this class only
 * renders it.
 *
 * Nothing here is money: a customer has no credit limit yet (Phase 4 owns credit control) and the
 * rates live on `route_rates`. The one field hides is the terms themselves, and that is the
 * screen's job behind {@see FieldGate::SeeCustomerTerms}.
 *
 * @property int $id
 * @property int $company_id
 * @property string|null $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $vat_number
 * @property string $payment_terms
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(CustomerPolicy::class)]
final class Customer extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
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
            'active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->code !== null && $this->code !== ''
            ? $this->code.' · '.$this->name
            : $this->name;
    }

    /** "Cash on delivery", "Net 30 days" — how an invoice footer reads a stored term. */
    public function paymentTermsLabel(): string
    {
        return PaymentTerms::label($this->payment_terms);
    }
}
