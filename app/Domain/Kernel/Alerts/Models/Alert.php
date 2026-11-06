<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Models;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Exceptions\AlertTransitionRefused;
use App\Domain\Kernel\Alerts\Notifications\AlertRaised;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * / ALERT — one thing that needs attention, and the record of what was done about it.
 *
 * `state`, every `*_at` and every `*_by` are absent from `$fillable` on purpose, exactly as
 * `lifecycle` is absent from {@see Document}: they are the
 * transitions, and a screen that mass-assigns them closes an alert without writing the event that
 * says who did it and why. Those columns belong to the Actions.
 *
 * There is no `subject()` morph relation, and that is a decision rather than an omission. `subject_type`
 * holds a domain word (`document`, `truck`, `invoice`) and not a class name, because the design's own column
 * pair has to survive the row it points at arriving in a later phase: a morphTo against `document`
 * would be a broken relation on every screen until someone guessed the mapping. The register renders
 * `payload.title` — which the emitter wrote, in the words it had when the condition fired — and a later
 * phase adds a per-type link resolver where the mapping is real.
 *
 * @property int $id
 * @property int $company_id
 * @property AlertType $type
 * @property AlertSeverity $severity
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property Carbon $occurred_on
 * @property string $cooldown_key
 * @property array<string, mixed>|null $payload
 * @property AlertState $state
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $notify_count
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $muted_until
 * @property int|null $muted_by
 * @property string|null $mute_reason
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property ActorKind|null $resolved_by_kind
 * @property string|null $resolve_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Alert extends Model
{
    use BelongsToCompany;

    protected $table = 'alerts';

    /**
     * How many times one condition may be resolved and come back before the engine stops appending rows
     * for it. Owned by the model because both ends of the cooldown need the same number: the raise door
     * to find the next free episode slot, and the self-check clear step to find the live row wherever it
     * sits. Two copies would drift, and the drift is silent — the second occurrence of a failing check
     * would simply never be closed.
     */
    public const Episodes = 12;

    /**
     * The cooldown keys one condition can occupy: the base key, then `base#2` … for every time the
     * finding was resolved and returned.
     *
     * Exact strings, never a prefix match: The design's own type names contain underscores, which `LIKE`
     * reads as "any character" — `doc_expiry` would collide with `docXexpiry`, and a key built that way
     * can also match a *different* condition's episodes and inflate them.
     *
     * @return list<string>
     */
    public static function episodeKeys(string $base): array
    {
        $keys = [$base];

        for ($episode = 2; $episode <= self::Episodes; $episode++) {
            $keys[] = $base.'#'.$episode;
        }

        return $keys;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type', 'severity', 'subject_type', 'subject_id', 'occurred_on', 'cooldown_key', 'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'state' => AlertState::class,
            'occurred_on' => 'date',
            'payload' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notify_count' => 'integer',
            'acknowledged_at' => 'datetime',
            'muted_until' => 'datetime',
            'resolved_at' => 'datetime',
            'resolved_by_kind' => ActorKind::class,
        ];
    }

    protected static function booted(): void
    {
        // `open` is the only state a row may be born in. An insert that arrived already acknowledged
        // would be a record of a decision nobody made, and the DB CHECK would refuse it for a reason
        // the caller cannot act on.
        self::creating(function (self $alert): void {
            $alert->state ??= AlertState::Open;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return BelongsTo<User, $this> */
    public function mutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Everything the register and the bell need: not finished, including the acknowledged and muted
     * rows that are still waiting on somebody.
     *
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('state', '!=', AlertState::Resolved->value);
    }

    /**
     * Mutes whose end has passed, so the sweep can reopen them (a mute that outlives its own
     * expiry is a resolve with a different name).
     *
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeMuteExpired(Builder $query): Builder
    {
        return $query->where('state', AlertState::Muted->value)
            ->whereNotNull('muted_until')
            ->where('muted_until', '<=', Carbon::now());
    }

    /**
     * The alert is still waiting on the thing it says it is waiting on.
     *
     * Used by the cooldown decision in {@see Actions\RaiseAlert}: a live row is refreshed rather than
     * duplicated, and a resolved row means the condition came back and needs its own record.
     */
    public function isLive(): bool
    {
        return $this->state->isLive();
    }

    /**
     * The one line the register and the bell show.
     *
     * Written by the emitter into `payload` when the condition fired, in the words it had at that
     * moment. It is not re-derived at render time: a row whose subject arrives in a later phase, or whose
     * title depends on a record that has since been renamed, would otherwise change what a historical
     * alert says. The register is a record of what was noticed, not a live join.
     */
    public function title(): string
    {
        $title = $this->payload['title'] ?? null;

        return is_string($title) && $title !== ''
            ? $title
            : sprintf('%s — %s', $this->type->label(), $this->occurred_on->toDateString());
    }

    /**
     * How many of this person's unread deliveries describe something still live (the bell).
     *
     * Both halves matter and they are different facts. The delivery is per person, which is why the dot
     * can be one clerk's and not another's; the alert's state is per company, which is why an alert
     * somebody resolved must stop counting for everyone — otherwise the register says "nothing open"
     * while the bell insists there are four things needing attention.
     *
     * Read the delivery ids first and the alerts second, rather than joining on a JSON path, because the
     * unread set is small by construction and a `data->alert_id` join would be an unindexable JSON
     * extraction on every page render of the topbar.
     */
    public static function countUnreadDeliveriesFor(User $user): int
    {
        $ids = $user->notifications()
            ->where('type', AlertRaised::class)
            ->whereNull('read_at')
            ->get()
            ->map(static fn (DatabaseNotification $delivery): mixed => $delivery->data['alert_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        return self::query()->live()->whereIn('id', $ids)->count();
    }

    /**
     * @throws AlertTransitionRefused
     */
    public function assertCanMoveTo(AlertState $to): void
    {
        if (! $this->state->canTransitionTo($to)) {
            throw AlertTransitionRefused::between($this->state, $to, (int) $this->id);
        }
    }
}
