<?php

declare(strict_types=1);

use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\Actions\TransitionAlert;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Alerts\Exceptions\AlertTransitionRefused;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Alerts\Models\NotificationPreference;
use App\Domain\Kernel\Alerts\Models\ReminderThreshold;
use App\Domain\Kernel\Alerts\Notifications\AlertRaised;
use App\Domain\Kernel\Alerts\Sources\DocumentExpirySource;
use App\Domain\Kernel\Alerts\Support\RecipientResolver;
use App\Domain\Kernel\Alerts\Support\ReminderRunner;
use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Alerts\Support\ThresholdRepository;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Audit\Models\OperationalEvent;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Identity\CompanyTeamResolver;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Kernel\Tenancy\MissingCompanyContext;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Phase 0 alerts and reminders verification (DESIGN.md §20.8).
 *
 * Not the test suite — `tests/` is local-only by owner decision (§14.6). This is the runnable proof of
 * §14.1 and §10: that one table is the source of truth and the notification is a copy of it, that the
 * cooldown is a lookup on a condition's identity rather than a counter that expires, that a mute cannot
 * become permanent, that thresholds are rows and recipients are roles, and that a scheduled self-check
 * failure reaches a human instead of a log file.
 *
 * It writes to the development database — including a second company and four temporary staff accounts,
 * two of them holding the same role, which are the only ways to measure tenant isolation, role-derived
 * recipients and the per-person half of the bell — and removes everything it created, then asserts that
 * it did.
 *
 * Two measurements matter more than the rest, because both were hazards named before the code was
 * written:
 *
 *  1. **Recipients resolve only inside a company context.** `permission.teams` is on and
 *     {@see CompanyTeamResolver} answers from `CurrentCompany`, so a sweep
 *     that forgot to pin a tenant would resolve every recipient list to empty, create every alert, and
 *     tell nobody. That is measured on purpose — including the empty answer, which is the design working.
 *  2. **A clean single-tenant run must not resolve another tenant's alert.** `--company` narrowed the
 *     check, so the evidence says nothing about anyone else. The bug was found while writing this file.
 *
 * The mail transport is swapped for `array` before the queue is drained: this machine's SMTP is a
 * placeholder, and the finding under test is which channels a recipient is entitled to and whether the
 * delivery row exists — not whether Laragon can reach a mail server. The decision itself is exercised
 * against the real `AlertDelivery`.
 *
 * Run: php scripts/verify-alerts.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/*
 * Pre-clean, before anything is measured. A run that dies halfway — a broken pipe, a Ctrl-C, a fatal in
 * a later section — would otherwise leave its fixtures behind, and the next run would report the schema
 * misbehaving when the truth is that a previous run never reached its cleanup. Slice 7 learned this from
 * six runs' worth of audit rows that every check above them still passed over.
 *
 * The register is empty by design between runs (§20.7), so clearing it here costs nothing real. Company 2
 * is removed only when it is *this* file's company, never as a blanket "everything but company 1".
 */
$probeUserIds = DB::table('users')->where('name', 'like', 'Verify %')->pluck('id')->all();

DB::table('alerts')->delete();
DB::table('notifications')->delete();
DB::table('failed_jobs')->where('queue', 'alerts')->delete();
// Unprocessed deliveries from a run that died halfway. Left alone, they are a notification for an alert
// row this file has already forgotten creating — and on this machine a `queue:work` from an earlier
// session can pick one up at any moment, which shows up as residue in a cleanup section that did nothing
// to make it.
DB::table('jobs')->where('queue', 'alerts')->delete();
DB::table('operational_events')->where('aggregate_type', 'alert')->delete();
DB::table('documents')->where('title', 'like', 'Fixture:%')->delete();

// Children before parents: `reminder_thresholds.company_id` has a real FK, so a half-removed tenant is a
// company this file cannot delete until its configuration rows go first.
DB::table('reminder_thresholds')->where('company_id', '!=', 1)->delete();

// Every threshold row this file creates, by its unique key. A run that dies before the cleanup section —
// as one did, on a `BadMethodCallException` in the render block below — leaves these behind, and the next
// run then aborts on the unique index in a section three hundred lines later that has nothing to do with
// the crash. Keeping the list next to the one that deletes them is what stops the two drifting.
foreach ([['licence', 21], ['insurance', 25]] as [$family, $daysBefore]) {
    DB::table('reminder_thresholds')->where('company_id', 1)->where('family', $family)->where('days_before', $daysBefore)->delete();
}

if ($probeUserIds !== []) {
    DB::table('notification_preferences')->whereIn('user_id', $probeUserIds)->delete();
    DB::table('model_has_roles')->whereIn('model_id', $probeUserIds)->delete();
    DB::table('users')->whereIn('id', $probeUserIds)->delete();
}

DB::table('companies')->where('name', 'Verify Second Company')->delete();

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
    $checks++;
    echo "  ok   {$what}\n";
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

function check(bool $condition, string $what, string $why = ''): void
{
    $condition ? ok($what) : failed($what, $why);
}

function refuses(string $what, callable $fn, string $expectClass): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        if ($e instanceof $expectClass) {
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)));
        } else {
            failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());
        }
    }
}

/** @param array<string, mixed> $row */
function checkRefuses(string $table, array $row, string $what): void
{
    // The transaction is not for the refusal — a statement that violates a CHECK writes nothing — it is
    // for the case where the constraint does *not* fire. That insert then stands, and the next section
    // reads a row this file created and never removed as though the schema had accepted it on purpose.
    // One measured instance: a `recipients` shape test that let a bare string through, and the sweep ten
    // checks later threw a TypeError on it while attributing the fault to the model.
    try {
        DB::beginTransaction();
        DB::table($table)->insert($row);
        DB::rollBack();

        failed($what, 'the insert was accepted');
    } catch (Throwable $e) {
        DB::rollBack();

        if ($e instanceof QueryException) {
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)));
        } else {
            failed($what, 'expected QueryException, got '.$e::class.': '.$e->getMessage());
        }
    }
}

/** @param array<string, mixed> $row */
function checkAccepts(string $table, array $row, string $what): void
{
    try {
        DB::beginTransaction();
        DB::table($table)->insert($row);
        DB::rollBack();
        ok($what);
    } catch (Throwable $e) {
        DB::rollBack();
        failed($what, $e->getMessage());
    }
}

/**
 * A candidate on the `licence` family, at whatever band and verdict the check needs.
 *
 * Built here rather than through DocumentExpirySource so the cooldown and machine tests can construct
 * the *second night's* version of a condition directly — which is the whole point of those tests, and
 * would otherwise need two fixture documents eighteen days apart.
 *
 * @param  array<string, mixed>  $overrides
 */
function candidate(array $overrides = []): ReminderCandidate
{
    // Named unpacking, so an override names the argument it replaces rather than relying on position —
    // the first version of this helper took `$overrides` and quietly ignored it, which made the
    // escalation checks pass and fail in exactly the wrong places.
    return new ReminderCandidate(...array_merge([
        'type' => AlertType::DocExpiry,
        'severity' => AlertSeverity::Warning,
        'family' => 'licence',
        'daysBefore' => 30,
        'subjectType' => 'document',
        'subjectId' => '01VERIFYALERT00000000001',
        'occurredOn' => Carbon::parse('2026-10-27'),
        'bucket' => '2026-12-01',
        'title' => 'Fixture licence expires 2026-12-01',
        'payload' => ['band_days' => 30, 'expires_on' => '2026-12-01'],
    ], $overrides));
}

