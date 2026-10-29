<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An accounting month (§6.5 GL_PERIOD). A row rather than a date calculation so that "2026-10 is
 * locked" is data an auditor can read, and so the lock trigger is a single-row lookup.
 *
 * The three statuses mean different things and are not a flag: `open` accepts postings, `closed`
 * is the accountant's own line that Phase 5's close validation sets (and the app refuses to post
 * into), `locked` is the state the DB trigger refuses. Only `locked` is enforced below the
 * application, because that is the one that must survive a panic edit in a SQL client.
 *
 * @property int $id
 * @property int $company_id
 * @property string $period
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 */
final class Period extends Model
{
    use BelongsToCompany;

    protected $table = 'gl_periods';

    protected $fillable = ['period', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /** @return HasMany<JournalEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'period_id');
    }
}
