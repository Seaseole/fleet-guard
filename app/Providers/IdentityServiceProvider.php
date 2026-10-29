<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Identity\FieldGatePolicy;
use App\Domain\Security\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Wires the identity layer: the five §9.4 field gates, plus the policy for the one model the
 * starter kit owns.
 *
 * The gates are registered from the {@see FieldGate} enum rather than
 * listed here, so adding a gate means adding one enum case and one policy method. A provider that
 * named each gate separately would be a second list to keep in step — the failure mode §9.4 exists
 * to avoid.
 *
 * `BankAccount` and `Invitation` are absent on purpose: they carry `#[UsePolicy(…)]`, which is how
 * Laravel 13 finds a policy that lives beside its module (§13) rather than in `App\Policies`.
 * `User` is registered by hand because auto-discovery would look for `App\Policies\UserPolicy`,
 * and this project does not put a Security rule in a kit-shaped directory.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FieldGatePolicy::register();
        Livewire::addPersistentMiddleware([EnsureMultiFactorAuthentication::class, RequirePassword::class]);

        Gate::policy(User::class, UserPolicy::class);

        foreach (['queues.view', 'queues.retry'] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->company_id === config('fleet.queue_health.company_id')
                && $user->hasRole('owner') && $user->hasPermissionTo($ability));
        }
    }
}