/**
 * A current document with no bytes behind it.
 *
 * `RegisterDocument` + `UploadDocumentVersion` are slice 7's door and they are tested there. The source
 * under test reads the documents *index* — lifecycle, type, expiry — and nothing about the bytes, so a
 * fixture that wrote real PDFs would be testing storage a second time and leaving objects on the private
 * disk for the cleanup to fail over. Every row this file writes is removed by id at the end.
 *
 * @param  array<string, mixed>  $overrides
 */
function fixtureDocument(array $overrides = []): Document
{
    /** @var Document $document */
    $document = Document::forceCreate(array_merge([
        'type' => DocumentType::Licence->value,
        'title' => 'Fixture: truck BWP-123 licence',
        'lifecycle' => Lifecycle::Current->value,
        'retention_class' => 'legal',
        'expires_on' => Carbon::now()->addDays(29)->toDateString(),
    ], $overrides));

    return $document;
}

$createdDocumentIds = [];
$createdUserIds = [];
$createdAlertIds = [];
$createdThresholdIds = [];

/**
 * Drain only the alerts queue, then put the tenant context back.
 *
 * Two halves, both earned. The queue name is the one {@see AlertDelivery} routes to, so this file does not
 * pop a decade of other people's queued work and report their failures as its own. And the pin has to be
 * re-established: `DomainServiceProvider` drops the company context after every job — which is correct and
 * is exactly §20.4's queue-worker leak closed — so the sweep that continues after a drain is, from its own
 * point of view, a new process.
 */
function drainAlertQueue(): void
{
    Artisan::call('queue:work', ['--queue' => 'alerts', '--stop-when-empty' => true]);

    CurrentCompany::force(1);
}

/* ------------------------------------------------------------------------- */
echo "== §4.4 the engine has the constraints the code claims ==\n";

/** @var array<int, object> $ddl */
$ddl = DB::select('show create table alerts');
$create = (string) array_values((array) $ddl[0])[1];

foreach ([
    'alerts_type_allowed',
    'alerts_severity_allowed',
    'alerts_state_allowed',
    'alerts_subject_is_a_pair',
    'alerts_acknowledge_dated',
    'alerts_resolve_dated',
    'alerts_resolve_by_kind_allowed',
    'alerts_mute_dated',
    'alerts_state_matches_its_columns',
    'alerts_resolved_explained',
    'alerts_muted_explained',
    'alerts_resolved_is_final',
    'alerts_seen_in_order',
] as $name) {
    check(str_contains($create, $name), "the engine holds {$name}");
}

check(
    str_contains($create, 'UNIQUE KEY `alerts_company_cooldown_unique` (`company_id`,`cooldown_key`)'),
    'the cooldown unique leads with company_id — §4.4 wrote it as a bare UK, which would let one tenant swallow another\'s finding'
);

check(str_contains($create, '`resolved_by_kind`'), 'alerts carries resolved_by_kind (§14.6\'s scheduler resolution)');
check(str_contains($create, '`muted_until`'), 'alerts carries muted_until (§10\'s mute must end)');
check(str_contains($create, '`last_seen_at`'), 'alerts carries last_seen_at (the register sorts by how long a condition has lasted)');

$notificationsTable = DB::select("show tables like 'notifications'");
check(count($notificationsTable) === 1, 'the notifications table exists — User has used Notifiable since slice 5 with nowhere to write');

/* ------------------------------------------------------------------------- */
echo "\n== §4.4 every CHECK measured in both directions ==\n";

$base = [
    'company_id' => 1,
    'type' => 'doc_expiry',
    'severity' => 'warning',
    'subject_type' => 'document',
    'subject_id' => '01VERIFYALERT00000000001',
    'occurred_on' => '2026-10-27',
    'cooldown_key' => 'probe:doc_expiry:base',
    'payload' => json_encode(['title' => 'probe']),
    'state' => 'open',
    'first_seen_at' => '2026-10-27 01:00:00',
    'last_seen_at' => '2026-10-27 01:00:00',
    'created_at' => '2026-10-27 01:00:00',
    'updated_at' => '2026-10-27 01:00:00',
];

checkAccepts('alerts', $base, 'a plain open alert is accepted');
checkAccepts('alerts', ['type' => 'self_check_failure', 'subject_type' => null, 'subject_id' => null] + $base, 'the §14.6 type is accepted with no subject');
checkRefuses('alerts', ['type' => 'nonsense'] + $base, 'a type outside §4.4\'s list is refused');
checkRefuses('alerts', ['severity' => 'urgent'] + $base, 'a severity the schema does not have is refused');
checkRefuses('alerts', ['state' => 'closed'] + $base, 'a state §10 does not have is refused');
checkRefuses('alerts', ['subject_id' => null] + $base, 'half a subject is refused');
checkRefuses('alerts', ['subject_type' => null] + $base, 'and so is the other half');
checkRefuses('alerts', ['acknowledged_at' => '2026-10-27 02:00:00'] + $base, 'a timestamp with nobody behind it is refused');
checkRefuses('alerts', ['state' => 'resolved', 'resolved_at' => '2026-10-27 02:00:00', 'resolved_by' => 1] + $base, 'resolving without saying how is refused');
checkRefuses('alerts', ['state' => 'resolved', 'resolve_reason' => '   '] + $base, 'resolving with a blank reason is refused');
checkRefuses('alerts', ['state' => 'resolved', 'resolve_reason' => 'renewed'] + $base, 'resolving with no date is refused');
checkAccepts('alerts', ['state' => 'resolved', 'resolved_at' => '2026-10-27 02:00:00', 'resolved_by_kind' => 'scheduler', 'resolve_reason' => 'the next run was clean'] + $base, 'a scheduler-resolved alert is accepted with no user');
checkRefuses('alerts', ['state' => 'resolved', 'resolved_at' => '2026-10-27 02:00:00', 'resolved_by_kind' => 'user', 'resolve_reason' => 'fixed'] + $base, 'a user resolution that names no user is refused');
checkRefuses('alerts', ['state' => 'resolved', 'resolved_at' => '2026-10-27 04:00:00', 'resolved_by' => 1, 'resolved_by_kind' => 'user', 'resolve_reason' => 'x', 'acknowledged_at' => '2026-10-27 05:00:00', 'acknowledged_by' => 1] + $base, 'resolving before the acknowledgement it answers is refused');
checkRefuses('alerts', ['state' => 'muted', 'muted_until' => '2026-11-30 00:00:00', 'muted_by' => 1] + $base, 'a mute with no reason is refused');
checkRefuses('alerts', ['state' => 'muted', 'muted_by' => 1, 'mute_reason' => 'busy'] + $base, 'a mute with no end is refused — §10\'s permanent silence');
checkAccepts('alerts', ['state' => 'muted', 'muted_until' => '2026-11-30 00:00:00', 'muted_by' => 1, 'mute_reason' => 'off the road'] + $base, 'a mute with an end and a reason is accepted');
checkRefuses('alerts', ['first_seen_at' => '2026-10-27 03:00:00', 'last_seen_at' => '2026-10-27 01:00:00'] + $base, 'a condition seen again before it was first seen is refused');

