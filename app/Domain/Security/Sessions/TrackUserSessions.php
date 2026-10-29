<?php

declare(strict_types=1);

namespace App\Domain\Security\Sessions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Security\Models\UserSession;
use App\Domain\Security\SessionRegistry;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §9.2's session clock, and the only place the 12-hour absolute bound has ever existed in this
 * application — `config/session.lifetime` is an idle timeout, so a tab that gets clicked every hour has
 * never expired.
 *
 * **One clock, one comparison.** `expires_at` is written as `min(idle deadline, sign-in + absolute cap)`,
 * so this middleware asks a single question of the row. Two comparisons on two columns is two rules that
 * can disagree, and the disagreement is always resolved by whoever is debugging it at midnight.
 *
 * **A row per request is the cost of a device list that survives Cloud.** Locally `SESSION_DRIVER` is the
 * database table; in production it is Valkey, where there is no way to ask "which sessions belong to this
 * user" without a scan. Adopting the row here and touching it here is what makes the screen and the
 * revocation path behave identically on both, and it replaces no write that was not already happening —
 * the session payload is persisted on every request either way.
 *
 * Runs after `ResolveCurrentCompany`: the lookup is company-scoped, and a scoped read with no context
 * throws (T3) rather than returning somebody else's device.
 */
final class TrackUserSessions
{
    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $row = $this->record($request, $user);

        if (! $row->isUsable()) {
            $this->cut($request, $row, $user);
        }

        return $next($request);
    }

    private function record(Request $request, User $user): UserSession
    {
        $sessionId = $request->session()->getId();
        $hash = UserSession::hash($sessionId);

        /** @var UserSession|null $existing */
        $existing = UserSession::query()->where('id', $hash)->first();

        if ($existing !== null) {
            return $this->registry->touch($existing, $user, $request->ip(), $request->userAgent());
        }

        try {
            return $this->registry->adopt($user, $sessionId, $request->ip(), $request->userAgent());
        } catch (UniqueConstraintViolationException) {
            // Two parallel first requests from one brand-new session — `wire:navigate` plus a poll makes
            // this ordinary. The row that won is the record; the loser adopts nothing.
            return UserSession::query()->where('id', $hash)->firstOrFail();
        }
    }

    private function cut(Request $request, UserSession $row, User $user): never
    {
        $revoked = $row->isRevoked();

        // §11.2 puts this table's kind as `session_revoked` and the DB CHECK will not accept another
        // word, so `reason` carries the distinction the reviewer asks for: was a person or a clock
        // responsible?
        $this->audit->security(SecurityEventKind::SessionRevoked, [
            'session' => $row->id,
            'reason' => $revoked ? 'revoked' : 'expired',
            'signed_in_at' => $row->created_at->toIso8601String(),
            'target_user' => $row->user_id,
        ]);

        if ($revoked) {
            // A revoked device must not walk back in through its remember-me cookie: `logout()` clears
            // this browser's cookie, but the value that validates it lives on the user row and is shared
            // by every device that was ever tickled "remember me". Nulling it is the only way to retire
            // that cookie server-side. It costs the other devices their auto-login — not their sessions.
            // An expired clock does not get this: signing in again is exactly what a clock expects.
            $user->forceFill(['remember_token' => null])->save();
        }

        auth('web')->logout();

        $request->session()->flush();
        $request->session()->regenerateToken();

        throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
    }
}
