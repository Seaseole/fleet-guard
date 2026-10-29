<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primes the company context at the start of every authenticated request and drops it at
 * the end. Under FPM the statics would reset anyway; doing it explicitly is what keeps the
 * context correct under Octane, where the process outlives the request (and a leaked
 * context is a cross-tenant read, which T3 exists to prevent).
 */
final class ResolveCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        CurrentCompany::forget();

        // Forgetting is the whole job: the resolver reads the authenticated user lazily, and
        // a context left over from a previous request is precisely the cross-tenant read T3
        // exists to prevent. The column is NOT NULL, so a logged-in user always resolves.
        CurrentCompany::tryId();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CurrentCompany::forget();
    }
}
