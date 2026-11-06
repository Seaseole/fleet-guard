<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Security\Models\UserSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "log out this device", and the rule that a state change is an event with an actor: the
 * tombstone is one write, the `security_events` row is the other, and they commit together.
 *
 * A tombstone rather than a delete because `TrackUserSessions` adopts a session it has never seen —
 * otherwise "revoked" and "signed in before this table existed" are the same query result, and the
 * second one would be logged out.
 */
final class RevokeSession
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function __invoke(User $actor, string $sessionHash): UserSession
    {
        return DB::transaction(function () use ($actor, $sessionHash): UserSession {
            /** @var UserSession|null $row */
            $row = UserSession::query()
                ->where('id', $sessionHash)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new NotFoundHttpException('That session is not yours, or it has already gone.');
            }

            abort_if($row->isRevoked(), 409, 'That session is already revoked.');

            $row->forceFill(['revoked_at' => Carbon::now()])->save();

            // Inside the transaction,: a revocation whose audit row was lost is a device that
            // was cut off and cannot be explained afterwards.
            $this->audit->security(SecurityEventKind::SessionRevoked, [
                'session' => $row->id,
                'signed_in_at' => $row->created_at->toIso8601String(),
                'target_user' => $actor->id,
            ]);

            return $row;
        });
    }
}
