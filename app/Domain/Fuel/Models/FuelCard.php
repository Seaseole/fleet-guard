<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §4.3's `fuel_card_id` needs a row: a pump card the fleet holds, named by an alias a clerk can
 * recognise and a §9.5 trio that never owns the number in a plain column.
 *
 * The write path for the trio arrives with the card-management screen; until then nothing in this
 * build creates a card, and the table's CHECK refuses a row whose "encrypted" column is no longer
 * than its masked one — the cheap half of the rule, enforced where a careless writer cannot skip it.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $issuer_supplier_id
 * @property string $alias
 * @property string $card_ref_encrypted
 * @property string $card_ref_masked
 * @property string $key_version
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FuelCard extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'issuer_supplier_id',
        'alias',
        'card_ref_encrypted',
        'card_ref_masked',
        'key_version',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'issuer_supplier_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'issuer_supplier_id');
    }

    public function label(): string
    {
        return $this->alias;
    }
}
