<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Actions;

use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\Exceptions\AlertTransitionRefused;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What a person does to an alert: acknowledge it, mute it, resolve it (§10).
 *
 * One action for the three exits, because the rules are the same three rules asked in a different
 * order — is the move legal, does it need a reason, which columns does it date itself with. Three
 * near-identical classes would each have to remember §10.1's reason rule, and a rule remembered in
 * three places is a rule that drifts in two. This is {@see
 * \App\Domain\Kernel\Documents\Actions\TransitionDocument}'s shape for the same reason.
 *
 * `open` is not offered as a target. A mute ending is the sweep's transition, made by the clock rather
 * than by a clerk, and an un-acknowledge is not a decision anyone needs to make.
 *
 * The reason is checked **before** the update, and the DB CHECK then refuses the row that follows.
 * That order is not belt-and-braces: without the first check the alert would move and the trail would
 * fail, leaving the state changed with nothing saying who did it — the precise shape of the hole §10.1
 * closes.
 */
final class TransitionAlert
{
    /**
     * @var list<AlertState>
     */
    private const Targets = [AlertState::Acknowledged, AlertState::Muted, AlertState::Resolved];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        Alert $alert,
        AlertState $to,
        ?string $reason = null,
        ?CarbonInterface $mutedUntil = null,
        ?int $byUserId = null,
        ActorKind $actorKind = ActorKind::User,
    ): Alert {
        if (! in_array($to, self::Targets, true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not a state a person puts an alert into. An expired mute is reopened by the sweep — '
                .'nothing needs to set %s by hand (§10).',
                $to->value,
                AlertState::Open->value,
            ));
        }

        $from = $alert->state;

        $alert->assertCanMoveTo($to);

        $reason = $reason === null ? null : trim($reason);

        if ($to->requiresReason() && ($reason === null || $reason === '')) {
            throw AlertTransitionRefused::withoutReason($to, (int) $alert->id);
        }

        if ($to === AlertState::Muted && $mutedUntil === null) {
            throw new \InvalidArgumentException(
                'A mute needs an end. §10 says muting carries an expiry "so muting cannot silently become '
                .'permanent" — an open-ended mute is a resolved alert with no reason attached.'
            );
        }

        // The scheduler is allowed exactly one of these exits, and only because it owns the findings it
        // raised: §14.6's self-check alert must close itself when the next run comes back clean, or the
        // register fills with accurate rows about problems that no longer exist. Acknowledging and
        // muting both say "a person has decided this can wait", which is not something a cron entry gets
        // to claim.
        if ($actorKind !== ActorKind::User && $to !== AlertState::Resolved) {
            throw new \InvalidArgumentException(sprintf(
                'The %s principal cannot move an alert to %s. Only a person acknowledges or mutes; the '
                .'scheduler may only close a finding its own next run disproved (§14.6).',
                $actorKind->value,
                $to->value,
            ));
        }

        // `Auth::id()` answers mixed on a guard-agnostic call, and the trail's actor column is an int:
        // narrowing here is what lets the audit recorder accept it without a cast at the write.
        $userId = $byUserId ?? ($actorKind === ActorKind::User ? Auth::id() : null);
        $userId = $userId === null ? null : (int) $userId;

        if ($actorKind === ActorKind::User && $userId === null) {
            throw new \InvalidArgumentException(
                'An alert decision needs a person. There is no authenticated user to attribute this to, and a '
                .'trail row with a null actor answers the only question the trail exists to ask.'
            );
        }

        DB::transaction(function () use ($alert, $from, $to, $reason, $mutedUntil, $userId, $actorKind): void {
            $alert->forceFill(match ($to) {
                AlertState::Muted => [
                    'state' => $to,
                    'muted_until' => $mutedUntil,
                    'muted_by' => $userId,
                    'mute_reason' => $reason,
                ],
                AlertState::Resolved => [
                    'state' => $to,
                    'resolved_at' => now(),
                    'resolved_by' => $userId,
                    'resolved_by_kind' => $actorKind,
                    'resolve_reason' => $reason,
                ],
                AlertState::Acknowledged => [
                    'state' => $to,
                    'acknowledged_at' => now(),
                    'acknowledged_by' => $userId,
                ],
            })->save();

            $this->audit->operational(
                'alert',
                (string) $alert->id,
                $from->value,
                $to->value,
                $reason,
                [
                    'type' => $alert->type->value,
                    'severity' => $alert->severity->value,
                    'cooldown_key' => $alert->cooldown_key,
                ],
                $actorKind,
                $userId,
            );
        });

        return $alert;
    }
}