// The duplicate refusal needs a committed first row, not a rolled-back one — asserting "the second
// insert is refused" against a row that was never there proves nothing about the index.
DB::table('alerts')->insert($base);
checkRefuses('alerts', $base, 'the same cooldown key twice in one company is refused');
checkAccepts('alerts', ['company_id' => 1, 'cooldown_key' => 'probe:doc_expiry:second'] + $base, 'a different condition in the same company is accepted');

// A second company for the isolation checks below, removed at the end.
DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => now(),
    'updated_at' => now(),
]);

checkAccepts('alerts', ['company_id' => 2] + $base, 'the same key in another company is accepted — the per-company unique §4.4 needed');
DB::table('alerts')->where('cooldown_key', 'like', 'probe:%')->delete();

checkRefuses('reminder_thresholds', [
    'company_id' => 1, 'family' => 'licence', 'days_before' => 30,
    'recipients' => json_encode([]), 'active' => true,
    'created_at' => now(), 'updated_at' => now(),
], 'a threshold that notifies nobody is refused');

checkRefuses('reminder_thresholds', [
    'company_id' => 1, 'family' => 'licence', 'days_before' => 30,
    'recipients' => json_encode('operations_manager'), 'active' => true,
    'created_at' => now(), 'updated_at' => now(),
], 'a bare string where a role list belongs is refused');

checkRefuses('reminder_thresholds', [
    'company_id' => 1, 'family' => 'doc_expiry', 'days_before' => 30,
    'recipients' => json_encode(['owner']), 'active' => true,
    'created_at' => now(), 'updated_at' => now(),
], 'an alert type used as a threshold family is refused — the two vocabularies §4.4 wrote as one column');

checkAccepts('reminder_thresholds', [
    'company_id' => 1, 'family' => 'licence', 'days_before' => 45,
    'recipients' => json_encode(['owner']), 'active' => true,
    'created_at' => now(), 'updated_at' => now(),
], 'a band outside 60/30/14/7 is accepted — the rows are the contract, not the defaults');

/* ------------------------------------------------------------------------- */
echo "\n== §10 the machine, from the enum that is supposed to be the only table ==\n";

check(
    AlertState::Open->allowedTransitions() === [AlertState::Acknowledged, AlertState::Muted, AlertState::Resolved],
    'open may go to acknowledged, muted or resolved'
);
check(
    AlertState::Acknowledged->allowedTransitions() === [AlertState::Muted, AlertState::Resolved],
    'acknowledged may be muted or resolved, and is never put back to open'
);
check(AlertState::Muted->canTransitionTo(AlertState::Open), 'a mute whose end has passed can be reopened — §10: muting cannot become permanent');
check(AlertState::Resolved->allowedTransitions() === [], 'resolved is final; a recurrence is a new row');
check(! AlertState::Resolved->isLive() && AlertState::Muted->isLive(), 'isLive() means "still needs attention", and a muted row still does');
check(
    ! AlertState::Acknowledged->requiresReason()
    && AlertState::Muted->requiresReason()
    && AlertState::Resolved->requiresReason(),
    'the two exits that make a claim about the world need a reason; having seen it does not (§10.1)'
);
check(
    AlertType::DocExpiry->respectsOptOut(AlertSeverity::Warning)
    && ! AlertType::DocExpiry->respectsOptOut(AlertSeverity::Critical),
    'a critical alert outranks an opt-out (§14.1)'
);

echo "\n== §10 the Actions are the only way a state moves ==\n";

$transition = app(TransitionAlert::class);
$raise = app(RaiseAlert::class);

$first = $raise->handle(candidate());
$probeAlert = $first->alert;
$createdAlertIds[] = $probeAlert->id;

check($first->isNew, 'the first raise creates a row');

refuses('resolving it with no reason is refused', fn () => $transition->handle($probeAlert, AlertState::Resolved, null, null, 1), AlertTransitionRefused::class);
refuses('a mute with no end is refused', fn () => $transition->handle($probeAlert, AlertState::Muted, 'off the road', null, 1), InvalidArgumentException::class);
refuses('the scheduler cannot acknowledge on somebody\'s behalf', fn () => $transition->handle($probeAlert, AlertState::Acknowledged, null, null, null, ActorKind::Scheduler), InvalidArgumentException::class);

$acknowledged = $transition->handle($probeAlert, AlertState::Acknowledged, null, null, 1);
check($acknowledged->state === AlertState::Acknowledged && (int) $acknowledged->acknowledged_by === 1, 'a person may acknowledge, and the row says who');

$muted = $transition->handle($acknowledged, AlertState::Muted, 'vehicle off the road until the audit', Carbon::parse('2026-10-26 23:59:59'), 1);
check($muted->state === AlertState::Muted, 'an acknowledged alert may be muted, with a reason and an end');

$reopenReport = app(ReminderRunner::class)->runOne(1);
check($reopenReport->mutesReopened === 1, 'the sweep reopens a mute whose end has passed (§10, measured rather than promised)');
check($muted->fresh()->state === AlertState::Open, '...and the row says open again');

$reopenEvents = OperationalEvent::query()->where('aggregate_type', 'alert')->where('aggregate_id', (string) $muted->id)->get();

// `muted → open`, specifically. Counting every event that *arrived* at open is not this check: the raise
// that created the row wrote one of those too, which is what this assertion first mistook for a missing
// transition.
check(
    $reopenEvents->filter(
        fn (OperationalEvent $e): bool => (string) $e->from_state === AlertState::Muted->value
            && (string) $e->to_state === AlertState::Open->value
    )->count() === 1,
    'the reopen writes the transition event it came from'
);
$reopened = $reopenEvents->first(
    fn (OperationalEvent $e): bool => (string) $e->from_state === AlertState::Muted->value
        && (string) $e->to_state === AlertState::Open->value
);

check(
    (string) $reopened?->actor_kind === ActorKind::Scheduler->value,
    '...attributed to the scheduler, not to whoever happened to be signed in (§20.4\'s leak, refused at the door)'
);
check(
    $reopened !== null && $reopened->actor_id === null,
    '...with no user id attached, because there was no user in the room when the clock expired'
);
check(
    $reopenEvents->where('to_state', AlertState::Acknowledged->value)->where('actor_kind', ActorKind::User->value)->count() === 1,
    'and the human decision earlier in the row\'s life is still there, attributed to the human'
);

$unguarded = Alert::query()->findOrFail($muted->id);
$unguarded->fill(['state' => AlertState::Resolved->value, 'resolved_at' => now(), 'notify_count' => 99]);
$unguarded->save();
check($unguarded->fresh()->state !== AlertState::Resolved, 'a screen cannot mass-assign state — the transitions belong to the Actions (§4.4)');
check($unguarded->fresh()->notify_count === 0, '...and cannot invent a delivery count');

