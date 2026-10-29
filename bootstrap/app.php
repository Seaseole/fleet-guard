<?php

use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\LimitConfirmationAttempts;
use App\Domain\Kernel\Tenancy\ResolveCurrentCompany;
use App\Domain\Operations\Kiosk\EnsureKioskToken;
use App\Domain\Security\Sessions\TrackUserSessions;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Added by EnvKit so shared (public) URLs keep the https scheme and
        // public host. Safe locally; remove to opt out.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'company' => ResolveCurrentCompany::class,

            // §9.6 — the driver's door. Not `auth`: a driver has no account (ADR-0006), and this
            // middleware is what establishes the company context the tenancy guard otherwise reads off
            // the authenticated user.
            'kiosk' => EnsureKioskToken::class,

            // §9.1 — Fortify's password confirmation behind a named gate, so the abilities that
            // PermissionCatalogue::requiresReauth() marks ⚑ carry the requirement on the route
            // where a reviewer can see it, rather than in a component that might remember to ask.
            'reauth' => RequirePassword::class,
        ]);

        // T2: every web request resolves the company from the authenticated user, so no
        // controller has to remember to. Guest routes get a null context, which is exactly
        // what makes a scoped business query throw instead of leaking rows.
        //
        // The session clock runs before the factor gate: a revoked device is cut off even on the
        // enrolment routes the gate exists to allow through.
        $middleware->web(append: [
            ResolveCurrentCompany::class,
            TrackUserSessions::class,
            LimitConfirmationAttempts::class,
            EnsureMultiFactorAuthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
