<?php

declare(strict_types=1);

/**
 * Phase 0 identity verification (DESIGN.md §14.6).
 *
 * Not a test suite — tests/ stays out of the repository by the owner's decision. This is the
 * runnable proof that the security rules actually hold, because §9's promises ("a dispatcher must
 * not see margins", "an invitation is single-use", "the number is encrypted") are all the kind of
 * thing that looks implemented while silently failing open.
 *
 * Run: php scripts/verify-identity.php
 *
 * It writes real rows (an invitation, a bank account, a second company) and cleans them up, so it
 * is safe against a development database. It refuses to run against anything but `local`, because
 * "clean up afterwards" is not a promise worth making about a production ledger's neighbour tables.
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Accounting\Actions\CreateBankAccount;
use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Identity\PermissionCatalogue;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Identity\RoleProvisioner;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\AcceptInvitation;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Domain\Security\Actions\InviteUser;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\MissingFieldKey;
use App\Domain\Security\DataGovernance\SecretMask;
use App\Domain\Security\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to run against a production database (§14.6).\n");

    exit(1);
}

$failures = 0;
$checks = 0;

function check(string $label, callable $assertion): void
{
    global $failures, $checks;
    $checks++;

    try {
        $result = $assertion();

        if ($result === true) {
            echo "  ok    {$label}\n";

            return;
        }

        $failures++;
        echo '  FAIL  '.$label.(is_string($result) ? " — {$result}" : '')."\n";
    } catch (Throwable $exception) {
        $failures++;
        echo '  FAIL  '.$label.' — '.get_class($exception).': '.$exception->getMessage()."\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n".str_repeat('-', mb_strlen($title))."\n";
}

/** Runs the callable as the given user, with the tenant context a request would have given them. */
function asUser(?User $user, callable $callback): mixed
{
    $previous = CurrentCompany::tryId();

    if ($user === null) {
        auth()->forgetUser();
        CurrentCompany::forget();
    } else {
        auth()->setUser($user);
        CurrentCompany::forget();
        CurrentCompany::force($user->company_id);
    }

    try {
        return $callback();
    } finally {
        auth()->forgetUser();
        CurrentCompany::force($previous);
    }
}

// The pivot is read directly rather than through `whereHas('roles')`: this script starts with no
// tenant context, and the role relation filters on the ambient company id — so the relation would
// report "no Owner" for a company that has one. Same trap as CreateOwner.
$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', RoleKey::Owner->value)
    ->select('users.id')
    ->first();

/** @var User|null $owner */
$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner row. Run: php artisan fleet:setup-owner (§9.1).\n");

    exit(1);
}

CurrentCompany::force($owner->company_id);

section('Catalogue and role set');

check('every catalogue permission exists as a row', function () {
    $missing = array_diff(PermissionCatalogue::names(), DB::table('permissions')->pluck('name')->all());

    return $missing === [] ?: 'missing: '.implode(', ', $missing);
});

check('no role row is global (company_id NOT NULL) (§8)', fn () => DB::table('roles')->whereNull('company_id')->count() === 0 ?: 'a role exists for every company at once');

check('every role holds only catalogue permissions', function () {
    $known = PermissionCatalogue::names();
    $rows = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->pluck('permissions.name')
        ->unique()
        ->all();

    $stray = array_diff($rows, $known);

    return $stray === [] ?: 'stray: '.implode(', ', $stray);
});

check('Owner holds the whole catalogue (§9.3)', fn () => asUser($owner, fn () => count(PermissionCatalogue::names()) === $owner->roles->flatMap->permissions->count()));

check('provisioning is idempotent (no cascade-deleted assignments)', function () use ($owner) {
    $before = DB::table('model_has_roles')->count();
    (new RoleProvisioner)->provision($owner->company_id);

    return $before === DB::table('model_has_roles')->count() ?: 'role assignments changed during a re-provision';
});

section('Field gates (§9.4)');

$staff = [];

foreach ([RoleKey::Dispatcher, RoleKey::Accountant, RoleKey::Auditor, RoleKey::WorkshopManager] as $key) {
    $staff[$key->value] = asUser($owner, function () use ($owner, $key): User {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => "verify-{$key->value}@fleetguard.local"]);
        $user->name = "Verify {$key->label()}";
        $user->company_id = $owner->company_id;
        $user->password = 'verification-only-not-a-real-secret';
        $user->email_verified_at = now();
        $user->save();

        (new ChangeUserRole)($user, $key);

        return $user->fresh('roles');
    });
}

