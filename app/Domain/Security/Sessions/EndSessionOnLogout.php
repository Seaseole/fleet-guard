<?php

declare(strict_types=1);

namespace App\Domain\Security\Sessions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Models\UserSession;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Carbon;

/**
 * A signed-out device stops being an active device. Without this the screen would keep listing a
 * depot computer as holding a session for the rest of the idle window after the person walked away from
 * it — which is the one claim the list must not get wrong.
 *
 * `Guard::logout()` fires this before the framework touches the session store, so the id still belongs to
 * the session that just ended. The tombstone is the same one {@see TrackUserSessions} reads, so a device
 * cannot be signed out and still be usable.
 *
 * A queue worker has no session at all (`getId()` is null there), and a headless command that logs a user
 * in and out is not a device.
 */
final class EndSessionOnLogout
{
    public function __invoke(Logout $event): void
    {
        $user = $event->user;
        $sessionId = session()->getId();

        if ($sessionId === '' || CurrentCompany::tryId() === null) {
            return;
        }

        UserSession::query()
            ->where('id', UserSession::hash($sessionId))
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }
}