echo "\n== §14.1 the cooldown is a lookup on the condition, not a counter ==\n";

$second = $raise->handle(candidate());
check(! $second->isNew && $second->refreshed, 'the same condition seen the next night refreshes instead of duplicating');
check(! $second->shouldDeliver(), '...and tells nobody again — the fourteen emails §14.1 forbids');
check($second->alert->id === $probeAlert->id, '...on the same row, not a new one');

$escalated = $raise->handle(candidate([
    'severity' => AlertSeverity::Critical,
    'daysBefore' => 14,
    'payload' => ['band_days' => 14, 'expires_on' => '2026-12-01'],
]));
check($escalated->escalated && $escalated->shouldDeliver(), 'a condition crossing into a tighter band goes out again');
check($escalated->alert->fresh()->severity === AlertSeverity::Critical, '...and the row carries the severity it reached');
check($escalated->alert->id === $probeAlert->id, '...still on the one row: one paper, one alert, however many warnings');

$notAnEscalation = $raise->handle(candidate([
    'severity' => AlertSeverity::Critical,
    'daysBefore' => 14,
    'payload' => ['band_days' => 14, 'expires_on' => '2026-12-01'],
]));
check(! $notAnEscalation->escalated && ! $notAnEscalation->shouldDeliver(), 'a third night in the same band says nothing new');

$closed = $transition->handle($escalated->alert, AlertState::Resolved, 'renewed licence filed as a successor document', null, 1);
$recurrence = $raise->handle(candidate([
    'severity' => AlertSeverity::Info,
    'daysBefore' => 60,
    'payload' => ['band_days' => 60, 'expires_on' => '2026-12-01'],
]));
$createdAlertIds[] = $recurrence->alert->id;
check($recurrence->isNew, 'the same condition after a resolve is a new row — history is not rewritten by reopening it');
check($recurrence->alert->cooldown_key === $closed->cooldown_key.'#2', '...with the episode suffix that makes it legal under the unique index');
check(
    Alert::query()->where('cooldown_key', $closed->cooldown_key)->where('state', AlertState::Resolved->value)->exists(),
    '...and the first occurrence is untouched'
);

$episodes = false;

try {
    for ($n = 0; $n < 14; $n++) {
        /** @var Alert $live */
        $live = Alert::query()->where('cooldown_key', $closed->cooldown_key.'#'.($n + 2))->firstOrFail();
        $transition->handle($live, AlertState::Resolved, 'cycle '.($n + 2), null, 1);
        $flapping = $raise->handle(candidate());
        $createdAlertIds[] = $flapping->alert->id;
    }
} catch (RuntimeException $refused) {
    $episodes = true;
}

check($episodes, 'a condition resolved and re-raised twelve times stops rather than filling the register with a fight');

echo "\n== §14.1 thresholds are rows, and rows mean what they say ==\n";

$repository = app(ThresholdRepository::class);
$sources = iterator_to_array(app()->tagged('reminder_sources'));

$firstEnsure = $repository->ensureDefaults($sources);
$secondEnsure = $repository->ensureDefaults($sources);

check($firstEnsure === [] || count($firstEnsure) === 20, 'a company either has its ladder or is given it — '.count($firstEnsure).' rows written this run');
check($secondEnsure === [], '...and the next run writes nothing: ensure is idempotent and never re-creates what an Owner deleted');

$set = $repository->forCurrentCompany();
check($set->count() >= 20, 'the ladder is 20 rows for the five expiring document kinds, got '.$set->count());
check($set->buckets('licence') === [60, 30, 14, 7], 'furthest horizon first, so the widest window a condition is inside answers');
check($set->find('licence', 45) === null, 'a band nobody configured answers null, so nothing fires for it');

$extra = ReminderThreshold::create(['family' => 'licence', 'days_before' => 21, 'recipients' => ['owner']]);
$createdThresholdIds[] = $extra->id;
check($repository->forCurrentCompany()->find('licence', 21)?->daysBefore === 21, 'a new band is live the moment the row exists — rows, not constants');

$extra->forceFill(['active' => false])->save();
check($repository->forCurrentCompany()->find('licence', 21) === null, 'switching a row off stops that reminder without deleting the history of it');

$empty = ThresholdSet::fromRows(ReminderThreshold::query()->whereRaw('1 = 0')->get());
check(! $empty->has('licence'), 'no rows means no reminders — the reading §14.1 commits to');

echo "\n== §14.1 recipients are roles, resolved inside a company ==\n";

$holder = User::create([
    'name' => 'Verify Operations Manager',
    'email' => 'verify-alerts-'.Str::lower(Str::random(8)).'@fleetguard.local',
    'password' => Str::random(32),
]);
$holder->forceFill(['email_verified_at' => now()])->save();
$createdUserIds[] = (int) $holder->id;

CurrentCompany::force(1);

// The role is looked up as a row, the way CreateOwner does it. `assignRole('operations_manager')` by name
// asks spatie for a role in the *guard*, and a role that exists per company with a guard_name of its own
// is not that lookup — the name form throws RoleDoesNotExist and a verifier error looks like an app bug.
/** @var Role $operationsManagerRole */
$operationsManagerRole = Role::query()
    ->where('name', 'operations_manager')
    ->where('company_id', 1)
    ->firstOrFail();

$holder->assignRole($operationsManagerRole);

$resolver = app(RecipientResolver::class);
$people = $resolver->resolve(new Threshold('licence', 30, ['operations_manager']));
check($people->count() === 1, 'the role holder in this company is found — not zero, which is the hazard this slice named before writing the code');
check((int) $people->first()?->id === (int) $holder->id, '...and it is the person just put in the role');

CurrentCompany::force(null);

// Measured, not assumed: the first version of this check expected an empty list. With
// `permission.teams` on, spatie resolves a role *within* the current team, so an unpinned lookup throws
// instead. The resolver now asks for the context by name so the message is §8's, and the behaviour worth
// recording is that an unpinned sweep cannot quietly notify nobody.
refuses(
    'with no tenant pinned the lookup fails loudly instead of answering nobody',
    fn () => $resolver->resolve(new Threshold('licence', 30, ['operations_manager'])),
    MissingCompanyContext::class,
);

CurrentCompany::force(1);

check(
    $resolver->resolve(new Threshold('licence', 30, ['auditor']))->count() === 0,
    'a pinned company whose role simply has no holder does answer empty — which is why an unnotified alert is a finding, not a silence'
);
refuses('a threshold naming a role that does not exist throws instead of silently notifying nobody', fn () => $resolver->resolve(new Threshold('licence', 30, ['bursar'])), InvalidArgumentException::class);

NotificationPreference::create(['user_id' => $holder->id, 'type' => 'doc_expiry', 'channel' => 'mail', 'enabled' => false]);
check(! $resolver->wants($holder, AlertType::DocExpiry, 'mail', AlertSeverity::Warning), 'a warning respects the mail opt-out');
check($resolver->wants($holder, AlertType::DocExpiry, 'mail', AlertSeverity::Critical), 'a critical alert does not — §14.1\'s exception is not a checkbox');
check($resolver->wants($holder, AlertType::DocExpiry, 'database', AlertSeverity::Warning), 'the opt-out is per channel: the bell still gets it');