check('Dispatcher is refused the trip margin', fn () => asUser($staff['dispatcher'], fn () => ! Gate::allows(FieldGate::SeeTripMargin->value)));
check('Dispatcher is refused the vehicle cost', fn () => asUser($staff['dispatcher'], fn () => ! Gate::allows(FieldGate::SeeVehicleCost->value)));
check('Dispatcher is refused the bank number', fn () => asUser($staff['dispatcher'], fn () => ! Gate::allows(FieldGate::SeeBankDetail->value)));
check('Accountant is granted the bank number', fn () => asUser($staff['accountant'], fn () => Gate::allows(FieldGate::SeeBankDetail->value)));
check('Auditor is refused the bank number (R (masked) row) (§9.3)', fn () => asUser($staff['auditor'], fn () => ! Gate::allows(FieldGate::SeeBankDetail->value)));
check('Auditor is granted the trip margin (R row)', fn () => asUser($staff['auditor'], fn () => Gate::allows(FieldGate::SeeTripMargin->value)));
check('a guest closes every gate', fn () => asUser(null, function () {
    foreach (FieldGate::cases() as $gate) {
        if (Gate::allows($gate->value)) {
            return "gate {$gate->value} opened for a guest";
        }
    }

    return true;
}));

check('every gate maps to a catalogue permission (§9.4)', function () {
    foreach (FieldGate::cases() as $gate) {
        if (! PermissionCatalogue::knows($gate->permission())) {
            return "{$gate->value} cites an unknown permission";
        }
    }

    return true;
});

section('Encrypted columns (§9.5)');

// The script is re-runnable, and `bank_accounts` has a unique (company, name) key — so a row left
// behind by an interrupted earlier run would fail the insert below rather than report a real defect.
asUser($owner, fn () => BankAccount::query()->where('name', 'Verify USD account')->get()->each->delete());

$accountNumber = '1234 5678 9012 3456';

/** @var BankAccount $account */
$account = asUser($owner, fn () => (new CreateBankAccount)(new BankAccountDto(
    name: 'Verify USD account',
    institution: 'Verification Bank',
    currencyCode: 'USD',
    type: 'bank',
    accountNumber: $accountNumber,
)));

check('the mask keeps only the tail', fn () => $account->number_masked === SecretMask::tail($accountNumber) ?: "stored '{$account->number_masked}'");
check('the plaintext is not in the stored column', fn () => ! str_contains((string) $account->getRawOriginal('number_encrypted'), '12345678') ?: 'the number is readable in the database');
check('the key version is recorded on the row', fn () => is_string($account->key_version) && $account->key_version !== '' ?: 'key_version missing');
check('the mask is the §9.5 shape and hides everything but the tail', fn () => $account->maskedNumber() === '••••3456'
    && ! str_contains($account->maskedNumber(), '1234') ?: "got '{$account->maskedNumber()}'");

// A list page for a role without the gate must still be able to render, sort and search this column
// — which is the entire reason the mask is stored rather than derived at render time (§9.5).
check('a Dispatcher renders the list without decrypting anything', function () use ($account, $staff) {
    $asDispatcher = asUser($staff['dispatcher'], function () use ($account): array {
        $row = BankAccount::query()->findOrFail($account->id);

        return [
            'masked' => $row->maskedNumber(),
            'revealed' => $row->revealedNumber(),
            'hidden' => ! str_contains($row->maskedNumber(), '1234'),
        ];
    });

    return $asDispatcher['masked'] === '••••3456'
        && $asDispatcher['revealed'] === null
        && $asDispatcher['hidden'];
});
check('reveal is refused through the gate (§9.4)', fn () => asUser($staff['dispatcher'], fn () => BankAccount::query()->findOrFail($account->id)->revealedNumber()) === null ?: 'a Dispatcher decrypted a bank number');
check('reveal succeeds for the Accountant', fn () => asUser($staff['accountant'], fn () => BankAccount::query()->findOrFail($account->id)->revealedNumber()) === $accountNumber);

check('a wrong key version refuses to read', function () use ($account) {
    try {
        app(FieldCipher::class)->decrypt((string) $account->getRawOriginal('number_encrypted'), 'v999');

        return 'a bogus key version decrypted';
    } catch (MissingFieldKey) {
        return true;
    }
});

