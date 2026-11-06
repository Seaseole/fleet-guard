<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use App\Models\User;

/**
 * The "Salaries, advances, settlements — F/F": the Accountant and the Owner record and sign
 * settlements (`payroll.manage`, which the catalogue marks ⚑ — the settle screen re-authenticates),
 * everyone else with `payroll.view` may read them. Drivers hold `settlements.view.own`, and that
 * door is a kiosk screen, not this one (drivers have no accounts to log into).
 */
final class TripSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.view');
    }

    public function view(User $user, TripSettlement $settlement): bool
    {
        return $settlement->company_id === $user->company_id && $user->can('payroll.view');
    }

    public function record(User $user): bool
    {
        return $user->can('payroll.manage');
    }

    public function settle(User $user, TripSettlement $settlement): bool
    {
        return $settlement->company_id === $user->company_id
            && $settlement->status === TripSettlementStatus::Open
            && $user->can('payroll.manage');
    }

    public function void(User $user, TripSettlement $settlement): bool
    {
        return $settlement->company_id === $user->company_id
            && $settlement->status !== TripSettlementStatus::Void
            && $user->can('payroll.manage');
    }

    public function delete(User $user, TripSettlement $settlement): bool
    {
        return false;
    }
}
