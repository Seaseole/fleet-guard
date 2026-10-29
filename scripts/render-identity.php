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

        echo "\nOwner (§9.3: sees everything, holds the ⚑ gates)\n";
        $this->screen($owner, '/security/users', 'Staff accounts');
        $this->screen($owner, '/security/users', 'Send invitation');
        $this->screen($owner, '/accounting/bank-accounts', 'Bank and cash accounts');
        $this->pageContains($owner, '/accounting/bank-accounts', 'Bank &amp; cash', 'the nav item renders');

        echo "\nDispatcher (§9.3: no users.view, no bank.view, staff nav filtered out)\n";
        $this->assertForbidden($dispatcher, '/security/users');
        $this->assertForbidden($dispatcher, '/accounting/bank-accounts');
        $this->pageLacks($dispatcher, '/dashboard', 'Users &amp; roles', 'the staff nav entry is filtered (§3.2)');

        echo "\nGuest (registration is off, so an unknown link is the only door)\n";
        $this->guestPageContains('/invitations/not-a-real-token-aaaaaaaaaaaaaaaaaaaaaaaa', 'cannot be used', 'unknown invitation refused without leaking');
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

    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->actingAs($user, 'web')->get($path)->assertOk()->assertSee($needle, false));
    }

    private function pageLacks(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->actingAs($user, 'web')->get($path)->assertOk()->assertDontSee($needle, false));
    }

    private function guestPageContains(string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->get($path)->assertOk()->assertSee($needle, false));
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