$noMail = User::create(['name' => 'Verify No Mail', 'email' => '', 'password' => Str::random(32)]);
$noMail->forceFill(['email_verified_at' => now()])->save();
$noMail->assignRole(Role::query()->where('name', 'dispatcher')->where('company_id', 1)->firstOrFail());
$createdUserIds[] = (int) $noMail->id;
check(! $resolver->wants($noMail, AlertType::DocExpiry, 'mail', AlertSeverity::Critical), 'nobody is mailed at an address that is not there, even for a critical alert');

echo "\n== §10.8/§14.1 what the expiry source reports ==\n";

$today = Carbon::now(CurrentCompany::timezone())->startOfDay();
$source = app(DocumentExpirySource::class);

foreach ([59, 29, 13, 0, -3] as $offset) {
    $createdDocumentIds[] = fixtureDocument(['expires_on' => $today->copy()->addDays($offset)->toDateString()])->id;
}

$createdDocumentIds[] = fixtureDocument(['lifecycle' => Lifecycle::Draft->value, 'expires_on' => $today->copy()->addDays(5)->toDateString()])->id;
$createdDocumentIds[] = fixtureDocument(['lifecycle' => Lifecycle::Superseded->value, 'expires_on' => $today->copy()->addDays(6)->toDateString()])->id;
$createdDocumentIds[] = fixtureDocument(['type' => DocumentType::Pod->value, 'expires_on' => $today->copy()->addDays(4)->toDateString()])->id;

$found = [];

foreach ($source->candidates($repository->forCurrentCompany(), $today) as $each) {
    $found[(string) $each->subjectId] = $each;
}

check(count($found) === 5, 'one candidate per live expiring paper — not one per threshold band it is inside');
check(! isset($found[$createdDocumentIds[5]]), 'a draft is not a paper anyone is relying on, so it does not alert');
check(! isset($found[$createdDocumentIds[6]]), 'a superseded document is evidence of what was on file; its successor alerts instead');
check(! isset($found[$createdDocumentIds[7]]), 'a POD is an event that happened, so it is not in the expiry machine at all (§10.8)');

$expected = [
    59 => [60, AlertSeverity::Info],
    29 => [30, AlertSeverity::Info],
    13 => [14, AlertSeverity::Warning],
    0 => [7, AlertSeverity::Warning],
    -3 => [7, AlertSeverity::Critical],
];

foreach ([59 => 0, 29 => 1, 13 => 2, 0 => 3, -3 => 4] as $offset => $index) {
    $id = $createdDocumentIds[$index];
    $each = $found[$id] ?? null;

    check(
        $each !== null && $each->daysBefore === $expected[$offset][0],
        sprintf('a licence %s days out reports the %d-day band', $offset >= 0 ? '+'.$offset : $offset, $expected[$offset][0])
    );
    check(
        $each !== null && $each->severity === $expected[$offset][1],
        sprintf('...at severity %s', $expected[$offset][1]->value)
    );
}

check(
    ($found[$createdDocumentIds[4]]->payload['validity'] ?? '') === 'expired',
    'the already-lapsed paper is reported as expired, and Validity is what said so — no column holds that word (§10.8)'
);

check(
    ($found[$createdDocumentIds[3]]->payload['validity'] ?? '') === 'expiring_soon',
    'and one expiring today is still expiring_soon, not expired — the business-day edge, measured'
);

// The band edges themselves, measured against a real row rather than a hand-built Carbon. `expires_on` is
// a `date` cast, so it arrives as midnight **UTC** while the business day is midnight **Africa/Gaborone**,
// and comparing those two instants put every boundary two hours off: the offsets above (59/29/13) all
// passed while a paper exactly on the sixty-day horizon produced no candidate at all, so the alert that
// was supposed to escalate as it crossed 30 and 14 never existed.
$boundary = fixtureDocument(['expires_on' => $today->copy()->addDays(60)->toDateString()]);

check(
    Validity::derive($boundary->expires_on, $today, 60) === Validity::ExpiringSoon,
    'a paper exactly on the horizon is inside the band — §10.8 says inclusive, and the offset said not'
);
check(
    Validity::derive($today->copy()->addDays(61)->startOfDay(), $today, 60) === Validity::Valid,
    '...and one day past it is outside'
);
check(
    Validity::daysUntil($boundary->expires_on, $today) === 60,
    '...with a days_until_expiry that agrees with the state printed beside it (it reported 59 for this row)'
);

$boundaryFound = [];

foreach ($source->candidates($repository->forCurrentCompany(), $today) as $each) {
    $boundaryFound[(string) $each->subjectId] = $each;
}

check(
    ($boundaryFound[$boundary->id]->daysBefore ?? null) === 60,
    '...and the source now reports it, in the widest band it is inside'
);

// The same arithmetic from the other side of the prime meridian, which is what turns "a band fired two
// hours late" into "a truck was stood down a day early". Deterministic: neither date is today's.
$westOfUtc = Carbon::parse('2026-11-10 09:00', 'America/Sao_Paulo');

check(
    Validity::derive(Carbon::parse('2026-11-10'), $westOfUtc, 7) === Validity::ExpiringSoon,
    'a company west of UTC still has its paper today: its midnight UTC was three hours *before* that '
        ."business day's midnight, so the derivation said Expired"
);

// Not left in the register: the end-to-end run below counts the five papers it created.
Document::query()->whereKey($boundary->id)->delete();

// A second tenant, pinned the way the scheduler pins one, with its own thresholds: without the rows the
// source correctly reports nothing and the isolation check would pass for the wrong reason.
CurrentCompany::force(2);
$repository->ensureDefaults($sources);
$otherCompanyDocument = fixtureDocument(['expires_on' => $today->copy()->addDays(9)->toDateString()])->id;
$createdDocumentIds[] = $otherCompanyDocument;
$otherCandidates = iterator_to_array($source->candidates($repository->forCurrentCompany(), $today));
CurrentCompany::force(1);

check(count($otherCandidates) === 1, 'company 2 sees only its own paper — one candidate, its own');
check(
    ! isset($found[$otherCompanyDocument]),
    'and company 1\'s run never reports it — the alert row\'s company_id is not a guess'
);

echo "\n== §14.1 a nightly run, end to end ==\n";

Alert::query()->where('subject_id', '01VERIFYALERT00000000001')->delete();

// A second holder of the same role, so the delivery is measured between two people rather than inside
// one mailbox. §14.1's whole claim is that one alert reaching two recipients leaves two independent
// unread states on one finding — which nothing else in this file can see, and a single recipient makes
// "nobody else's dot moved" unassertable.
$other = User::create([
    'name' => 'Verify Second Operations Manager',
    'email' => 'verify-alerts-2-'.Str::lower(Str::random(8)).'@fleetguard.local',
    'password' => Str::random(32),
]);
$other->forceFill(['email_verified_at' => now()])->save();
$other->assignRole($operationsManagerRole);
$createdUserIds[] = (int) $other->id;

