<?php

declare(strict_types=1);

use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\Money;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Phase 8 render sweep: the reporting screens' HTML, on the dev box, in the
 * seats the design says may sit in them.
 *
 * Not the test suite — tests are local-only by owner decision. Fixtures live in the
 * RENDER-RPT namespace on the development database and are purged by that prefix; a render
 * failure prints the missing needle itself, never PHPUnit's exporter.
 *
 * Run: php scripts/render-reporting.php
 *
 * @extends TestCase
 */

require __DIR__.'/../vendor/autoload.php';

final class ReportingRenderSweep extends TestCase
{
    public int $failures = 0;

    private ?int $ownerCompany = null;

    /** Rows queued before this instant are somebody else's; rows after it are this sweep's to purge. */
    private ?CarbonImmutable $runStartedAt = null;

    /** @var list<string> */
    private array $exportIds = [];

    /** @var list<string> */
    private array $scheduledIds = [];

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function sweep(): void
    {
        /** @var User|null $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->first();

        if ($owner === null) {
            $this->note('No Owner row for '.config('fleet.identity.owner_email').' — run FLEET_OWNER_EMAIL=<seat> php artisan fleet:setup-owner (defect d).');

            return;
        }

        $this->ownerCompany = $owner->company_id;
        $this->runStartedAt = CarbonImmutable::now();
        CurrentCompany::force($owner->company_id);

        // The MFA gate is asserted where it belongs (render-identity); this sweep is about the
        // statements' authorisation and rendering, so that one middleware steps aside here.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse(Carbon::now()->toDateString())) as $created) {
            echo "  sequence row created: {$created}\n";
        }

        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-rpt-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-rpt-dispatcher@fleetguard.local');

        CurrentCompany::force($owner->company_id);

        // One expense the sweep can watch appear on both sheets, posted through the only door
        // that may write here. Matched on description as well as source type for cleanup,
        // so a real journal a person typed is never this harness's to delete.
        $accounts = Account::query()->pluck('id', 'code');
        $base = CurrentCompany::model()->base_currency_code;

        app(LedgerService::class)->post(PostingDto::inBaseCurrency(
            description: 'render-rpt: sweep expense',
            postedOn: Carbon::now()->startOfDay(),
            baseCurrencyCode: $base,
            lines: [
                new LineDto((int) $accounts['5700'], Side::Debit, Money::ofMinor(45_000, $base), note: 'render-rpt'),
                new LineDto((int) $accounts['1120'], Side::Credit, Money::ofMinor(45_000, $base)),
            ],
            sourceType: 'render_reporting',
            sourceEventId: 'render-rpt-exp',
            createdBy: $accountant->id,
        ));

        echo "\nThe statements render for the seat that reads the journal, and refuse the one that may not\n";
        $this->pageContains($accountant, '/accounting/trial-balance', 'Trial balance', 'the Accountant opens the trial balance');
        $this->pageContains($accountant, '/accounting/trial-balance', 'Balanced', '…and the book answers balanced (every entry at the door)');
        $this->pageContains($accountant, '/accounting/profit-loss', 'Profit and loss', 'the Accountant opens the P&L');
        $this->pageContains($accountant, '/accounting/profit-loss', 'Net profit', '…and the bottom line is named');

        // The Dispatcher reads ops all day and holds no `ledger.view` — a statement screen
        // is a ledger read and must refuse exactly like the journal browser does.
        $this->assertForbidden($dispatcher, '/accounting/trial-balance', 'a Dispatcher without ledger.view is refused the trial balance');
        $this->assertForbidden($dispatcher, '/accounting/profit-loss', '…and the P&L');

        echo "\nThe window is the reader's question, answered without a page reload\n";
        // The standing rule for Livewire needles: read ->html() and str_contains, so a
        // missing string names itself instead of dying inside PHPUnit's exporter.

        $this->attempt('setting an as-at before any posting empties the balance honestly', function () use ($accountant): void {
            $html = Livewire::actingAs($accountant)
                ->test('pages::accounting.trial-balance')
                ->set('asAt', '2020-01-01')
                ->html();

            if (! str_contains($html, 'Nothing posted through this date')) {
                throw new Exception("the 2020 as-at did not print the honest empty ({$this->condense($html)})");
            }
        });

        $this->attempt('…and today shows the sweep\'s own expense back in the ledger\'s columns', function () use ($accountant): void {
            $html = Livewire::actingAs($accountant)
                ->test('pages::accounting.trial-balance')
                ->set('asAt', Carbon::now()->toDateString())
                ->html();

            foreach (['5700', 'Balanced'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("today's balance did not print '{$needle}'");
                }
            }
        });

        $this->attempt('a P&L window with no movement prints both honest empties', function () use ($accountant): void {
            $html = Livewire::actingAs($accountant)
                ->test('pages::accounting.profit-loss')
                ->set('from', '2020-01-01')
                ->set('to', '2020-01-31')
                ->html();

            foreach (['No revenue posted in this window', 'No expenses posted in this window'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the 2020 window did not print '{$needle}'");
                }
            }
        });

        $this->attempt('the P&L names the sweep\'s expense in its own window', function () use ($accountant): void {
            $html = Livewire::actingAs($accountant)
                ->test('pages::accounting.profit-loss')
                ->set('from', Carbon::now()->startOfMonth()->toDateString())
                ->set('to', Carbon::now()->toDateString())
                ->html();

            if (! str_contains($html, '5700')) {
                throw new Exception("the window did not print the account '5700'");
            }
        });

        echo "\nThe tables are named, per U3\n";
        $this->pageContains($accountant, '/accounting/trial-balance', 'Trial balance as at', 'the balance table carries its caption');
        $this->pageContains($accountant, '/accounting/profit-loss', 'Expense accounts moved between', '…and so does the expense table');

        echo "\nSlice 8b — the library lists each seat its own reports\n";
        $this->pageContains($accountant, '/reports', 'The library', 'the Accountant opens the report library');
        $this->pageContains($accountant, '/reports', 'Margin per truck', '…and sees the financial reports grants it');
        $this->pageContains($accountant, '/reports?report=trial_balance', 'Debit', 'a URL-chosen report renders its table, not just its card');
        $this->pageContains($accountant, '/reports?report=trial_balance', 'Metric definitions', '…and the written definitions underneath it');
        $this->pageContains($accountant, '/reports?report=daily_ops', 'Fuel issued on', 'the daily summary names its day');

        // The row: the dispatcher is "ops only". seeTripMargin (trips.margin.view),
        // invoices.view and ledger.view are all outside the dispatcher's matrix, so those
        // cards — and a URL that asks for one — must not render.
        $this->pageContains($dispatcher, '/reports', 'Daily operations summary', 'the Dispatcher reads the ops report');
        $this->pageLacks($dispatcher, '/reports', 'Margin per truck', '…and never sees the margin card');
        $this->pageLacks($dispatcher, '/reports', 'Profit and loss', '…nor the P&L');
        $this->pageLacks($dispatcher, '/reports?report=margin_truck', 'Margin %', 'a URL past the gate renders no columns, not a refusal-table');

        echo "\nEvery report's rows fit its columns on the rendered page too\n";
        $this->pageContains($accountant, '/reports?report=fuel_usage', 'Litres', 'fuel renders its litre column');
        $this->pageContains($accountant, '/reports?report=ar_ageing', 'Oldest due', '…and receivables its oldest-due column');

        echo "\nSlice 8c — the export strip honors the ability, and the queue takes the ticket\n";
        $this->pageContains($accountant, '/reports?report=trial_balance', 'Export this window', 'the financial seat gets the export strip');
        $this->pageContains($dispatcher, '/reports?report=daily_ops', 'Export this window', '…and the ops seat gets its own');

        Queue::fake();

        $this->attempt('the Livewire button queues a real ticket, not a dead promise', function () use ($accountant): void {
            $before = DB::table('report_exports')->count();

            Livewire::actingAs($accountant)
                ->test('pages::reporting.index', ['report' => 'trial_balance'])
                ->call('export', 'csv')
                ->assertHasNoErrors();

            $row = DB::table('report_exports')->orderByDesc('created_at')->first();

            if ($row === null || DB::table('report_exports')->count() !== $before + 1) {
                throw new Exception('the button wrote no export row');
            }

            if ($row->status !== 'queued' || (int) $row->actor_id !== (int) $accountant->id) {
                throw new Exception('the row is not a queued ticket for this seat');
            }

            $this->exportIds[] = (string) $row->id;
        });

        // The download door before any file exists: 403 for another seat, and for its owner a
        // financial report diverts to password confirmation (the ⚑), never streams.
        $this->attempt('a second seat cannot stream the accountant\'s export', function () use ($dispatcher): void {
            $row = DB::table('report_exports')->orderByDesc('created_at')->first();

            if ($row === null) {
                throw new Exception('no export row to try');
            }

            $status = $this->actingAs($dispatcher, 'web')->get('/reports/exports/'.$row->id.'/download')->getStatusCode();

            if ($status !== 403) {
                throw new Exception("expected 403, got {$status}");
            }
        });

        $this->attempt('…and the owner is diverted to re-auth before a financial file streams', function () use ($accountant): void {
            $row = DB::table('report_exports')->orderByDesc('created_at')->first();

            if ($row === null) {
                throw new Exception('no export row to try');
            }

            // acceptHtml, not the test default: Fortify's password confirmation *throws* a
            // 403 AuthorizationException to a JSON-expecting client and only redirects a
            // browser (Illuminate RequirePassword::expectsJson). A person clicking a download
            // link is the browser.
            // A person clicking a download link is a browser, and Fortify's password
            // confirmation *throws* (403) to a JSON-expecting client but redirects (302) an
            // HTML one — the Accept header is what decides which half the sweep is proving.
            $response = $this->actingAs($accountant, 'web')
                ->get('/reports/exports/'.$row->id.'/download', ['Accept' => 'text/html']);
            $status = $response->getStatusCode();

            if ($status !== 302) {
                throw new Exception("expected the ⚑ redirect (302), got {$status}");
            }

            if (! str_contains((string) $response->headers->get('Location'), 'confirm-password')) {
                throw new Exception('the redirect did not go to password confirmation: '.$response->headers->get('Location'));
            }
        });

        echo "\nSlice 8d — the nomination strip, its gates, and its words\n";
        $this->pageContains($dispatcher, '/reports?report=daily_ops', 'Scheduled mail', 'the ops seat can nominate its own board');
        $this->pageLacks($accountant, '/reports?report=trial_balance', 'Scheduled mail', '…and the trial balance is not on the nominated list');

        $this->attempt('nominating weekly from the page writes the row and says so', function () use ($dispatcher): void {
            $test = Livewire::actingAs($dispatcher)
                ->test('pages::reporting.index', ['report' => 'daily_ops'])
                ->set('schedFreq', 'weekly')
                ->set('schedDow', '2')
                ->call('saveSchedule');

            $html = $test->html();

            if (! str_contains($html, 'Nomination saved')) {
                throw new Exception('no confirmation; schedNote was: '.$this->condense((string) $test->get('schedNote')).' || report='.$this->condense((string) $test->get('report')).' freq='.$this->condense((string) $test->get('schedFreq')));
            }

            $row = DB::table('scheduled_reports')->where('report', 'daily_ops')->where('enabled', 1)->first();

            if ($row === null || $row->frequency !== 'weekly') {
                throw new Exception('the nomination row did not land');
            }

            $this->scheduledIds[] = (string) $row->id;

            if (! str_contains($html, 'Mailed weekly')) {
                throw new Exception('the library card did not badge the standing nomination');
            }
        });

        $this->attempt('stopping disables the row without erasing it', function () use ($dispatcher): void {
            Livewire::actingAs($dispatcher)
                ->test('pages::reporting.index', ['report' => 'daily_ops'])
                ->call('stopSchedule');

            $row = DB::table('scheduled_reports')->where('report', 'daily_ops')->first();

            if ($row === null || (int) $row->enabled !== 0) {
                throw new Exception('the stop did not disable the nomination');
            }
        });

        echo "\nSlice 8e — the executive band reads the same doors the reports do\n";
        $this->pageContains($accountant, '/dashboard', 'Revenue this month', 'the money band opens for a ledger seat');
        $this->attempt('the revenue card prints whichever truth the ledger holds', function () use ($accountant): void {
            $response = $this->actingAs($accountant, 'web')->get('/dashboard');
            $html = (string) $response->getContent();

            // Zero revenue this month is a real answer — the card swaps its caption for the
            // zero-caption, exactly as designed (metric-card `$isZero`). Any other month renders
            // 'Posted through'. This check must not depend on what the dev data happens to hold.
            if (! str_contains($html, 'Posted through') && ! str_contains($html, 'Nothing has been earned on the books this month')) {
                throw new Exception('the revenue card printed neither its caption nor its zero-caption');
            }
        });
        $this->pageContains($accountant, '/dashboard', 'Cash and bank', '…and the cash figure');

        // Mask-not-delete: a dispatcher still sees the slot exists and which ability opens it.
        $this->pageContains($dispatcher, '/dashboard', 'Net profit this month', 'the dispatcher sees the slot exists');
        $this->pageContains($dispatcher, '/dashboard', 'ledger.view', '…named by the ability that opens it');
        $this->pageLacks($dispatcher, '/dashboard', 'read from the ledger', '…and not the figures behind the gate');
    }

    private function staffAs(User $owner, RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render RPT '.$role->label();
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

    /**
     * Reads the rendered body rather than calling PHPUnit's `assertSee` (outside a real
     * test run its failure path dies inside its own exporter; a missing string has to name itself).
     */
    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new Exception("{$path} answered {$status}, not 200");
            }