check('a tampered ciphertext fails authentication', function () use ($account) {
    $payload = (string) $account->getRawOriginal('number_encrypted');
    $decoded = json_decode((string) base64_decode($payload), true);
    $decoded['value'] = base64_encode('tampered');

    try {
        app(FieldCipher::class)->decrypt((string) base64_encode(json_encode($decoded)), (string) $account->key_version);

        return 'modified ciphertext decrypted';
    } catch (Throwable) {
        return true;
    }
});

section('Invitations (§9.1)');

$inviteEmail = 'verify-clerk@fleetguard.local';

// Cleared inside the owner's tenant context: `invitations` carries BelongsToCompany, so an
// unscoped delete is a MissingCompanyContext error by design (§8 T3) rather than something this
// script gets to skip.
asUser($owner, function () use ($inviteEmail): void {
    Invitation::query()->where('email', $inviteEmail)->delete();
});

User::query()->where('email', $inviteEmail)->delete();

$invitation = asUser($owner, fn () => (new InviteUser)($owner, $inviteEmail, RoleKey::StoresClerk));

check('the row stores a hash, not the token', fn () => $invitation['invitation']->token_hash === hash('sha256', $invitation['token'])
    && ! str_contains(file_get_contents(storage_path('logs/laravel.log')) ?: '', $invitation['token']) ?: 'the plaintext token reached the log');

check('the token is not mass-assignable', fn () => ! in_array('token_hash', (new Invitation)->getFillable(), true));

check('a second invitation to the same address is refused', function () use ($owner, $inviteEmail) {
    try {
        asUser($owner, fn () => (new InviteUser)($owner, $inviteEmail, RoleKey::StoresClerk));

        return 'a duplicate live invitation was created';
    } catch (Throwable) {
        return true;
    }
});

check('accepting creates the account with the invited role', function () use ($invitation, $inviteEmail) {
    /** @var User $user */
    $user = (new AcceptInvitation)($invitation['token'], 'Verify Clerk', 'A-long-verification-passphrase-9');

    // Asserted as the new user: `AcceptInvitation` drops the tenant it pinned while creating the
    // row, and `hasRole()` resolves through the company context — checking without it would report
    // "no role" for a grant that actually landed.
    return asUser($user, function () use ($user, $inviteEmail, $invitation): bool|string {
        if ($user->email !== $inviteEmail) {
            return 'created with the wrong address';
        }

        if ($user->email_verified_at === null) {
            return 'created without a verified address (§9.1)';
        }

        if ($user->company_id !== $invitation['invitation']->company_id) {
            return 'created in the wrong company';
        }

        return $user->hasRole(RoleKey::StoresClerk->value) ?: 'the invited role was not granted';
    });
});

check('the same link cannot be used twice', function () use ($invitation) {
    try {
        (new AcceptInvitation)($invitation['token'], 'Second attempt', 'Another-long-verification-pass-7');

        return 'a used invitation was accepted again';
    } catch (Throwable) {
        return true;
    }
});

check('an expired invitation is refused', function () use ($invitation) {
    $invitation['invitation']->forceFill(['expires_at' => now()->subMinute()])->save();

    try {
        (new AcceptInvitation)($invitation['token'], 'Late attempt', 'Another-long-verification-pass-7');

        return 'an expired invitation was accepted';
    } catch (Throwable) {
        return true;
    }
});

check('the Owner role is never granted by invitation (§9.3)', function () use ($owner) {
    try {
        asUser($owner, fn () => (new InviteUser)($owner, 'verify-would-be-owner@fleetguard.local', RoleKey::Owner));

        return 'an invitation granted the Owner role';
    } catch (Throwable) {
        return true;
    }
});

section('Tenancy isolation (§8 T3)');

$second = Company::query()->firstOrCreate(
    ['name' => 'Verification Second Co'],
    ['base_currency_code' => 'ZAR', 'timezone' => 'Africa/Gaborone', 'country_code' => 'ZA', 'fiscal_year_starts_on' => '2026-01-01'],
);

check('the second company has its own role rows', function () use ($second) {
    (new RoleProvisioner)->provision($second->id);

    return DB::table('roles')->where('company_id', $second->id)->count() === count(RoleKey::cases());
});