$beforeAlerts = Alert::query()->where('company_id', 1)->count();
$beforeDeliveries = $holder->notifications()->count();

// Narrowed to one tenant, because the interesting failure of a full sweep belongs to its own section
// below: company 2 has thresholds and no role rows yet, and a run that reached it would report the
// finding as "nobody was told", which would make the exit code here 1 for reasons this check does not
// care about. `runOne()` is the same code path the scheduler uses.
$exit = Artisan::call('reminders:run', ['--company' => 1]);
// Read before draining: `Artisan::call()` replaces the output buffer, so a `queue:work` in between hands
// back the worker's log and every claim about what the command *said* is measured against the wrong run.
$output = Artisan::output();
drainAlertQueue();

check($exit === 0, 'reminders:run exits 0 when everything it raised reached somebody', $output);

$live = Alert::query()->where('company_id', 1)->live()->get();
check($live->count() > $beforeAlerts, 'the run created the alerts the source described');
check(
    $live->whereIn('subject_id', $createdDocumentIds)->count() === 5,
    '...one row per expiring paper, each naming its own company'
);
check($live->every(fn (Alert $a) => (int) $a->company_id === 1), 'no row leaked into another tenant');

$delivered = $holder->notifications()->where('type', AlertRaised::class)->count();
check(
    DB::table('failed_jobs')->where('queue', 'alerts')->count() === 0,
    'not one alert delivery failed on the worker — the bug this check exists for was a queued job that re-read the company-scoped Alert and could not find it'
);

check($delivered > $beforeDeliveries, 'the role holder received a delivery of each one (§14.1: notification is a delivery of an alert)');
check(
    (int) Alert::query()->where('company_id', 1)->where('notify_count', '>', 0)->count() === $live->where('notify_count', '>', 0)->count(),
    '...and every alert that reached somebody counts exactly one delivery, not one per sighting'
);

$sample = $holder->notifications()->where('type', AlertRaised::class)->latest('id')->first();
check(
    $sample !== null && isset($sample->data['alert_id'], $sample->data['url']),
    'the delivery carries the alert id and the register link — the bell needs no second query to render'
);
check(
    $sample !== null && Alert::query()->whereKey($sample->data['alert_id'])->exists(),
    '...and the id points at a row that exists'
);

$unread = Alert::countUnreadDeliveriesFor($holder);
$otherUnread = Alert::countUnreadDeliveriesFor($other);
check($unread > 0, 'the bell counts unread deliveries of live alerts');
check((int) $holder->unread_alerts_count === $unread, '...through the one accessor the topbar is allowed to use (§DESIGN-SYSTEM §7)');
check($otherUnread === $unread, '...and the second holder of the same role sees the same findings, as their own rows');

$again = Artisan::call('reminders:run', ['--company' => 1]);
drainAlertQueue();

check($again === 0, 'a second run in the same conditions exits clean');
check(
    Alert::query()->where('company_id', 1)->live()->count() === $live->count(),
    '...and raises nothing: the cooldown held across the real command, not just the Action'
);
check(
    $holder->notifications()->where('type', AlertRaised::class)->count() === $delivered,
    '...and tells nobody twice (§14.1\'s fourteen emails, refused)'
);

$one = $holder->notifications()->where('type', AlertRaised::class)->latest('id')->first();
$targetAlert = Alert::query()->findOrFail((int) $one->data['alert_id']);

$one->markAsRead();
check(
    Alert::countUnreadDeliveriesFor($holder) === $unread - 1,
    'reading one delivery clears one dot for this person'
);
check(
    Alert::countUnreadDeliveriesFor($other) === $otherUnread,
    '...and nobody else\'s — the other recipient of that same alert has not read theirs (§14.1: the finding '
        .'is one row, the dots are per person)'
);

$transition->handle($targetAlert, AlertState::Resolved, 'the licence was renewed and filed', null, (int) $holder->id);
check(
    Alert::countUnreadDeliveriesFor($other) === $otherUnread - 1,
    '...and resolving the alert behind another person\'s unread delivery drops it from their count too — '
        .'unread alone would keep telling them about something that is finished'
);
check(
    Alert::countUnreadDeliveriesFor($holder) === $unread - 1,
    '...while the person who had already read it sees no further change, because resolving is not reading'
);

echo "\n== §14.1 a reminder that reaches nobody is a defect, not a quiet success ==\n";

$orphan = ReminderThreshold::create(['family' => 'insurance', 'days_before' => 25, 'recipients' => ['auditor']]);
$createdThresholdIds[] = $orphan->id;
$createdDocumentIds[] = fixtureDocument([
    'type' => DocumentType::Insurance->value,
    'title' => 'Fixture: policy with no auditor',
    'expires_on' => $today->copy()->addDays(20)->toDateString(),
])->id;

$exit = Artisan::call('reminders:run', ['--company' => 1]);
// Read before draining: `Artisan::call()` replaces the output buffer, so a `queue:work` in between hands
// back the worker's log and every claim about what the command *said* is measured against the wrong run.
$output = Artisan::output();
drainAlertQueue();

check($exit === 1, 'the command fails the run when an alert was raised that told nobody');
check(str_contains($output, 'told nobody'), '...and says which one, and why');
check(
    Alert::query()->where('company_id', 1)->where('type', AlertType::DocExpiry->value)->where('notify_count', 0)->exists(),
    '...the alert is on the register regardless — the finding is not lost, only the delivery is'
);

echo "\n== §8/§14.1 one unprovisioned tenant cannot silence the others ==\n";

// Company 2 has thresholds and no staff, which is the normal state of a company on its first week. The
// first version of the resolver asked spatie for the role by name and let it throw — so the nightly sweep
// died on company 2 and every company after it got no reminders at all, quietly, because the scheduler
// marks the job failed and nobody reads failed jobs at 03:00.
$wholeRun = Artisan::call('reminders:run');
$wholeOutput = Artisan::output();
drainAlertQueue();

check(
    // `withoutGlobalScopes()` because this is the one assertion in the file about a tenant the file is
    // not standing inside. Scoped, `Alert::query()` would silently filter the other company's rows out
    // and the check would fail for the reason it exists to rule out.
    Alert::query()->withoutGlobalScopes()->where('company_id', 2)->where('type', AlertType::DocExpiry->value)->live()->exists(),
    'the second tenant\'s own expiring paper is recorded even though nobody in it holds a role yet'
);
check(
    str_contains($wholeOutput, 'company #2') && str_contains($wholeOutput, 'nobody was told'),
    '...and the run says so out loud, naming the tenant, with a non-zero exit, rather than passing silently'
);
check($wholeRun === 1, '...a sweep that raised something nobody received is a failed run (§14.1\'s promise kept by the exit code)');
check(
    ! str_contains($wholeOutput, 'RoleDoesNotExist') && ! str_contains($wholeOutput, 'Exception'),
    '...and it got there without throwing: one tenant\'s missing staffing is a finding, not an abort'
);

CurrentCompany::force(1);

echo "\n== §14.6 every scheduled self-check failure raises an alert ==\n";

$alerter = app(SelfCheckAlerter::class);

