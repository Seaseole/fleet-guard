<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Models;

use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Policies\DocumentPolicy;
use App\Domain\Kernel\Documents\RetentionClass;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * / DOCUMENT — one piece of paper, the versions of it we hold, and the two axes the design keeps
 * independent of each other.
 *
 * `lifecycle` is stored because superseding and archiving are decisions somebody made; `validity` is
 * derived because "is this still in force" is a question about a date and not about a decision, and
 * a stored answer would need a job to keep it true. That is the entire reason this row is separate
 * from its bytes.
 *
 * The subject is a polymorphic pair, which the design refuses for the ledger's *reporting* dimensions. The
 * difference is that a document's subject is never grouped by in a report — it is only followed back
 * to the row it evidences — so nothing is lost by keeping it polymorphic, and nothing is blocked:
 * trip, truck, customer and loan all arrive in later phases.
 *
 * @property string $id
 * @property int $company_id
 * @property DocumentType $type
 * @property string $title
 * @property string|null $morph_type
 * @property string|null $morph_id
 * @property Carbon|null $expires_on
 * @property Lifecycle $lifecycle
 * @property RetentionClass $retention_class
 * @property list<string>|null $visibility
 * @property bool $confidential
 * @property string|null $notes
 * @property Carbon|null $archived_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(DocumentPolicy::class)]
final class Document extends Model
{
    use BelongsToCompany;

    protected $table = 'documents';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `lifecycle`, `archived_at`, `voided_at` and `void_reason` are absent on purpose: they are the
     * transitions, and a screen that mass-assigns them can move a document to `voided` without
     * writing the event that says who did it and why. Those columns belong to the Actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id', 'type', 'title', 'morph_type', 'morph_id', 'expires_on',
        'retention_class', 'visibility', 'confidential', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'lifecycle' => Lifecycle::class,
            'retention_class' => RetentionClass::class,
            'visibility' => 'array',
            'confidential' => 'boolean',
            'expires_on' => 'date',
            'archived_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The id is the ULID the storage path is built from, so it is minted here and not
        // by each caller: a document whose key and stored path disagree is unreadable.
        self::creating(function (self $document): void {
            $document->id ??= (string) Str::ulid();
            $document->lifecycle ??= Lifecycle::Draft;
            $document->retention_class ??= RetentionClass::Operational;
        });
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_seq');
    }

    /**
     * The one current version, as a relation rather than a `->first()` over the versions:
     * the supersede step needs to ask "is there one" in SQL, and the database is what guarantees
     * there is at most one.
     *
     * @return HasOne<DocumentVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'morph_type', 'morph_id');
    }

    /**
     * The derived axis. A voided or archived document still reports what its date says:
     * validity answers "is this paper still in force" and the lifecycle answers "do we still rely
     * on it" — collapsing them would make `voided` read as `expired` on the expiry board.
     */
    public function validity(): Validity
    {
        /** @var Carbon|null $expiresOn */
        $expiresOn = $this->expires_on;

        return Validity::for($expiresOn);
    }

    /**
     * The role keys that may see this document. Empty means "holding `documents.view` is
     * enough", which is what keeps the column additive instead of a second permission system: a
     * document nobody has narrowed is visible to everyone the ability covers.
     *
     * @return list<string>
     */
    public function visibilityRoles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values(array_filter((array) ($this->visibility ?? []), 'is_string'));

        return $roles;
    }

    /**
     * The visibility rule as a WHERE clause, so the register can paginate in SQL.
     *
     * {@see DocumentPolicy::view()} remains the per-row authority: a row that is fetched has still
     * been checked against it before it is printed or downloaded. This scope is the same rule in
     * SQL — Owner bypass, unnamed-and-not-confidential, or the list naming one of the user's roles —
     * and the two must keep answering alike; loading the whole table to filter it in PHP is what
     * made the register's cost grow with the paper it holds.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(RoleKey::Owner->value)) {
            return $query;
        }

        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $visible) use ($roles): void {
            $visible->where(function (Builder $open): void {
                $open->where(function (Builder $unnamed): void {
                    $unnamed->whereNull('visibility')->orWhereRaw('json_length(visibility) = 0');
                })->where('confidential', false);
            });

            foreach ($roles as $role) {
                $visible->orWhereRaw('json_contains(visibility, json_quote(?))', [$role]);
            }
        });
    }
}
