<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMultiFactorAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        // Livewire replays this middleware against the original route after verifying its snapshot.
        if ($request->routeIs('*livewire.update')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresMfa()) {
            return $next($request);
        }

        abort_if($request->routeIs('two-factor.disable'), 403, 'Two-factor authentication is required for your role.');

        if ($user->hasEnabledTwoFactorAuthentication() || $request->routeIs(
            'security.edit',
            'password.confirm',
            'password.confirm.store',
            'password.confirmation',
            'verification.*',
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
            'two-factor.regenerate-recovery-codes',
            'logout',
        )) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => 'Two-factor authentication enrolment is required.'], 403);
        }

        return redirect()->route('security.edit');
    }
}