$raised = $alerter->reportFindings('ledger:verify', 'probe drift', [
    ['entry' => 'e1', 'entry_no' => 'JE-1', 'company' => 1, 'finding' => 'total_debit is 100 but the lines sum to 90'],
    ['entry' => 'e2', 'entry_no' => 'JE-2', 'company' => 2, 'finding' => 'lines are out of balance: debits 10, credits 4'],
]);

check($raised['raised'] === 2, 'one alert per company named by the findings, not one for the run');
check(
    Alert::query()->withoutGlobalScopes()->where('type', AlertType::SelfCheckFailure->value)->where('company_id', 2)->exists(),
    '...including the second tenant, whose ledger the same sweep also looked at'
);

$global = $alerter->reportFindings('ledger:verify', 'probe', [
    ['entry' => '-', 'entry_no' => 'barrier', 'finding' => 'the gl_ledger_barrier row is present — ledger immutability is currently OFF'],
]);
check(count($global['unattributable']) === 1, 'a finding with no tenant is returned rather than filed under a guess');
check($global['raised'] === 0, '...and raises nothing at all');

$clearedOne = $alerter->clear('ledger:verify', 'the narrowed run was clean', 1);
check($clearedOne === 1, 'a clean run for one company resolves that company\'s finding');
check(
    Alert::query()->withoutGlobalScopes()->where('company_id', 2)->where('type', AlertType::SelfCheckFailure->value)->live()->exists(),
    '...and leaves another tenant\'s alert open — the bug this file caught while being written: --company narrowed the check, so the evidence says nothing about anyone else'
);

$companyOneClosure = Alert::query()->where('company_id', 1)->where('type', AlertType::SelfCheckFailure->value)->where('state', AlertState::Resolved->value)->latest('id')->first();
check($companyOneClosure !== null && $companyOneClosure->resolved_by_kind === ActorKind::Scheduler, 'closed by the scheduler, and the row says which principal');
check($companyOneClosure !== null && $companyOneClosure->resolved_by === null, '...without inventing a person who was not there');

$alerter->clear('ledger:verify', 'the whole sweep came back clean');
$flap = $alerter->reportFindings('ledger:verify', 'drift again', [
    ['entry' => 'e3', 'entry_no' => 'JE-3', 'company' => 1, 'finding' => 'line_count is 2 but 3 lines exist'],
]);
check($flap['raised'] === 1, 'a check that fails again after being cleared raises a fresh alert, because the old one is history');

// Two measurements, in the order that makes each one honest. The fixture register is deliberately broken
// paper — `fixtureDocument()` files a `current` document with no version at all — so the real integrity
// sweep *must* fail on it, and §14.6 says that failure has to reach the register rather than stdout. The
// "still passes" claim describes a different database state, so it is measured after the fixtures are
// gone. Asserting it while they were still filed measured nothing but the fixture.
$integrityExit = Artisan::call('documents:verify-integrity');
$integrityOutput = Artisan::output();

check($integrityExit === 1, 'the real documents:verify-integrity fails on a current document with no current copy');
check(
    str_contains($integrityOutput, 'no version flagged current'),
    '...and names the document and what is missing rather than only that something is wrong'
);
check(
    Alert::query()->withoutGlobalScopes()->live()
        ->where('cooldown_key', 'self_check_failure:-:-:documents:verify-integrity')
        ->pluck('company_id')->sort()->values()->all() === [1, 2],
    '...and raises one alert per tenant with broken paper, each naming its own company'
);

// The fixtures leave, and with them the honest reason the sweep was failing.
Document::query()->withoutGlobalScopes()->whereIn('id', $createdDocumentIds)->delete();
$createdDocumentIds = [];

foreach (['ledger:verify', 'documents:number-integrity', 'documents:verify-integrity'] as $command) {
    $code = Artisan::call($command);
    check($code === 0, 'the real '.$command.' passes on a database with nothing wrong in it');
}

check(
    Alert::query()->withoutGlobalScopes()->live()->where('type', AlertType::SelfCheckFailure->value)->count() === 0,
    '...and every self-check alert the failing runs raised is closed again: the fix resolves the finding it '
        .'came from, which is the difference between a signal and wallpaper'
);

$flapEpisode = Alert::query()->withoutGlobalScopes()
    ->where('cooldown_key', 'self_check_failure:-:-:ledger:verify#2')
    ->first();
check(
    $flapEpisode !== null && $flapEpisode->state === AlertState::Resolved,
    '...including the second occurrence, which does not sit on the base key — the bug this file caught while '
        .'being written: clearing matched `base` alone, so a check that failed twice was never closed'
);

// The self-check path queues on the same named queue as the reminders, and its payload is built the same
// snapshot way. Draining it here is the only way to know that a `self_check_failure` delivery reaches a
// real person's bell instead of `failed_jobs`: the earlier drain in this file only ever saw `doc_expiry`.
drainAlertQueue();

check(
    DB::table('failed_jobs')->where('queue', 'alerts')->where('payload', 'like', '%self_check_failure%')->count() === 0,
    '...and not one of them failed on the worker'
);

echo "\n== §9.3 the doors ==\n";

check(Route::has('alerts.index'), 'the register route exists — which is what lets the topbar bell render at all');

$route = Route::getRoutes()->getByName('alerts.index');
$middleware = $route === null ? [] : $route->gatherMiddleware();
check(in_array('can:alerts.view', $middleware, true), 'the gate is on the route where a reviewer can see it (§9.4), not in a component that might remember');

CurrentCompany::force(1);

// The read-only role §9.2 describes as "reads everything, changes nothing" is the one that proves the
// register's third permission means something: every role can see the alert list, so `alerts.view` alone
// would let the Auditor resolve a compliance finding.
$reader = User::create([
    'name' => 'Verify Auditor',
    'email' => 'verify-auditor-'.Str::lower(Str::random(8)).'@fleetguard.local',
    'password' => Str::random(32),
]);
$reader->forceFill(['email_verified_at' => now()])->save();
$reader->assignRole(Role::query()->where('name', 'auditor')->where('company_id', 1)->firstOrFail());
$createdUserIds[] = (int) $reader->id;

check(Gate::forUser($holder)->allows('alerts.view'), 'the role that runs the trucks may see the register');
check(Gate::forUser($holder)->allows('alerts.acknowledge'), '...and may acknowledge or mute what §14.1 delivers to it');
check(Gate::forUser($holder)->allows('alerts.resolve'), '...and may close one, with a reason');
check(Gate::forUser($noMail)->allows('alerts.view'), 'a dispatcher may see it (§9.3: alerts.view is granted to every role)');
check(Gate::forUser($noMail)->allows('alerts.acknowledge'), '...and may act on it — §14.1\'s own example is the Ops Manager *and* the Dispatcher both acting on one alert');
check(! Gate::forUser($noMail)->allows('alerts.resolve'), '...but may not reach the terminal state, which the engine cannot undo (`alerts_resolved_is_final`)');
check(Gate::forUser($reader)->allows('alerts.view'), 'the Auditor reads the register (§9.2)');
check(! Gate::forUser($reader)->allows('alerts.acknowledge'), '...and holds neither write');
check(! Gate::forUser($reader)->allows('alerts.resolve'), '...including the one that is final');

