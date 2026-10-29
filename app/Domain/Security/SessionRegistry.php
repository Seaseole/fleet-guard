<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Security\Models\UserSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * §9.2's session record: the two writes that are bookkeeping rather than business — adopting a device
 * the first time the middleware sees it, and moving its clock on activity.
 *
 * Revocation is deliberately **not** here: it is a security decision with an actor and a reason, so it is
 * an Action and it lands a `security_events` row. This class is what runs on every request, and a
 * per-request path that writes an audit row is a table that fills up with noise.
 */
final class SessionRegistry
{
    public function adopt(User $user, string $sessionId, ?string $ip, ?string $agent): UserSession
    {
        $now = Carbon::now();
        $idle = $this->idleDeadline($now);

        return UserSession::forceCreate([
            'id' => UserSession::hash($sessionId),
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'ip_address' => $ip === null ? null : substr($ip, 0, 45),
            'user_agent' => $agent === null ? null : substr($agent, 0, 512),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $this->cap($idle, $now),
            'two_factor_ok' => $this->factorSatisfied($user),
        ]);
    }

    public function touch(UserSession $session, User $user, ?string $ip, ?string $agent): UserSession
    {
        $now = Carbon::now();

        $session->forceFill([
            'last_seen_at' => $now,
            'expires_at' => $this->cap($this->idleDeadline($now), $session->created_at),
            'ip_address' => $ip === null ? $session->ip_address : substr($ip, 0, 45),
            'user_agent' => $agent === null ? $session->user_agent : substr($agent, 0, 512),
            // A one-way latch: the invited user who signs in before enrolling a factor has one minute of
            // being honestly recorded as "no factor", and after enrolment the device should stop saying
            // that. `hasEnabledTwoFactorAuthentication()` is two columns, so this costs no query — the
            // full predicate, including the role question, only runs once per session at adoption.
            'two_factor_ok' => $session->two_factor_ok || $user->hasEnabledTwoFactorAuthentication(),
        ])->save();

        return $session;
    }

    private function idleDeadline(CarbonInterface $from): CarbonInterface
    {
        return $from->copy()->addMinutes((int) config('session.lifetime', 120));
    }

    /**
     * The absolute cap (§9.2: idle 2 h, absolute max 12 h) is measured from the sign-in, so the deadline
     * a device can reach never passes the deadline the day created.
     */
    private function cap(CarbonInterface $idle, CarbonInterface $signedInAt): CarbonInterface
    {
        $absolute = $signedInAt->copy()->addSeconds((int) config('fleet.identity.session_absolute_seconds'));

        return $idle->lessThan($absolute) ? $idle : $absolute;
    }

    /**
     * Was a second factor actually presented for this device?
     *
     * The passkey branch is not decoration: a passkey *is* the phishing-resistant factor Fortify's
     * first-party passkeys exist to be, so recording such a sign-in as "no second factor" would be a
     * wrong claim on a screen whose only job is to tell the Owner which devices to distrust.
     */
    private function factorSatisfied(User $user): bool
    {
        if (! $user->requiresMfa()) {
            return true;
        }

        return $user->hasEnabledTwoFactorAuthentication() || $user->passkeys()->exists();
    }
}