            if (! str_contains((string) $response->getContent(), $needle)) {
                throw new Exception("the page did not contain '{$needle}'");
            }
        });
    }

    private function pageLacks(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new Exception("{$path} answered {$status}, not 200");
            }

            if (str_contains((string) $response->getContent(), $needle)) {
                throw new Exception("the page should not contain '{$needle}'");
            }
        });
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
        } catch (Throwable $blewUp) {
            $this->failures++;
            echo '  FATAL '.get_class($blewUp).' — '.$this->condense($blewUp->getMessage())."\n".$blewUp->getFile().':'.$blewUp->getLine()."\n";
        } finally {
            $this->cleanUp();

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function cleanUp(): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        $entries = DB::table('gl_journal_entries')
            ->where(fn ($q) => $q->where('source_type', 'render_reporting')->orWhere('description', 'like', 'render-rpt: %'))
            ->pluck('id')
            ->all();

        if ($entries !== []) {
            LedgerBarrier::withLiftedGuard('render-reporting: remove this run\'s entries', null, function () use ($entries): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entries)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entries)->delete();
            });
        }

        // The counter the sweep spent comes back down (a harness must not break its
        // neighbours), same closure shape as verify-reporting's.
        $rows = DB::table('number_sequences')->where('company_id', $this->ownerCompany ?? 1)->where('scope', 'journal_entry')->get();

        foreach ($rows as $row) {
            $periodKey = (string) $row->period_key;

            $numbers = DB::table('gl_journal_entries')->where('company_id', $this->ownerCompany ?? 1)->whereNotNull('entry_no')
                ->when($periodKey !== '', static fn ($query) => $query->where('entry_no', 'like', '%'.$periodKey.'%'))
                ->pluck('entry_no');

            $highest = $numbers->map(static function (string $number): int {
                $dash = strrpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })->max() ?? 0;

            if ((int) $row->next_value > $highest + 1) {
                DB::table('number_sequences')->where('id', $row->id)
                    ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
            }
        }

        $checkIds = DB::table('users')->where('email', 'like', 'render-rpt-%@fleetguard.local')->pluck('id')->all();

        // The happy path remembers the row it queued, but an attempt that failed after the button
        // did its work leaves a ticket nobody owns — and in a run without Queue::fake the job row
        // outlives the ticket. Sweep the window this sweep opened, not just the ids it remembered.
        $untracked = DB::table('report_exports')
            ->when($this->runStartedAt !== null, fn ($q) => $q->where('created_at', '>=', $this->runStartedAt))
            ->whereNotIn('id', $this->exportIds ?: [''])
            ->pluck('id')
            ->all();

        $doomed = array_values(array_unique(array_merge($this->exportIds, $untracked)));

        if ($doomed !== []) {
            foreach (DB::table('report_exports')->whereIn('id', $doomed)->pluck('storage_key') as $key) {
                if (is_string($key) && $key !== '' && Storage::disk(DocumentStore::Disk)->exists($key)) {
                    Storage::disk(DocumentStore::Disk)->delete($key);
                }
            }

            foreach ($doomed as $id) {
                DB::table('jobs')->where('queue', 'exports')->where('payload', 'like', '%'.$id.'%')->delete();
                DB::table('failed_jobs')->where('payload', 'like', '%'.$id.'%')->delete();
            }

            DB::table('report_exports')->whereIn('id', $doomed)->delete();
        }

        if ($checkIds !== []) {
            DB::table('scheduled_reports')->whereIn('set_by', $checkIds)->delete();
            DB::table('number_sequences')->whereIn('last_used_by', $checkIds)->update(['last_used_by' => null]);
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $checkIds)->delete();
            DB::table('security_events')->whereIn('user_id', $checkIds)->delete();
            DB::table('users')->whereIn('id', $checkIds)->delete();
        }
    }
}

$sweep = new ReportingRenderSweep('sweep');
$sweep->drive();

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
