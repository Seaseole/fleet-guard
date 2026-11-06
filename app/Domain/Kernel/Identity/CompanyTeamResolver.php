<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * The single place that answers "which company's roles are in force right now" — by asking the
 * tenancy context and nothing else.
 *
 * `Spatie\Permission\DefaultTeamResolver` keeps its own mutable team id, which would make the
 * tenant a second piece of ambient state beside `CurrentCompany`. Two knobs that have to be set
 * together, and that are set by different code paths, is exactly how the queue-worker leak in
 * Happened; this class removes the second knob instead of synchronising it.
 *
 * Failing closed is the point: with no context — a guest request, a job that never pinned its
 * company, a CLI command run without `--company` — the answer is null, the pivot filter matches
 * no rows, and every role-derived permission is refused. A silent no-roles is a screen that
 * looks empty; it is never a screen that shows more than it should.
 */
final class CompanyTeamResolver implements PermissionsTeamResolver
{
    /**
     * Ignored on purpose. `setPermissionsTeamId()` would let a caller move the RBAC context
     * without moving the data context, which is the split this class exists to prevent; the only
     * way to change the company is `CurrentCompany::force()`.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        //
    }

    /**
     * Narrowed against the contract: a company id is an integer here, and saying so is what lets
     * Larastan check the call sites that compare it with `users.company_id`.
     */
    public function getPermissionsTeamId(): ?int
    {
        return CurrentCompany::tryId();
    }
}
