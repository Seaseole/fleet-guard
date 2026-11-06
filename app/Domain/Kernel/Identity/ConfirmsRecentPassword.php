<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

/**
 * The ⚑ rule of for an action that cannot sit on a route.
 *
 * `reauth` is route middleware, and that is the right home when the whole route is the sensitive
 * act (posting a manual journal). Most ⚑ abilities are not like that: they are one button on a
 * screen whose every other read is ordinary, and the "hide the field, not the row" means a
 * dispatcher may open the fleet page without being asked for a password. So the action asks, using
 * the clock Fortify's own RequirePassword middleware writes — `auth.password_confirmed_at` — rather
 * than keeping a second notion of "recently confirmed" that would drift from the one the browser
 * follows after a password prompt.
 *
 * `security:audit` treats a ⚑ permission reference without one of these calls (or a route-level
 * `reauth`) as a finding, so a new sensitive action cannot quietly skip the rule.
 *
 * For use from a Livewire component: the redirect is the component's, and the caller must return
 * immediately when this answers false, leaving its state — and the database — untouched.
 */
trait ConfirmsRecentPassword
{
    /**
     * @param  string|null  $intended  where to return after the prompt; the current URL by default
     * @return bool true when the password was confirmed inside the window
     */
    protected function confirmRecentPassword(?string $intended = null): bool
    {
        $confirmed = (int) session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmed <= (int) config('fleet.identity.reauth_window_seconds')) {
            return true;
        }

        session(['url.intended' => $intended ?? url()->current()]);
        $this->redirectRoute('password.confirm');

        return false;
    }
}
