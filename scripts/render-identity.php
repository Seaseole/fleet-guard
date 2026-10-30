<?php

declare(strict_types=1);

/**
 * Renders the Phase 0 identity screens through the real HTTP stack and reports what a browser would
 * get — including the §9.4 gate closing for a role that lacks it, and the §3.2 nav filtering.
 *
 * This exists because `view:cache` only *compiles* Blade; it does not parse the PHP it generates, so
 * a render-time ParseError walks straight past the quality gate (§20.2). For a component system the
 * only meaningful check is an actual request, and for §9.4 the only meaningful check is that same
 * request made as a role that is supposed to be refused.
 *
 * Not a test suite: tests/ stays out of the repository (§14.6). This is a smoke check, like
 * scripts/verify-kernel.php, and it runs against the development database.
 *
 * Run: php scripts/render-identity.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @extends TestCase
 */
final class RenderSweep extends TestCase
{
    public int $failures = 0;

    /** @var list<string> */
    public array $messages = [];

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function sweep(): void
    {
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->first();

        if ($owner === null) {
            $this->note('No Owner row for '.config('fleet.identity.owner_email').' — run php artisan fleet:setup-owner (§9.1).');

            return;
        }

        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-check-dispatcher@fleetguard.local');
        $enrolled = $this->enrolFactor($this->staffAs($owner, RoleKey::Dispatcher, 'render-check-enrolled@fleetguard.local'));

        echo "\nOwner (§9.3: sees everything, holds the ⚑ gates)\n";
        $this->screen($owner, '/security/users', 'Staff accounts');
        $this->screen($owner, '/security/users', 'Send invitation');
        $this->screen($owner, '/accounting/bank-accounts', 'Bank and cash accounts');
        $this->pageContains($owner, '/accounting/bank-accounts', 'Bank &amp; cash', 'the nav item renders');

        echo "\nDispatcher (§9.3: no users.view, no bank.view, staff nav filtered out)\n";

        // §9.1 and §9.3 meet here. A Dispatcher whose factor has not been enrolled is *refused* a screen
        // they may never open, and *diverted to enrolment* on one they may: if the divert won everywhere,
        // the seat would be told to enrol a factor for a page that still says no afterwards.
        $this->assertForbidden($dispatcher, '/security/users');
        $this->assertForbidden($dispatcher, '/accounting/bank-accounts');
        $this->assertEnrolmentRedirect($dispatcher, '/dashboard');

        // Read as an enrolled seat, because §3.2's filtering is a claim about the rendered nav, and an
        // un-enrolled one never reaches the nav.
        $this->pageLacks($enrolled, '/dashboard', 'Users &amp; roles', 'the staff nav entry is filtered (§3.2)');

        echo "\nGuest (registration is off, so an unknown link is the only door)\n";

        // Shaped like a real token — the route's own `where()` rejects anything else with a 404, which
        // would prove nothing about §9.1's no-enumeration promise.
        $this->guestPageContains('/invitations/'.str_repeat('a', 40), 'cannot be used', 'unknown invitation refused without leaking');
    }

