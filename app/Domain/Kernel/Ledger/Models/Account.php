<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Models;

use App\Domain\Kernel\Ledger\Data\AccountType;
use App\Domain\Kernel\Ledger\Data\Dimension;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chart-of-accounts row (§4.2 GL_ACCOUNT, §6.1) — a tree, so a trial balance can roll up
 * without a report-definition table.
 *
 * `is_postable` false marks the header rows that group but never take a line; `is_system` marks
 * the seeded accounts that cannot be deleted or re-coded, which is what lets Phase 0.5 post
 * opening balances against known codes rather than known ids.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property Side $normal_balance
 * @property int|null $parent_id
 * @property bool $is_system
 * @property bool $is_postable
 * @property bool $is_active
 * @property int $allowed_dimension_mask
 * @property string|null $currency_code
 * @property string|null $external_ref
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'parent_id',
        'is_system',
        'is_postable',
        'is_active',
        'allowed_dimension_mask',
        'default_tax_rate_id',
        'currency_code',
        'external_ref',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'parent_id' => 'integer',
            'type' => AccountType::class,
            'normal_balance' => Side::class,
            'is_system' => 'boolean',
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
            'allowed_dimension_mask' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function isHeader(): bool
    {
        return ! $this->is_postable;
    }

    /**
     * The dimensions every line posted here must carry (§6.1, {@see Dimension}).
     *
     * @return list<Dimension>
     */
    public function requiredDimensions(): array
    {
        return Dimension::listFrom($this->allowed_dimension_mask);
    }

    public function requiresDimension(Dimension $dimension): bool
    {
        return Dimension::isIn($this->allowed_dimension_mask, $dimension);
    }
}
