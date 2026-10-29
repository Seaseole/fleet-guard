<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The throttle for Fortify's two unthrottled confirmation doors.
 *
 * It is a web-group middleware rather than `throttle:` on the route, because Fortify's `limiters` config
 * covers only login, the TOTP challenge and passkeys: the two routes below are registered by the vendor
 * with a fixed middleware list, and a boot-time patch of a vendor route was measured not to work here —
 * `Fortify`'s routes are not yet in the collection when `booted()` callbacks run, so the patch silently
 * found nothing. A route-name match inside the pipeline is the version of this that can be verified.
 *
 * This door is charged per attempt rather than per failure, and shares its key with
 * {@see ConfirmationThrottle}, which charges the Livewire enrolment modal per failure. Mixed accounting on
 * one bucket only ever tightens it, so splitting guesses across the raw route and the component buys an
 * attacker nothing.
 */
final class LimitConfirmationAttempts
{
    /** @var array<string, string> which field carries the refusal for each door */
    private const FIELDS = [
        ConfirmationThrottle::REAUTH => 'password',
        ConfirmationThrottle::TWO_FACTOR_CONFIRM => 'code',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $door = match (true) {
            $request->routeIs('password.confirm.store') => ConfirmationThrottle::REAUTH,
            $request->routeIs('two-factor.confirm') => ConfirmationThrottle::TWO_FACTOR_CONFIRM,
            default => null,
        };

        if ($door === null) {
            return $next($request);
        }

        $key = ConfirmationThrottle::keyFor($door, $request->user()?->id, $request->ip());

        if (RateLimiter::tooManyAttempts($key, ConfirmationThrottle::maxAttempts())) {
            throw ValidationException::withMessages([
                self::FIELDS[$door] => ConfirmationThrottle::refusedMessage($key),
            ]);
        }

        try {
            return $next($request);
        } finally {
            // Charged per attempt rather than per failure, because a wrong password here arrives as a
            // redirect with an error bag, not as an exception a middleware can see. Five a minute is
            // generous for a gate a person confirms once per session, and it is the same shape Fortify
            // already uses for the email-verification resend (`throttle:6,1`).
            RateLimiter::hit($key, ConfirmationThrottle::decaySeconds());
        }
    }
}