check('a role from another company does not authorise this one', function () use ($owner, $second) {
    $other = User::query()->firstOrNew(['email' => 'verify-second-co-owner@fleetguard.local']);
    $other->name = 'Second Company Clerk';
    $other->company_id = $second->id;
    $other->password = 'verification-only-not-a-real-secret';
    $other->email_verified_at = now();
    $other->save();

    CurrentCompany::force($second->id);
    $other->syncRoles([DB::table('roles')->where('company_id', $second->id)->where('name', RoleKey::Accountant->value)->value('id')]);
    CurrentCompany::forget();

    // Same guard, same role name, different company: the pivot and the role row are both keyed on
    // company_id, so authorisation must not cross.
    //
    // The second half re-reads the user deliberately. Spatie's getPermissionsViaRoles() uses
    // loadMissing('roles', …), and `roles()` filters on the ambient company — so a model instance
    // keeps whichever role set was loaded first, even after the context is pinned to another
    // company. That is a real rule for this codebase, not a script quirk: **never authorise from a
    // User instance that was loaded under a different tenant.** Every HTTP request resolves the user
    // after ResolveCurrentCompany has set the context, so it cannot hit this; a CLI or queued job
    // that re-pins mid-process can (§20.5).
    $inOwnCompany = asUser($other, function () use ($second) {
        CurrentCompany::force($second->id);

        return Gate::allows('ledger.post');
    });

    $secondLook = asUser($other->fresh(), function () use ($owner) {
        // Pin company 1 while the user belongs to company 2: the resolver follows the *context*, and
        // the role row it finds there is not this user's — so the grant must not carry over.
        CurrentCompany::force($owner->company_id);

        return Gate::allows('ledger.post');
    });

    return match (true) {
        $inOwnCompany !== true => 'the role did not authorise inside its own company ('.var_export($inOwnCompany, true).')',
        $secondLook !== false => 'a role from company '.$second->id.' authorised company '.$owner->company_id,
        default => true,
    };
});

check('a stale relation is the only way a cross-company grant appears (§20.5)', function () use ($owner, $second) {
    /** @var User|null $other */
    $other = User::query()->where('email', 'verify-second-co-owner@fleetguard.local')->first();

    if ($other === null) {
        return 'the second-company user is gone';
    }

    // Loaded under company 2, then asked under company 1 without a re-read.
    asUser($other, function () use ($second) {
        CurrentCompany::force($second->id);

        return Gate::allows('ledger.post');
    });

    $carried = asUser($other, function () use ($owner) {
        CurrentCompany::force($owner->company_id);

        return Gate::allows('ledger.post');
    });

    return $carried === true ?: 'the memoised relation did not carry over, so the rule above is stronger than documented';
});

check('with no tenant context, every role-derived permission is refused', fn () => asUser(null, function () {
    CurrentCompany::forget();

    return ! Gate::allows('ledger.post') && ! Gate::allows('bank.view');
}));

section('Mandatory MFA enforcement (§9.1)');

// Every one of the seven seeded seats holds at least one financial permission (invoices.view,
// accounting.view, dashboard.financial, fuel.variance.view_cost …), so §9.1's rule makes TOTP
// mandatory for the whole staff set, not just the Owner. Recorded because that is a consequence of
// the catalogue rather than a decision anyone took in one place — and it is why the negative control
// below has to be a role-less probe: no seeded role escapes the requirement.
foreach (RoleKey::cases() as $role) {
    check("role '{$role->label()}' requires MFA (§9.1)", fn () => $role->requiresMfa() === true
        ?: 'a seeded seat can see or move money without a factor');
}

// Pre-clean before measuring anything: a run that died further down leaves these behind, and the
// unique email index would then abort forceCreate with a schema error blamed on the wrong section
// (§20.8's residue lesson).
User::query()->where('email', 'like', 'verify-mfa-%@fleetguard.local')->get()->each(function (User $stale): void {
    DB::table('model_has_roles')->where('model_id', $stale->id)->where('model_type', User::class)->delete();
    $stale->delete();
});

$makeProbe = fn (string $email, ?RoleKey $role, bool $confirmed): User => asUser($owner, function () use ($email, $owner, $role, $confirmed): User {
    $probe = User::forceCreate([
        'name' => 'Verify MFA',
        'email' => $email,
        'password' => 'verify-secret',
        'company_id' => $owner->company_id,
        'email_verified_at' => Carbon::now(),
        'two_factor_secret' => $confirmed ? encrypt('JBSWY3DPEHPK3PXP') : null,
        'two_factor_confirmed_at' => $confirmed ? Carbon::now() : null,
    ]);

    if ($role !== null) {
        $probe->assignRole($role->value);
    }

    return $probe;
});

