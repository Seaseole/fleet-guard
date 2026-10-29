<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use App\Models\User;
use App\Providers\IdentityServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * One method per {@see FieldGate}, each of which is the whole of that gate's decision (§9.4).
 *
 * The rule the section is protecting: a Blade component never writes an ad-hoc `@can(...)`
 * expression around a sensitive column, and a report export never re-selects columns by hand.
 * Both ask a gate, so a field has exactly one owner and `Gate::allows('seeBankDetail')` means the
 * same thing in a table cell, a PDF and a CSV.
 *
 * Each method takes only the user. When a gate needs the row — "own truck", "own trip", the
 * kiosk-token cases in §9.6 — the subject argument is added here and nowhere else, so the
 * row-scoped half of a field rule still resolves to one method.
 */
final class FieldGatePolicy
{
    public function seeTripMargin(?User $user): bool
    {
        return $this->opens($user, FieldGate::SeeTripMargin);
    }

    public function seeVehicleCost(?User $user): bool
    {
        return $this->opens($user, FieldGate::SeeVehicleCost);
    }

    public function seePayroll(?User $user): bool
    {
        return $this->opens($user, FieldGate::SeePayroll);
    }

    /**
     * §9.5: the masked column exists so lists, sorting and search keep working without this gate.
     * Opening it decrypts on demand, which is why it is also on the re-auth list in the catalogue.
     */
    public function seeBankDetail(?User $user): bool
    {
        return $this->opens($user, FieldGate::SeeBankDetail);
    }

    public function seeCustomerTerms(?User $user): bool
    {
        return $this->opens($user, FieldGate::SeeCustomerTerms);
    }

    /**
     * Bind every gate from the enum, so adding a case adds the ability — there is no second list
     * to forget. Called from {@see IdentityServiceProvider::boot()}.
     */
    public static function register(): void
    {
        foreach (FieldGate::cases() as $gate) {
            Gate::define($gate->value, [self::class, $gate->name]);
        }
    }

    /**
     * Guests, kiosk tokens and the scheduler have no `User` at all, so they close every gate
     * rather than reaching a permission lookup that would throw inside a queued export.
     */
    private function opens(?User $user, FieldGate $gate): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can($gate->permission());
    }
}
