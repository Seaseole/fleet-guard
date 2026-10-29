<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Models;

use App\Domain\Kernel\Ledger\Exceptions\ImmutableLedgerException;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\MinorUnits;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The journal entry header (§4.2 GL_JOURNAL_ENTRY) — the row the DB CHECK balances and the
 * triggers freeze (§6.3 layers 2–4), and the row this class refuses to update or delete in code
 * (layer 1, the layer that produces a readable exception instead of an SQLSTATE).
 *
 * The one permitted transition is away from `draft`: {@see LedgerService}
 * writes the draft, appends its lines, then flips it to `posted` with the totals that the CHECK
 * then verifies. A `posted` entry is never touched again — §6.4 corrects it with a reversal.
 *
 * @property string $id
 * @property int $company_id
 * @property string $entry_no
 * @property Carbon $posted_at
 * @property Carbon $posted_on
 * @property int $period_id
 * @property string $status
 * @property string $description
 * @property string $currency_code
 * @property string $fx_rate
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property string|null $fx_rate_ref
 * @property string $base_currency_code
 * @property int $total_debit
 * @property int $total_credit
 * @property int $line_count
 * @property string $source_type
 * @property string $source_id
 * @property string $source_event_id
 * @property string|null $reverses_entry_id
 * @property string|null $netting_group
 * @property int|null $created_by
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
final class JournalEntry extends Model
{
    use BelongsToCompany;

    public const Draft = 'draft';

    public const Posted = 'posted';

    public const Void = 'void';

    protected $table = 'gl_journal_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Deliberately narrow. `total_debit`, `total_credit` and `line_count` are absent because they
     * are the poster's computed values, not user input — mass-assignable totals would be a
     * screen's way of writing an entry that the CHECK then refuses, which is a confusing error
     * instead of an impossible one.
     */
    protected $fillable = [
        'id', 'entry_no', 'posted_at', 'posted_on', 'period_id', 'status', 'description',
        'currency_code', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref',
        'base_currency_code', 'source_type', 'source_id', 'source_event_id',
        'reverses_entry_id', 'netting_group', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'period_id' => 'integer',
            'created_by' => 'integer',
            'posted_at' => 'datetime',
            'posted_on' => 'date',
            'fx_rate_date' => 'date',
            'fx_rate' => 'decimal:8',
            'voided_at' => 'datetime',
            // MinorUnits, not integer: the totals are the poster's computed values and a float
            // reaching them means a screen was thinking in major units (§5.1, §20.4).
            'total_debit' => MinorUnits::class,
            'total_credit' => MinorUnits::class,
            'line_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // §6.3 layer 1, matching the trigger exactly: an unpublished entry is still the poster's
        // to finish; a posted or voided one is history. getOriginal() rather than the in-memory
        // attribute, because a caller that assigned ->status itself must not be able to describe
        // its own way past this.
        self::updating(function (self $entry): void {
            $from = (string) $entry->getOriginal('status');
            $to = (string) $entry->getAttribute('status');

            // Exactly the transition the DB trigger permits. A draft is not edited in place — the
            // poster writes it once, appends lines, and flips it; anything looser here would be
            // allowed by the model and then refused by the trigger, which is a confusing failure
            // instead of an impossible one.
            if ($from === self::Draft && in_array($to, [self::Posted, self::Void], true)) {
                return;
            }

            throw ImmutableLedgerException::cannotUpdate($entry, "only draft -> posted|void is legal, not {$from} -> {$to}");
        });

        self::deleting(function (self $entry): void {
            throw ImmutableLedgerException::cannotDelete($entry);
        });
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === self::Draft;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::Draft, self::Posted, self::Void];
    }
}
