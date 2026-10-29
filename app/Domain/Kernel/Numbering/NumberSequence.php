<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The counter row and the lock target for one (company, scope, period) (§7.1).
 *
 * Company-scoped by T1: two companies must never share a sequence, or each one's fiscal
 * numbering depends on the other's activity.
 */
final class NumberSequence extends Model
{
    use BelongsToCompany;

    protected $table = 'number_sequences';

    protected $fillable = ['scope', 'period_key', 'next_value', 'format', 'last_used_at', 'last_used_by'];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
            'last_used_at' => 'datetime',
            'last_used_by' => 'integer',
        ];
    }
}