// Exercises the middleware the way the web group does: tenant pinned (so hasAnyPermission is scoped
// to the right team), a named route on the request (so the allow-list is measured, not assumed), and
// a pass-through sentinel as $next. The request is built by hand on purpose — §20.8 recorded that a
// full in-process kernel->handle() drops the guard and dies on MissingCompanyContext, so a fake
// browser proves nothing here; calling handle() directly does.
$runMfa = fn (User $user, string $routeName, string $uri = '/dashboard'): mixed => asUser($user, function () use ($user, $routeName, $uri): mixed {
    $request = Request::create($uri);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => (new Route(['GET'], $uri, fn () => null))->name($routeName));

    return (new EnsureMultiFactorAuthentication)->handle($request, fn () => new Response('PASSED_THROUGH'));
});

$unenrolled = $makeProbe('verify-mfa-unenrolled@fleetguard.local', RoleKey::Accountant, false);
$enrolled = $makeProbe('verify-mfa-enrolled@fleetguard.local', RoleKey::Accountant, true);
$roleless = $makeProbe('verify-mfa-roleless@fleetguard.local', null, false);

check('a financial seat with no confirmed factor is redirected to enrolment', function () use ($runMfa, $unenrolled) {
    $response = $runMfa($unenrolled, 'dashboard');

    return $response instanceof RedirectResponse && $response->getTargetUrl() === route('security.edit')
        ?: 'expected a redirect to '.route('security.edit').', got '.var_export($response->getContent(), true);
});

check('the same seat, once the factor is confirmed, passes through', fn () => $runMfa($enrolled, 'dashboard')->getContent() === 'PASSED_THROUGH'
    ?: 'an enrolled financial user was still blocked');

check('a seat holding no financial permission passes through unenrolled', fn () => $runMfa($roleless, 'dashboard')->getContent() === 'PASSED_THROUGH'
    ?: 'a role-less user was blocked — the predicate over-enforces');

check('a guest passes through (auth owns that decision, not this gate)', fn () => asUser(null, function (): mixed {
    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => null);
    $request->setRouteResolver(fn () => (new Route(['GET'], '/dashboard', fn () => null))->name('dashboard'));

    return (new EnsureMultiFactorAuthentication)->handle($request, fn () => new Response('PASSED_THROUGH'))->getContent();
}) === 'PASSED_THROUGH' ?: 'a guest was redirected by the MFA gate');

check('the enrolment screen is reachable while unenrolled (no redirect loop)', fn () => $runMfa($unenrolled, 'security.edit', '/settings/security')->getContent() === 'PASSED_THROUGH'
    ?: 'security.edit redirected — the user could never enrol');

check('logout stays reachable while unenrolled (the cage has an exit)', fn () => $runMfa($unenrolled, 'logout', '/logout')->getContent() === 'PASSED_THROUGH'
    ?: 'logout was blocked — an unenrolled user cannot sign out');

check('the Fortify factor routes stay reachable while unenrolled', fn () => $runMfa($unenrolled, 'two-factor.enable', '/user/two-factor-authentication')->getContent() === 'PASSED_THROUGH'
    ?: 'a two-factor.* route was blocked — enrolment cannot post');

echo "\n";

// Ordered cleanup: the pivot rows go with the users (their FK cascades), the second company's role
// rows must go before the company itself (FK), and the verification bank account is master data with
// no postings — so a hard delete is allowed here (§9.5) and would not be in the application.
asUser($owner, function () use ($second): void {
    $verifyIds = User::query()->where('email', 'like', 'verify-%@fleetguard.local')->pluck('id');

    DB::table('model_has_roles')->whereIn('model_id', $verifyIds)->where('model_type', User::class)->delete();
    Invitation::query()->withoutCompanyScope()->where('email', 'like', 'verify-%@fleetguard.local')->delete();
    BankAccount::query()->where('name', 'Verify USD account')->get()->each->delete();
    User::query()->where('email', 'like', 'verify-%@fleetguard.local')->delete();

    $roleIds = DB::table('roles')->where('company_id', $second->id)->pluck('id');
    DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
    DB::table('roles')->where('company_id', $second->id)->delete();

    Company::query()->find($second->id)?->delete();
});

echo "\n";
printf("%d checks, %d failed — %s\n", $checks, $failures, $failures === 0 ? 'IDENTITY OK' : 'IDENTITY FAILED');

exit($failures === 0 ? 0 : 1);
