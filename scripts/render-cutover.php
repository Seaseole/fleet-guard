<?php

declare(strict_types=1);

/**
 * Phase 0.5's cutover screen, rendered and driven through the real HTTP/Livewire stack.
 *
 * `view:cache` only compiles Blade, so a render-time error walks past the quality gate (§20.2). This
 * makes real requests as the three roles §9.3 distinguishes — the Owner who commits, the
 * accountant who validates, and a Dispatcher who may not open the screen at all — and then drives
 * the component's own actions, because "the button is hidden" is not the same claim as "the action
 * refuses".
 *
 * Not a test suite: tests/ stays out of the repository (§14.6). It writes to the development
 * database and removes everything it created, journals included, through the ledger's own hatch.
 *
 * Run: php scripts/render-cutover.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Kernel\Cutover\Actions\ImportCutoverBatch;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverErrorReport;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class CutoverRenderSweep extends TestCase
{
    public int $failures = 0;

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<string> */
    private array $publicIds = [];

    private ?int $ownerCompany = null;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private const BALANCED = <<<'CSV'
account_code,account_name,debit,credit,description
1120,BWP bank,"12 500.00",,Opening bank
3010,Owner capital,,"12 500.00",Opening equity
CSV;

    private const MESSY = <<<'CSV'
account_code,debit,credit
9999,10.00,
3010,,5.00
CSV;

    public function sweep(): void
    {
        /** @var User|null $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->first();

        if ($owner === null) {
            $this->note('No Owner row for '.config('fleet.identity.owner_email').' — run php artisan fleet:setup-owner (§9.1).');

            return;
        }

        $this->ownerCompany = $owner->company_id;
        CurrentCompany::force($owner->company_id);

        // §9.1 requires MFA enrolment from anyone holding a financial permission, so a freshly
        // minted accountant is redirected to the security page before any screen loads. This sweep
        // is about the cutover screen's authorisation, not about MFA — the redirect is asserted
        // where it belongs, in render-identity — so that one middleware steps aside here.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse('2031-05-01')) as $created) {
            echo "  sequence row created: {$created}\n";
        }

        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-check-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-check-dispatcher@fleetguard.local');

        echo "\nThe screen renders for the roles that own it\n";
        $this->pageContains($owner, '/accounting/cutover', 'Validate a trial balance', 'the Owner sees the upload card');
        $this->pageContains($accountant, '/accounting/cutover', 'Cutover history', 'the accountant sees the same register');
        $this->assertForbidden($dispatcher, '/accounting/cutover', 'a Dispatcher without cutover.import is refused');

        echo "\nValidation is a preview, kept as a batch\n";
        $validated = $this->validatedBatch($accountant);
        $validId = $validated->public_id;

        $this->attempt('the accountant\'s paste becomes a validated batch', function () use ($validated): void {
            if ($validated->status !== CutoverBatchStatus::Validated) {
                throw new Exception('expected validated, got '.$validated->status->value);
            }
        });

        $this->pageContains($owner, '/accounting/cutover?batch='.$validId, 'Commit the opening balances', 'the Owner is offered the commit');
        $this->pageLacks($accountant, '/accounting/cutover?batch='.$validId, 'Commit the opening balances', 'the accountant is not offered the commit');
        $this->pageContains($accountant, '/accounting/cutover?batch='.$validId, 'waiting for the Owner', 'the accountant is told who commits');

        echo "\nThe error report is a file, with the row it names\n";
        $messy = app(ImportCutoverBatch::class)->handle(
            CutoverDataset::OpeningTrialBalance,
            self::MESSY,
            'messy.csv',
            $accountant->id,
        );
        $this->batchIds[] = $messy->id;
        $this->publicIds[] = $messy->public_id;

        $this->attempt('the CSV endpoint serves the report', function () use ($accountant, $messy): void {
            $this->actingAs($accountant, 'web')
                ->get("/accounting/cutover/{$messy->id}/errors.csv")
                ->assertOk();

            $body = CutoverErrorReport::build($messy);

            if (! str_starts_with($body, 'line,account_code,account_name,debit,credit,description,errors')) {
                throw new Exception('unexpected header: '.substr($body, 0, 80));
            }

            if (! str_contains($body, 'not in the chart of accounts') || ! str_contains($body, 'does not balance')) {
                throw new Exception('the report does not name the row and batch errors');
            }
        });

        echo "\nCommitting needs a recent password and the Owner's permission\n";

        $this->attempt('the accountant holds no post ability at the policy', function () use ($accountant, $validated): void {
            if (Gate::forUser($accountant)->allows('post', $validated)) {
                throw new Exception('the accountant may post');
            }

            if (Gate::forUser($owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail())->denies('post', $validated)) {
                throw new Exception('the Owner may not post');
            }
        });

        $this->attempt('the Owner is sent to the password prompt first', function () use ($owner, $validId): void {
            Livewire::actingAs($owner)
                ->test('pages::accounting.cutover')
                ->set('batchId', $validId)
                ->call('commit')
                ->assertRedirect(route('password.confirm'));
        });

        $this->attempt('with a recent confirmation the batch posts', function () use ($owner, $validId): void {
            $this->withSession(['auth.password_confirmed_at' => time()]);

            Livewire::actingAs($owner)
                ->test('pages::accounting.cutover')
                ->set('batchId', $validId)
                ->set('postedOn', '2031-05-01')
                ->call('commit')
                ->assertHasNoErrors();

            $batch = CutoverBatch::query()->where('public_id', $validId)->firstOrFail();

            if ($batch->status !== CutoverBatchStatus::Posted) {
                throw new Exception('expected posted, got '.$batch->status->value);
            }

            if ($batch->journal_entry_id === null) {
                throw new Exception('the batch does not name its journal entry');
            }
        });

        // The reversal writes a second entry after this point, so cleanup re-reads by source id
        // rather than trusting a list taken here.
        $this->pageContains($owner, '/accounting/cutover?batch='.$validId, 'Reverse the opening balances', 'a posted batch offers the reversal');

        echo "\nReversing needs a reason\n";

        $this->attempt('a blank reason is a field error, not a ledger write', function () use ($owner, $validId): void {
            $this->withSession(['auth.password_confirmed_at' => time()]);

            Livewire::actingAs($owner)
                ->test('pages::accounting.cutover')
                ->set('batchId', $validId)
                ->set('reversalReason', '')
                ->call('reverse')
                ->assertHasErrors('reversalReason');
        });

        $this->attempt('a stated reason reverses the batch', function () use ($owner, $validId): void {
            $this->withSession(['auth.password_confirmed_at' => time()]);

            Livewire::actingAs($owner)
                ->test('pages::accounting.cutover')
                ->set('batchId', $validId)
                ->set('reversalReason', 'screen check')
                ->call('reverse')
                ->assertHasNoErrors();

            $batch = CutoverBatch::query()->where('public_id', $validId)->firstOrFail();

            if ($batch->status !== CutoverBatchStatus::Reversed || $batch->reversal_entry_id === null) {
                throw new Exception('expected reversed with a mirror, got '.$batch->status->value);
            }
        });
    }

    private function validatedBatch(User $accountant): CutoverBatch
    {
        /** @var CutoverBatch $batch */
        $batch = app(ImportCutoverBatch::class)->handle(
            CutoverDataset::OpeningTrialBalance,
            self::BALANCED,
            'screen-check.csv',
            $accountant->id,
        );

        $this->batchIds[] = $batch->id;
        $this->publicIds[] = $batch->public_id;

        return $batch;
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
        app(ChangeUserRole::class)($user, $role);
        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
    }

    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->actingAs($user, 'web')->get($path)->assertOk()->assertSee($needle, false));
    }

    private function pageLacks(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->actingAs($user, 'web')->get($path)->assertOk()->assertDontSee($needle, false));
    }

    private function assertForbidden(User $user, string $path, string $label): void
    {
        $this->attempt($label, function () use ($user, $path): void {
            $status = $this->actingAs($user, 'web')->get($path)->getStatusCode();

            if ($status !== 403) {
                throw new Exception("unexpected status {$status}");
            }
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

    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } finally {
            $this->cleanUp();

            // `TestCase::tearDown()` hands the global handlers back to PHPUnit, which asserts a
            // phpunit.xml configuration this standalone script does not have. Dropping the
            // bootstrapper's app reference first makes that flush a no-op (it returns early).
            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function cleanUp(): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        $checkIds = DB::table('users')->where('email', 'like', 'render-check-%@fleetguard.local')->pluck('id');

        // Batches first: they hold the FK onto the journal entries, so deleting the entry would
        // orphan the row that names it (§6.3 lets a batch be removed; it never lets its journal be).
        // The sweep's own ids are not enough — a run that died before its `finally` left rows behind,
        // and the next run reuses the same staff accounts — so anything those accounts created goes.
        $publicIds = array_values(array_unique(array_merge(
            $this->publicIds,
            DB::table('cutover_batches')
                ->whereIn('created_by', $checkIds)
                ->pluck('public_id')
                ->all(),
        )));

        DB::table('cutover_batches')
            ->where(fn ($query) => $query
                ->whereIn('id', $this->batchIds === [] ? [-1] : $this->batchIds)
                ->orWhereIn('created_by', $checkIds === [] ? [-1] : $checkIds))
            ->delete();

        $entries = $publicIds === [] ? [] : DB::table('gl_journal_entries')
            ->whereIn('source_id', $publicIds)
            ->pluck('id')
            ->all();

        // Opening-balance entries exist only because something in this repository's scripts wrote
        // them, and an earlier run that died mid-cleanup leaves orphans whose batch row is gone.
        // Sweeping the source types keeps the dev ledger free of this rehearsal's debris.
        $entries = array_values(array_unique(array_merge($entries, DB::table('gl_journal_entries')
            ->whereIn('source_type', ['opening_balance', 'opening_balance_reversal'])
            ->pluck('id')
            ->all())));

        if ($entries !== []) {
            LedgerBarrier::withLiftedGuard('render-cutover: remove this run\'s entries', null, function () use ($entries): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entries)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entries)->whereNotNull('reverses_entry_id')->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entries)->delete();
            });
        }

        DB::table('operational_events')->where('aggregate_type', 'cutover_batch')->delete();

        $periodIds = DB::table('gl_periods')->whereIn('period', ['2031-05'])->pluck('id')->all();

        if ($periodIds !== [] && ! DB::table('gl_journal_entries')->whereIn('period_id', $periodIds)->exists()) {
            DB::table('gl_periods')->whereIn('id', $periodIds)->delete();
        }

        DB::table('number_sequences')
            ->where('company_id', $this->ownerCompany ?? 1)
            ->where('period_key', 'FY31/32')
            ->whereIn('scope', ['journal_entry', 'vat_return'])
            ->delete();

        $checkIds = DB::table('users')->where('email', 'like', 'render-check-%@fleetguard.local')->pluck('id');

        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $checkIds)->delete();
        DB::table('users')->whereIn('id', $checkIds)->delete();

        CurrentCompany::forget();

        echo LedgerBarrier::isLifted()
            ? "  FAIL  the barrier row was left lifted\n"
            : "  ok    the barrier row is lowered — ledger immutability is back on\n";
    }
}

$sweep = new CutoverRenderSweep('sweep');
$sweep->drive();

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