    /**
     * Enrols a probe seat's factor the way {@see scripts/verify-identity.php} does, so a check that reads
     * rendered markup is not reading a redirect.
     *
     * A second account rather than a flag on `$dispatcher`: this sweep needs both the un-enrolled and the
     * enrolled behaviour of the same role, and the redirect would otherwise hide the nav.
     */
    private function enrolFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => Carbon::now(),
        ])->save();

        return $user->fresh();
    }

    /** §9.1: an un-enrolled seat on a screen it *is* allowed sees the enrolment door, not the page. */
    private function assertEnrolmentRedirect(User $user, string $path): void
    {
        $this->attempt("{$path} sends an un-enrolled seat to enrolment, not to the page", function () use ($user, $path): void {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();
            $location = (string) $response->headers->get('Location');

            if ($status !== 302) {
                throw new Exception("expected the enrolment redirect, got {$status}");
            }

            if (! str_contains($location, '/settings/security')) {
                throw new Exception("the redirect went to '{$location}', not the enrolment screen");
            }
        });
    }

    private function staffAs(User $owner, RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render Check '.$role->label();
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);
        (new ChangeUserRole)($user, $role);
        CurrentCompany::forget();

        return $user->fresh();
    }

    private function screen(User $user, string $path, string $needle): void
    {
        $this->pageContains($user, $path, $needle, $path);
    }

    /**
     * Reads the response body rather than calling PHPUnit's `assertSee`.
     *
     * Outside a real test run PHPUnit's failure path dies inside its own exporter —
     * `assert(self::$instance instanceof Configuration)` — so every failed check here reported that
     * internal message instead of the string it could not find (§20.19 records the same masking in
     * `render-cutover`). A refusal has to name the needle.
     */
    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $this->assertOkBody($this->actingAs($user, 'web')->get($path), $path, 'contains', $needle);
        });
    }

    private function pageLacks(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $this->assertOkBody($this->actingAs($user, 'web')->get($path), $path, 'lacks', $needle);
        });
    }

    private function guestPageContains(string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($path, $needle): void {
            $this->assertOkBody($this->get($path), $path, 'contains', $needle);
        });
    }

    private function assertOkBody(mixed $response, string $path, string $want, string $needle): void
    {
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new Exception("{$path} answered {$status}, not 200");
        }

        $found = str_contains((string) $response->getContent(), $needle);

        if ($want === 'contains' && ! $found) {
            throw new Exception("the page did not contain '{$needle}'");
        }

        if ($want === 'lacks' && $found) {
            throw new Exception("the page should not contain '{$needle}'");
        }
    }

    /**
     * §9.3's matrix is only real if the absence is enforced server-side. A screen that renders the
     * table and hides the columns is a leak waiting for someone to forget a @can.
     */
    private function assertForbidden(User $user, string $path): void
    {
        $this->attempt("{$path} is refused for a Dispatcher", function () use ($user, $path) {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();

            if ($status === 403) {
                return;
            }

            // Livewire page components inside an `auth` group redirect on failure; a 200 here would
            // mean the page rendered, which is the only outcome that would be a bug.
            if ($status === 302) {
                throw new Exception('redirected instead of refusing (status 302)');
            }

            throw new Exception("unexpected status {$status}");
        });
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";
        }
    }

    /** TestResponse failures carry the whole HTML body; the first line is the useful one. */
    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 220);
    }

    private function note(string $message): void
    {
        $this->failures++;
        echo '  FAIL  '.$message."\n";
    }

    /**
     * PHPUnit's setUp()/tearDown() are protected, so the script's whole entry point has to be a
     * method on this class: the app is booted, the screens are rendered, and the rows this script
     * created are removed in FK order (pivot, then user, then the bank rows).
     */
    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } finally {
            $this->cleanUp();

            // The handler has to be dropped before the teardown, the way every other sweep does it:
            // `flushState()` reaches for PHPUnit's error-handler singleton, and outside a real PHPUnit
            // run that asks its configuration registry for a configuration that was never loaded — an
            // AssertionError that escapes as a PHP fatal *after* the report, so the script died with 255
            // whatever the screens did. Without this line the gate cannot report anything at all.
            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function cleanUp(): void
    {
        DB::table('model_has_roles')->where('model_type', User::class)
            ->whereIn('model_id', DB::table('users')->where('email', 'like', 'render-check-%@fleetguard.local')->pluck('id'))
            ->delete();

        User::query()->where('email', 'like', 'render-check-%@fleetguard.local')->delete();

        $ownerCompany = User::query()->where('email', config('fleet.identity.owner_email'))->value('company_id');
        CurrentCompany::force($ownerCompany === null ? 1 : (int) $ownerCompany);
        BankAccount::query()->where('name', 'like', 'Render check%')->get()->each->delete();
        CurrentCompany::forget();
    }
}

$sweep = new RenderSweep('sweep');
$sweep->drive();

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
