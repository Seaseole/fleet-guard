<?php

declare(strict_types=1);

namespace App\Domain\Operations\Kiosk;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\RedeemKioskToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §9.6's door: turn the URL's secret into a live token, or answer 404 and say nothing more.
 *
 * Three things happen here that every kiosk screen would otherwise have to remember to do, which is the
 * reason they are in a middleware rather than in a component:
 *
 * 1. the company context is established from the route. `ResolveCurrentCompany` reads the authenticated
 *    user, and a driver has none (ADR-0006) — so a kiosk request that skipped this would either throw
 *    §T2's fail-closed guard or, worse, run unscoped;
 * 2. the attempt counter (§9.6's "rate-limited on redemption") runs before any lookup, so a brute-force
 *    walk of 6-digit PINs is expensive per IP rather than free;
 * 3. `last_used_at` / `last_ip` are stamped once, on entry, instead of in each Action that might forget.
 *
 * A dead link is a 404, not a 403 with a reason. "Revoked" and "expired" are useful to the driver and
 * useless to nobody else, but a page that says *which* one it was also tells an outsider that the string
 * they guessed is a real token that simply aged out.
 */
final class EnsureKioskToken
{
    public function __construct(private readonly RedeemKioskToken $redeem) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = (int) $request->route('company');

        if ($companyId <= 0) {
            abort(404);
        }

        $secret = (string) $request->route('secret');

        try {
            $token = ($this->redeem)->fromSecret($companyId, $secret, (string) $request->ip());
        } catch (\InvalidArgumentException) {
            CurrentCompany::forget();

            abort(404);
        }

        $request->attributes->set('kioskToken', $token);
        $request->attributes->set('kioskSecret', $secret);

        return $next($request);
    }
}
