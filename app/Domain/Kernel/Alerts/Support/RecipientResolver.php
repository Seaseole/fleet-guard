<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Support;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Models\NotificationPreference;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Who is told, from the role keys a threshold row names.
 *
 * The row names roles and this class turns them into people at the moment of delivery, which is what
 * makes the promise real: a reminder addressed to `operations_manager` reaches whoever holds that
 * role tonight, with no migration and no one editing a JSON column to move a departed employee's inbox
 * out of the path.
 *
 * **An unknown role key throws rather than contributing nobody.** A threshold row that names a role the
 * catalogue no longer has would otherwise resolve to an empty recipient list, the alert would be created
 * and sit open on the register, and the one behaviour the recipients sentence exists to guarantee —
 * that somebody hears about it — would have quietly stopped. That is the same class of defect as slice
 * 7's `generatedAs()` on MySQL: the guard reads correct and does nothing.
 *
 * Spatie's `role()` scope is used rather than a hand-rolled join on `model_has_roles`, because team mode
 * is on: the scope applies `CompanyTeamResolver`'s answer, and the only way to change that answer is
 * `CurrentCompany::force()` — which is the T4 pin the runner sets before it gets here. Re-implementing
 * it here would put a second, subtly different copy of the tenant rule in the codebase.
 */
final class RecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(Threshold $threshold): Collection
    {
        return $this->resolveRoleKeys($threshold->recipients, $threshold->family);
    }

    /**
     * The same answer from bare role keys, for a delivery that no threshold row authorised.
     *
     * Only the self-check alerts take this path: they are not day-based, so there is no
     * `reminder_thresholds` row to hang their recipients on, and inventing a zero-day row to satisfy the
     * schema would be a row that means something the Owner cannot edit or read. The role keys come from
     * `fleet.reminders.self_check_recipients` instead, and the same unknown-role rule applies.
     *
     * @param  list<string>  $roleKeys
     * @return Collection<int, User>
     */
    public function resolveRoleKeys(array $roleKeys, string $for): Collection
    {
        // Ask for the context by name before the first role lookup. With `permission.teams` on, spatie
        // resolves a role *within* the current team, so an unpinned call does not return an empty list —
        // it throws `RoleDoesNotExist`, because the row exists but not under a null team. Both answers
        // are fail-closed, and this line decides which message the operator reads: The sentence about
        // missing context, naming the command flag and the queued-job rule, is worth more than a
        // package's exception about a role that demonstrably does exist.
        CurrentCompany::id();

        /** @var Collection<int, User> $people */
        $people = collect();

        foreach ($roleKeys as $roleKey) {
            if (RoleKey::tryFrom($roleKey) === null) {
                throw new InvalidArgumentException(sprintf(
                    'The %s recipient list names "%s", which is not a role in the catalogue. '
                    .'Fix the row or name a role that exists; resolving to nobody is not a failure this '
                    .'reminder path is allowed to have quietly.',
                    $for,
                    $roleKey,
                ));
            }

            // A catalogue role that this company does not have a row for yet is a different thing from a
            // name that is not a role at all: it is a company that has not been provisioned, or one whose
            // staff have not been given that role. It contributes no recipients and the caller reports that
            // out loud — it does not abort the sweep. Measured, not theorised: with one unprovisioned
            // tenant in the database, the nightly run threw here and never reached the companies after it,
            // so a single unlucky tenant could silence everyone's reminders.
            if (! $this->roleHasHolders($roleKey)) {
                continue;
            }

            $people = $people->concat(User::query()->role($roleKey)->get());
        }

        // A user who holds two of the named roles is one recipient, not two emails.
        return $people->unique(static fn (User $user): int => (int) $user->id);
    }

    /**
     * Whether anybody in the pinned company holds this role.
     *
     * Read directly rather than through spatie's finder, because its finder throws on a name it cannot
     * resolve for the current team — and "does anyone hold this" is a question, not an exception, when the
     * honest answer for a new company is nobody yet.
     */
    private function roleHasHolders(string $roleKey): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $roleKey)
            ->where('model_has_roles.company_id', CurrentCompany::id())
            ->exists();
    }

    /**
     * Whether this person still wants this type on this channel (the opt-out, and its one exception).
     *
     * The exception is asked first and answered without a query: a critical alert is delivered whatever
     * the row says, and "we did not check because it does not matter" is the cheapest way to keep the
     * promise the design makes about safety papers.
     *
     * Absence of a row means deliver — the preference table is opt-out, never opt-in, for the reason on
     * {@see NotificationPreference}.
     */
    public function wants(User $user, AlertType $type, string $channel, AlertSeverity $severity): bool
    {
        // Asked before the critical override, and the order is the point: an alert that cannot be opted out
        // still cannot be mailed to an address that is not there. "Delivered" is a claim about a recipient,
        // and a job that fails because the person has no mailbox teaches nobody anything.
        if ($channel === 'mail' && (string) $user->email === '') {
            return false;
        }

        if ($severity->cannotBeOptedOut()) {
            return true;
        }

        return ! NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('channel', $channel)
            ->where('enabled', false)
            ->exists();
    }
}