$navigation = (array) config('navigation');
$flat = json_encode($navigation);
check(is_string($flat) && str_contains($flat, 'alerts.index'), 'the register is in the sidebar, gated on alerts.view');

echo "\n== §9.4 the screens a passing gate cannot see ==\n";

// Three defects in this slice were invisible to every assertion above — including `Gate::forUser()` and
// `Route::has()` — and all three were found by loading the URL in a browser: the register defined
// `private function transition()`, which collides with the public `transition()` Livewire's
// SupportTransitions feature mixes into every component (a compile-time fatal: the screen 500s before its
// first query while the route, the middleware and the gate all still read correct); the Muted badge named
// `speaker-wave`, which is not in the icon registry, and `x-ui/icon` throws on an unknown name rather than
// drawing nothing — only on the one row shape that is muted; and both reason fields omitted the `name`
// prop `x-ui.input` uses to look a validation error up, so §10.1's mandatory reason refused the submit and
// said nothing on screen.
//
// The screen is rendered in the browser pass, which is where they were caught (§20.8). An in-process
// render was tried and dropped: `AuthenticateSession` rightly drops a guard with no session row behind it,
// so the tenancy middleware has no user to read a company from and the gate script fails for being a
// script. What it *can* own is the shape, across every page rather than only this one.

/**
 * Every Blade file in the application, so the three guards below cover screens this slice never
 * touched rather than only the register.
 *
 * @return list<string>
 */
$bladeFiles = static function (): array {
    $path = base_path('resources/views');
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
};

$views = $bladeFiles();
$sources = [];

foreach ($views as $file) {
    $sources[$file] = (string) file_get_contents($file);
}

check(count($views) > 40, 'the view sweep found the screens to read ('.count($views).' Blade files)');

// 1 — a page may not define a method Livewire's own base class already uses publicly.
/** @var list<string> $livewireApi */
$livewireApi = array_values(array_filter(
    get_class_methods(Component::class),
    static fn (string $m): bool => ! str_starts_with($m, '__'),
));

$collisions = [];

foreach ($sources as $file => $blade) {
    if (! str_contains($blade, 'class extends Component')) {
        continue;
    }

    if (preg_match_all('/(private|protected)\s+function\s+(\w+)\s*\(/', $blade, $found) !== false) {
        foreach ($found[2] as $index => $method) {
            if (in_array($method, $livewireApi, true)) {
                $collisions[] = basename((string) $file).': '.$found[1][$index].' '.$method.'()';
            }
        }
    }
}

check(
    $collisions === [],
    'no page shadows a public Livewire\Component method — `private function transition()` did, and the '
        .'register 500ed before its first query',
    implode(', ', $collisions)
);

// 2 — every icon a view names must exist in the registry, because `x-ui/icon` throws rather than
//     drawing nothing, and a badge that only renders for one row state can hide for weeks.
$registry = (string) file_get_contents(base_path('resources/views/components/ui/icon.blade.php'));
$known = [];

if (preg_match_all("/^\s*'([a-z0-9-]+)' =>/m", $registry, $keys) !== false) {
    $known = $keys[1];
}

check(count($known) > 40, 'the icon registry was readable ('.count($known).' names)');

$unknownIcons = [];

foreach ($sources as $file => $blade) {
    if (preg_match_all('/\bicon="([a-z0-9-]+)"/', $blade, $used) !== false) {
        foreach (array_unique($used[1]) as $icon) {
            if (! in_array($icon, $known, true)) {
                $unknownIcons[] = basename((string) $file).' → '.$icon;
            }
        }
    }
}

check(
    $unknownIcons === [],
    'every static icon name in every view is in the registry — the muted badge named `speaker-wave`, '
        .'which is not, and only a Muted row draws it',
    implode(', ', $unknownIcons)
);

// 3 — an input bound to state must carry `name`, or `x-ui.input` has no key to look the validation
//     error up with and the submit refuses in silence.
$anonymousInputs = [];

foreach ($sources as $file => $blade) {
    if (preg_match_all('/<x-ui\.input\b(.*?)\/>/s', $blade, $blocks) !== false) {
        foreach ($blocks[1] as $block) {
            if (str_contains($block, 'wire:model') && ! preg_match('/\bname="/', $block)) {
                $anonymousInputs[] = basename((string) $file).' → '.trim(preg_replace('/\s+/', ' ', $block) ?? '');
            }
        }
    }
}

check(
    $anonymousInputs === [],
    'every wire:model-bound x-ui.input can show its own validation error — both reason fields on the '
        .'register omitted `name`, so §10.1\'s mandatory reason said nothing',
    mb_substr(implode(' | ', $anonymousInputs), 0, 300)
);

echo "\n== cleanup, and the proof it worked ==\n";

// By type rather than by the accounts this file created: §14.6's self-check alerts are delivered to the
// real Owner, whose rows a `whereIn($createdUserIds)` would leave behind — a bell dot for a finding that
// no longer exists, on an account that is not a fixture.
DB::table('notifications')->where('type', AlertRaised::class)->delete();
DB::table('failed_jobs')->where('queue', 'alerts')->delete();
DB::table('jobs')->where('queue', 'alerts')->delete();
Alert::query()->withoutGlobalScopes()->delete();
DB::table('operational_events')->where('aggregate_type', 'alert')->delete();
DB::table('notification_preferences')->whereIn('user_id', $createdUserIds)->delete();
DB::table('reminder_thresholds')->whereIn('id', $createdThresholdIds)->delete();
DB::table('reminder_thresholds')->where('company_id', '!=', 1)->delete();
DB::table('model_has_roles')->whereIn('model_id', $createdUserIds)->delete();
DB::table('users')->whereIn('id', $createdUserIds)->delete();
Document::query()->withoutGlobalScopes()->whereIn('id', $createdDocumentIds)->delete();
DB::table('companies')->where('id', 2)->delete();

check((int) DB::table('alerts')->count() === 0, 'no alert rows survive this run');
check((int) DB::table('notifications')->count() === 0, 'no delivery rows survive');
check((int) DB::table('jobs')->where('queue', 'alerts')->count() === 0, 'no delivery is still queued for an alert that no longer exists');
check((int) DB::table('operational_events')->where('aggregate_type', 'alert')->count() === 0, 'no alert trail rows survive');
check((int) Document::query()->withoutGlobalScopes()->count() === 0, 'no fixture documents survive — the register is empty by design (§20.7)');
check((int) DB::table('users')->whereIn('id', $createdUserIds)->count() === 0, 'no temporary staff accounts survive');
check((int) DB::table('companies')->where('id', '!=', 1)->count() === 0, 'the probe company is gone');
check(
    in_array(1, DB::table('users')->pluck('id')->all(), true),
    'and the real accounts are still here: '.json_encode(DB::table('users')->pluck('id')->all())
);
check(
    (int) DB::table('reminder_thresholds')->where('company_id', 1)->count() === 20,
    'company 1 keeps its twenty threshold rows: they are the Owner\'s configuration, not fixture residue'
);

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
