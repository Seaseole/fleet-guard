<?php

use App\Domain\Accounting\Data\ProfitAndLoss;
use App\Domain\Accounting\Data\TrialBalance;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use App\Domain\Reporting\Series\AgeingBuckets;
use App\Domain\Reporting\Series\ChartWindow;
use App\Domain\Reporting\Series\FleetSnapshot;
use App\Domain\Reporting\Series\FuelPerTruck;
use App\Domain\Reporting\Series\CashTrends;
use App\Domain\Security\Backup\Models\BackupHealth;
use App\Domain\Security\Models\Invitation;
use App\Domain\Security\QueueHealth;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The Owner's home, redesigned 2026-11-03 (docs/HOME-DESIGN.md).
 *
 * Its idea is the argument the whole application makes: an operational fact and a ledger fact must be
 * reconciled, and between them sit exactly four seams — earned and not billed, spent and not posted,
 * received and not applied, advanced and not settled (the two-seam rule, the handover, the
 * unapplied cash, the driver advances). So the screen opens on the money sitting on the first of
 * them and shows the other three in the same shape, rather than as twenty-six figures at one volume.
 * DESIGN-SYSTEM.md the first rule still orders the page — what needs me before what happened — and DESIGN-SYSTEM.md the
 * Display role is now spent once, on the figure that has a decision inside it.
 *
 * Nothing here is a second source of truth. Every figure is counted from the rows the module already
 * owns and read through the door that owns it — `LedgerStatements`, `AgeingReport`, `BackupHealth`,
 * `QueueHealth`, `Validity`'s derivation rather than a re-computed expiry band — because a dashboard
 * that decided "expiring soon" for itself would disagree with the register two clicks away.
 * Where two bands need the same statement, it is walked once (`ledgerBalance`, `monthSheet`).
 *
 * Permission masks rather than deletes (DESIGN-SYSTEM.md the metric-card row): a seat that cannot open the staff
 * register still sees that a staff figure exists and which ability unlocks it. Hiding the card would
 * report "nothing to see here", which is a different claim from one you cannot check.
 */
new #[Title('Dashboard')] class extends Component {
    /**
     * Where to work. Deliberately excludes the registers the figures above already link to.
     *
     * @var list<array{label: string, route: string, icon: string, permission: string|null}>
     */
    private const DESTINATIONS = [
        ['label' => 'Journal entries', 'route' => 'accounting.journal', 'icon' => 'calculator', 'permission' => 'ledger.view'],
        ['label' => 'Post a manual journal', 'route' => 'accounting.journal.create', 'icon' => 'plus', 'permission' => 'ledger.journal.manual'],
        ['label' => 'Bank and cash accounts', 'route' => 'accounting.bank-accounts', 'icon' => 'banknotes', 'permission' => 'bank.view'],
        ['label' => 'Register a document', 'route' => 'documents.register', 'icon' => 'folder', 'permission' => 'documents.upload'],
        ['label' => 'Staff accounts and invitations', 'route' => 'security.users', 'icon' => 'users', 'permission' => 'users.view'],
        ['label' => 'Your devices', 'route' => 'sessions.edit', 'icon' => 'key', 'permission' => null],
        ['label' => 'Security and two-factor authentication', 'route' => 'security.edit', 'icon' => 'shield-check', 'permission' => null],
        ['label' => 'Your profile', 'route' => 'profile.edit', 'icon' => 'cog', 'permission' => null],
        ['label' => 'Appearance', 'route' => 'appearance.edit', 'icon' => 'computer-desktop', 'permission' => null],
    ];

    /** The nightly referee, by its own command name. */
    private const TIE_CHECK = 'reports:tie-to-ledger';

    /** The trip states that have left the yard and not finished. */
    private const ON_THE_ROAD = [
        TripStatus::Departed->value,
        TripStatus::InTransit->value,
        TripStatus::AtBorder->value,
        TripStatus::Arrived->value,
    ];

    /** Trips whose costs ought to be on the ledger by now (the handover). */
    private const COST_DUE = [
        TripStatus::Arrived->value,
        TripStatus::PodPending->value,
        TripStatus::Delivered->value,
        TripStatus::Completed->value,
    ];

    /**
     * The window the charts answer, straight from the URL so a trend is a link worth sending to the
     * accountant. Blank means the trailing six months. These re-window the charts only, never the
     * figures above: "Revenue this month" is a label with a door behind it, and letting a filter
     * rewrite it would make the label a lie.
     */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            // Either order is typable; answering it beats 500-ing on it.
            if ($this->from !== '' && $this->to !== '' && $this->from > $this->to) {
                $this->to = $this->from;
            }

            unset($this->chartWindow, $this->charts);
        }
    }

    public function clearWindow(): void
    {
        $this->from = '';
        $this->to = '';
    }

    #[Computed]
    public function chartWindow(): ChartWindow
    {
        return ChartWindow::fromRequest($this->from, $this->to, CarbonImmutable::instance($this->businessToday()));
    }

    /* ------------------------------------------------------------------ the one figure */

    /**
     * The view's Display figure: earnings that exist on delivered work and have no invoice yet.
     *
     * It is the hero rather than cash on hand because DESIGN-SYSTEM.md the design asks what needs me, and cash carries no
     * decision on an ordinary morning — this figure does: raise the invoice, or say why not. The `note`
     * is the second line the number cannot say alone (DESIGN-SYSTEM.md applied to a figure rather than a badge):
     * how long the oldest ready chargeable has waited, which is what turns P 0,00 into a task.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hero(): array
    {
        if (! Gate::allows('invoices.view')) {
            return ['gated' => true, 'label' => __('Earned, not billed'), 'gate' => 'invoices.view'];
        }

        $seam = $this->readySeam;

        return [
            'label' => __('Earned, not billed'),
            'value' => $this->money($seam['total']),
            'caption' => $seam['count'] === 0
                ? __('Nothing is on the counter: every accepted delivery has its invoice.')
                : __(':count chargeables across:trips delivered trip(s), still unraised.', [
                    'count' => $seam['count'],
                    'trips' => $seam['trips'],
                ]),
            'note' => $seam['waiting_days'] === null
                ? null
                : __('The oldest has waited :days day(s); the run is raised from the trip.', ['days' => $seam['waiting_days']]),
            // Fourteen days of unbilled earnings is where "in the run" becomes "forgotten". A derived
            // judgement rather than a colour: the note carries the words either way.
            'tone' => $seam['waiting_days'] !== null && $seam['waiting_days'] > 14 ? 'warning' : 'neutral',
            'href' => route('operations.dispatch'),
        ];
    }

    /* ------------------------------------------------------------------- the four seams */

    /**
     * The three seams the hero is not. The first of the four — earned and not billed — is the view's one
     * Display figure and is rendered at the head of this band, so the band reads as one argument in four
     * cells instead of the same figure twice on one screen.
     *
     * These are counts of documents and balances the ledger itself holds, and the balances come off the
     * same trial-balance walk the cash figure uses, so the page never asks the ledger the same question
     * twice.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function seams(): array
    {
        $spent = $this->unpostedSeam;
        $receipts = $this->unappliedReceipts;
        // 2150 is a credit balance and `netMinor()` is signed the ledger's way, debit positive.
        // The Owner's question is how much is sitting there, so the rail shows the credit as a positive
        // figure rather than a minus nobody reads.
        $applied = max(0, -$this->ledgerLineMinor('2150'));
        $advanced = max(0, $this->ledgerLineMinor('1160'));

        return [
            $this->seamCard(
                allowed: Gate::allows('expenses.create'),
                label: __('Spent, not posted'),
                ability: 'expenses.create',
                minor: $spent['total'],
                word: $this->plural($spent['count'], __('trip'), __('trips')),
                caption: __('Arrived or finished, and the ledger has no direct cost for them yet.'),
                zeroCaption: __('Every trip that has arrived carries its costs.'),
                href: route('operations.dispatch'),
            ),

            $this->seamCard(
                allowed: Gate::allows('invoices.view'),
                label: __('Received, not applied'),
                ability: 'invoices.view',
                minor: $applied,
                word: $this->plural($receipts, __('receipt'), __('receipts')),
                caption: __('Money that has landed and no invoice has claimed.'),
                zeroCaption: __('No receipt is waiting for an invoice to spend it.'),
                href: route('invoicing.index'),
            ),

            $this->seamCard(
                allowed: Gate::allows('ledger.view'),
                label: __('Advanced, not settled'),
                ability: 'ledger.view',
                minor: $advanced,
                word: __('on the ledger'),
                caption: __('Driver float the fleet has not recovered — the ledger holds it, and no desk clears it yet.'),
                zeroCaption: __('No driver is holding company cash on the ledger.'),
                href: route('accounting.balance-sheet'),
            ),
        ];
    }

    /**
     * @param  int  $minor  base minor units
     * @return array<string, mixed>
     */
    private function seamCard(bool $allowed, string $label, string $ability, int $minor, string $word, string $caption, string $zeroCaption, string $href): array
    {
        if (! $allowed) {
            return ['label' => $label, 'gated' => true, 'gate' => $ability];
        }

        return [
            'label' => $label,
            'figure' => $this->money($minor),
            'word' => $minor === 0 ? __('settled') : $word,
            // Each empty seam says what is empty in its own words: one sentence repeated four times
            // reads as a template rather than as a screen that knows the difference (rules).
            'caption' => $minor === 0 ? $zeroCaption : $caption,
            'tone' => $minor > 0 ? 'warning' : 'success',
            'href' => $href,
        ];
    }

    /** @return array{count: int, total: int, trips: int, waiting_days: int|null} */
    #[Computed]
    public function readySeam(): array
    {
        $row = Chargeable::query()->where('status', ChargeableStatus::Ready->value)
            ->selectRaw('count(*) as c, coalesce(sum(base_amount), 0) as total, coalesce(count(distinct trip_id), 0) as trips, min(created_at) as oldest')
            ->first();

        $oldest = $row === null ? null : ($row->oldest ?? null);

        return [
            'count' => (int) ($row->c ?? 0),
            'total' => (int) ($row->total ?? 0),
            'trips' => (int) ($row->trips ?? 0),
            'waiting_days' => $oldest === null
                ? null
                : (int) Carbon::parse((string) $oldest)->copy()->startOfDay()->diffInDays($this->businessToday()),
        ];
    }

    /** @return array{count: int, total: int} */
    #[Computed]
    public function unpostedSeam(): array
    {
        $row = Trip::query()->whereIn('status', self::COST_DUE)->whereNull('ledger_posted_at')
            ->selectRaw('count(*) as c, coalesce(sum(direct_cost_base), 0) as total')
            ->first();

        return ['count' => (int) ($row->c ?? 0), 'total' => (int) ($row->total ?? 0)];
    }

    #[Computed]
    public function unappliedReceipts(): int
    {
        return Payment::query()->where('unapplied_base', '>', 0)->count();
    }

    /* ------------------------------------------------------------------------ the road */

    /**
     * The five stages in work order (the design's own ordering: a job nobody has accepted can be dispatched
     * as many times as it likes and mean nothing, so the decision comes first and the proof last). Four
     * equal tiles said these five numbers in an order the reader had to invent.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function pipelineSteps(): array
    {
        $jobs = $this->jobCounts;
        $trips = $this->tripCounts;
        $held = (int) ($trips[TripStatus::OnHold->value] ?? 0);
        $border = (int) ($trips[TripStatus::AtBorder->value] ?? 0);
        $proof = (int) ($trips[TripStatus::PodPending->value] ?? 0);
        $road = $this->onTheRoad;

        return [
            Gate::allows('jobs.view') ? [
                'label' => __('Enquiries'),
                'figure' => (string) (int) ($jobs[JobStatus::Enquiry->value] ?? 0),
                'word' => __('unpriced'),
                'caption' => __('Work that has no price on it yet.'),
                'tone' => 'neutral',
                'href' => route('operations.jobs', ['status' => JobStatus::Enquiry->value]),
            ] : $this->gatedStep(__('Enquiries'), 'jobs.view'),

            Gate::allows('jobs.view') ? [
                'label' => __('Quoted, awaiting'),
                'figure' => (string) (int) ($jobs[JobStatus::Quoted->value] ?? 0),
                'word' => __('a decision is owed'),
                'caption' => __('Priced and unanswered — every day here is a truck standing somewhere.'),
                'tone' => (int) ($jobs[JobStatus::Quoted->value] ?? 0) > 0 ? 'warning' : 'neutral',
                'href' => route('operations.jobs', ['status' => JobStatus::Quoted->value]),
            ] : $this->gatedStep(__('Quoted, awaiting'), 'jobs.view'),

            Gate::allows('trips.view') ? [
                'label' => __('Planned, not dispatched'),
                'figure' => (string) (int) ($trips[TripStatus::Planned->value] ?? 0),
                'word' => $held > 0 ? __(':count held off the road', ['count' => $held]) : __('board is clear'),
                'caption' => __('A truck and a crew still to be committed.'),
                'tone' => $held > 0 ? 'danger' : ((int) ($trips[TripStatus::Planned->value] ?? 0) > 0 ? 'warning' : 'neutral'),
                'href' => route('operations.dispatch'),
            ] : $this->gatedStep(__('Planned, not dispatched'), 'trips.view'),

            Gate::allows('trips.view') ? [
                'label' => __('On the road'),
                'figure' => (string) $road,
                'word' => $border > 0 ? __(':count at a border post', ['count' => $border]) : __('none at a post'),
                'caption' => __('Departed, in transit, at the gate or arrived.'),
                'tone' => $border > 0 ? 'warning' : 'neutral',
                'href' => route('operations.dispatch'),
            ] : $this->gatedStep(__('On the road'), 'trips.view'),

            Gate::allows('trips.view') ? [
                'label' => __('Proof outstanding'),
                'figure' => (string) $proof,
                'word' => __(':count signed today', ['count' => $this->signedToday]),
                'caption' => __('Delivered on the road, undelivered on the paper.'),
                'tone' => $proof > 0 ? 'danger' : 'success',
                'href' => route('operations.dispatch'),
            ] : $this->gatedStep(__('Proof outstanding'), 'trips.view'),
        ];
    }

    /** @return array<string, mixed> */
    private function gatedStep(string $label, string $ability): array
    {
        return [
            'label' => $label,
            'figure' => '—',
            'word' => __('not available to your role'),
            'caption' => __('Requires :ability.', ['ability' => $ability]),
            'tone' => 'neutral',
            'href' => null,
        ];
    }

    /** @return Collection<string, int> */
    #[Computed]
    public function jobCounts(): Collection
    {
        return Job::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
    }

    /** @return Collection<string, int> */
    #[Computed]
    public function tripCounts(): Collection
    {
        return Trip::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
    }

    #[Computed]
    public function onTheRoad(): int
    {
        $trips = $this->tripCounts;

        return array_sum(array_map(static fn (string $s): int => (int) ($trips[$s] ?? 0), self::ON_THE_ROAD));
    }

    #[Computed]
    public function signedToday(): int
    {
        return Trip::query()->where('status', TripStatus::Delivered->value)
            ->whereDate('arrived_at', $this->businessToday()->toDateString())
            ->count();
    }

    /* ------------------------------------------------------------------ instruments */

    /**
     * The four readings that are only news next to a limit somebody set — `documents.expiry_warning_days`
     * (the derivation, not a threshold invented here), the fleet's own size, the roster's own size,
     * and `backup.stale_after_days` (the "silence is loud"). A measurement with no limit in the
     * frame leaves the reader to grade it alone, which is how a dashboard becomes a wall of numbers.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function instruments(): array
    {
        $window = $this->warningDays();
        $expiring = $this->expiring;

        // The nearest lapse is the decision; the count beside it is the size of the job. Both come from
        // `Validity`, so the register two clicks away cannot answer differently.
        $nearest = $expiring->isEmpty()
            ? null
            : $expiring
                ->map(fn (Document $document): ?int => Validity::daysUntil($document->expires_on, $this->businessToday()))
                ->filter()
                ->sort()
                ->first();

        $staleAfter = (int) config('fleet.backup.stale_after_days');
        $backup = BackupHealth::forJob(BackupHealth::JOB_EXPORT);
        $offRoad = $this->workshop['inWorkshop'] + $this->workshop['outOfService'];
        $drivers = $this->drivers;

        return [
            Gate::allows('documents.view') ? [
                'label' => __('Nearest paper'),
                'valueText' => $nearest === null
                    ? __('none inside')
                    : ($nearest < 0 ? __('lapsed :days d', ['days' => abs($nearest)]) : __(':days d left', ['days' => $nearest])),
                'value' => $nearest,
                'limit' => $nearest === null ? null : $window,
                'limitText' => __('against a :window-day window', ['window' => $window]),
                'caption' => $expiring->isEmpty()
                    ? __('No current document lapses inside the window.')
                    : __(':count papers inside it, :lapsed already lapsed.', ['count' => $this->expiry['inside'], 'lapsed' => $this->expiry['lapsed']]),
                'tone' => $nearest === null ? 'neutral' : ($nearest < 0 ? 'danger' : ($nearest <= 7 ? 'warning' : 'neutral')),
                'href' => route('documents.index'),
            ] : ['label' => __('Nearest paper'), 'gated' => true, 'gate' => 'documents.view'],

            Gate::allows('fleet.view') ? [
                'label' => __('Off the road'),
                'valueText' => (string) $offRoad,
                'value' => $offRoad,
                'limit' => $this->fleet['total'] > 0 ? $this->fleet['total'] : null,
                'limitText' => __('of :total on the books', ['total' => $this->fleet['total']]),
                'caption' => __(':workshop in the workshop ·:service out of service.', [
                    'workshop' => $this->workshop['inWorkshop'],
                    'service' => $this->workshop['outOfService'],
                ]),
                'tone' => $this->workshop['outOfService'] > 0 ? 'warning' : 'neutral',
                'href' => route('fleet.index', ['status' => FleetStatus::OutOfService->value]),
            ] : ['label' => __('Off the road'), 'gated' => true, 'gate' => 'fleet.view'],

            Gate::allows('people.view') ? [
                'label' => __('Drivers cleared'),
                'valueText' => (string) $drivers['cleared'],
                'value' => $drivers['cleared'],
                'limit' => $drivers['onBooks'] > 0 ? $drivers['onBooks'] : null,
                'limitText' => __('of :books on the roster', ['books' => $drivers['onBooks']]),
                'caption' => __('Asked of `eligibleOn` for today — the same question dispatch will ask.'),
                'tone' => $drivers['cleared'] < $drivers['onBooks'] ? 'warning' : 'success',
                'href' => route('people.index', ['tab' => 'drivers']),
            ] : ['label' => __('Drivers cleared'), 'gated' => true, 'gate' => 'people.view'],

            Gate::allows('backups.view') ? [
                'label' => __('Off-site copy'),
                'valueText' => $backup === null || $backup->last_success_at === null
                    ? __('never taken')
                    : __(':days d old', ['days' => $this->backupAge($backup)]),
                'value' => $backup === null || $backup->last_success_at === null ? null : $this->backupAge($backup),
                'limit' => $staleAfter > 0 ? $staleAfter : null,
                'limitText' => __('stale after :days', ['days' => $staleAfter]),
                'caption' => $backup === null || $backup->last_success_at === null
                    ? __('No copy has been recorded; the export runs on the first of the month.')
                    : (! $backup->ok
                        ? __('The last run did not complete; the age shown is the last good copy.')
                        : __(':size, taken :date — the verify sweep reads it back nightly.', [
                            'size' => Number::fileSize((int) ($backup->bytes ?? 0)),
                            'date' => $backup->last_success_at->copy()->setTimezone(CurrentCompany::timezone())->format('d M'),
                        ])),
                'tone' => $backup === null || $backup->last_success_at === null || ! $backup->ok
                    ? 'danger'
                    : ($this->backupAge($backup) > $staleAfter ? 'danger' : ($this->backupAge($backup) > $staleAfter - 7 ? 'warning' : 'success')),
                'href' => null,
            ] : ['label' => __('Off-site copy'), 'gated' => true, 'gate' => 'backups.view'],
        ];
    }

    private function backupAge(BackupHealth $row): int
    {
        return (int) $row->last_success_at->copy()->setTimezone(CurrentCompany::timezone())
            ->startOfDay()->diffInDays($this->businessToday());
    }

    /* ---------------------------------------------------------------- the ledger strip */

    /**
     * The month as the ledger holds it, in the words the signed sheets use — the same doors the report
     * library answers through (Phase 8: "executive dashboard completed on real data"), so if this
     * strip and the P&L ever disagree one of them has a bug rather than a different definition.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function ledgerStrip(): array
    {
        $sheet = $this->monthSheet;
        $net = $sheet->netProfitMinor();
        $owed = $this->receivableTotal;
        $cash = $this->cashMinor();

        return [
            $this->stripItem(__('Revenue this month'), $this->money($sheet->totalRevenueMinor()), 'ledger.view', route('accounting.profit-loss'), 'neutral'),
            $this->stripItem(__('Net profit this month'), $this->money($net), 'ledger.view', route('accounting.profit-loss'), $net < 0 ? 'danger' : 'success'),
            $this->stripItem(__('Receivables outstanding'), $this->money($owed[0]), 'invoices.view', route('invoicing.ageing'), $owed[1] > 0 ? 'warning' : 'neutral'),
            $this->stripItem(__('Cash and bank'), $this->money($cash), 'ledger.view', route('accounting.bank-accounts'), $cash < 0 ? 'danger' : 'neutral'),
        ];
    }

    /** @return array<string, mixed> */
    private function stripItem(string $label, string $figure, string $ability, string $href, string $tone): array
    {
        return Gate::allows($ability)
            ? ['label' => $label, 'figure' => $figure, 'href' => $href, 'tone' => $tone]
            : ['label' => $label, 'gated' => true, 'gate' => $ability];
    }

    /** @return array{0: int, 1: int} the open total and the overdue part, from the Phase 4d door */
    #[Computed]
    public function receivableTotal(): array
    {
        $rows = app(AgeingReport::class)();

        return [
            array_sum(array_map(static fn ($row): int => $row->totalMinor(), $rows)),
            array_sum(array_map(static fn ($row): int => $row->overdueMinor(), $rows)),
        ];
    }

    /**
     * The one sentence that says which truth the ledger holds for this month — said once for the whole
     * strip rather than four times, and said only to a seat that can check it. A zero is a real answer
     * and gets its own words rather than a figure nobody reads (DESIGN-SYSTEM.md the discipline applied to a caption);
     * a dispatcher holding no `ledger.view` sees no sentence at all, which `render-reporting` checks.
     */
    #[Computed]
    public function ledgerNote(): string
    {
        if (! Gate::allows('ledger.view')) {
            return '';
        }

        if ($this->monthSheet->totalRevenueMinor() === 0) {
            return __('Nothing has been earned on the books this month.');
        }

        return __('Posted through :date, read from the ledger.', ['date' => $this->businessToday()->format('d M')]);
    }

    /* --------------------------------------------------------------------- the desk */

    /**
     * The counts the desk is judged by, beside the rows themselves: what is open, what is waiting on a
     * signature, whether the machinery that carries the rest has failed, and what the referee last said.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function desk(): array
    {
        $alerts = $this->alerts;
        $invitations = $this->invitations;
        $queue = $this->queue;

        return [
            'critical' => $alerts['critical'],
            'open' => $alerts['open'],
            'muted' => $alerts['muted'],
            'live' => $alerts['live'],
            'invitations' => $invitations['pending'],
            'invitations_expiring' => $invitations['expiring'],
            'failed_jobs' => $queue['failed'],
            'waiting_jobs' => $queue['waiting'],
            'queue_unavailable' => $queue['unavailable'],
            'alerts_gated' => ! Gate::allows('alerts.view'),
            'documents_gated' => ! Gate::allows('documents.view'),
        ];
    }

    /* ------------------------------------------------------------------- shared reads */

    /**
     * The statement the money figures and two of the four seams are read from, walked once per paint.
     * `monthSheet` is the precedent: the same figure asked twice by two cards is one query and one
     * chance to disagree with yourself.
     */
    #[Computed]
    public function ledgerBalance(): TrialBalance
    {
        return app(LedgerStatements::class)->trialBalance($this->businessToday());
    }

    private function ledgerLineMinor(string $code): int
    {
        foreach ($this->ledgerBalance->lines as $line) {
            if ($line->code === $code) {
                return $line->netMinor();
            }
        }

        return 0;
    }

    private function cashMinor(): int
    {
        $cash = 0;

        foreach ($this->ledgerBalance->lines as $line) {
            if ($line->code === '1110' || $line->code === '1120') {
                $cash += $line->netMinor();
            }
        }

        return $cash;
    }

    /**
     * The referee's last word. A live self-check alert whose bucket is the tie-out means
     * the operational rows and the ledger stopped telling the same story — the one condition on this
     * page that earns continuous motion, and the only thing here that gets it.
     */
    #[Computed]
    public function drift(): bool
    {
        if (! Gate::allows('alerts.view')) {
            return false;
        }

        return Alert::query()->live()
            ->where('type', AlertType::SelfCheckFailure->value)
            // The bucket is the check's own name and it is the last segment of the cooldown key
            // (SelfCheckAlerter::candidate), so the suffix is what identifies the finding.
            ->where('cooldown_key', 'like', '%'.self::TIE_CHECK)
            ->exists();
    }

    /** The day in one sentence, built only from figures counted above. */
    #[Computed]
    public function headline(): string
    {
        $seams = $this->readySeam['total']
            + $this->unpostedSeam['total']
            + max(0, -$this->ledgerLineMinor('2150'))
            + max(0, $this->ledgerLineMinor('1160'));

        return __(':road out on the road · :border at a border post · :held held · :seams sitting on the seams · :referee', [
            'road' => $this->onTheRoad,
            'border' => (int) ($this->tripCounts[TripStatus::AtBorder->value] ?? 0),
            'held' => (int) ($this->tripCounts[TripStatus::OnHold->value] ?? 0),
            'seams' => $this->money($seams),
            'referee' => $this->drift
                ? __('the tie-out has found drift')
                : __('the tie-out is clean'),
        ]);
    }

    /* -------------------------------------------------------------------- the picture */

    /**
     * What happened, drawn. Gated exactly as the figures are (masked, never deleted), and a closed
     * seat's payload is never built at all, so nothing ships in JSON that CSS would only have hidden.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function charts(): array
    {
        return [
            Gate::allows('ledger.view') ? $this->trendChart() : $this->lockedChart(__('Cash in, cash out'), 'ledger.view'),
            Gate::allows('invoices.view') ? $this->ageingChart() : $this->lockedChart(__('Receivables by ageing bucket'), 'invoices.view'),
            Gate::allows('fuel.view') ? $this->fuelChart() : $this->lockedChart(__('Fuel by truck'), 'fuel.view'),
            Gate::allows('fleet.view') ? $this->fleetChart() : $this->lockedChart(__('Fleet by state'), 'fleet.view'),
        ];
    }

    /**
     * The cash trends span the band: twelve buckets of diverging columns need the width to be read at
     * all, and the Week / Month / Year control lives beside the total, not under it.
     *
     * @return array<string, mixed>
     */
    private function trendChart(): array
    {
        return [
            'trends' => app(CashTrends::class)($this->chartWindow),
            'href' => route('accounting.cash-flow'),
            'hrefLabel' => __('Open the cash-flow statement'),
            'span' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private function ageingChart(): array
    {
        return [
            'series' => app(AgeingBuckets::class)($this->chartWindow),
            'href' => route('invoicing.ageing'),
            'hrefLabel' => __('Open the ageing report'),
        ];
    }

    /** @return array<string, mixed> */
    private function fuelChart(): array
    {
        return [
            'series' => app(FuelPerTruck::class)($this->chartWindow),
            'href' => route('fuel.index'),
            'hrefLabel' => __('Open the fuel log'),
        ];
    }

    /** @return array<string, mixed> */
    private function fleetChart(): array
    {
        return [
            'series' => app(FleetSnapshot::class)($this->chartWindow),
            'href' => route('fleet.index'),
            'hrefLabel' => __('Open the fleet register'),
        ];
    }

    /** @return array<string, mixed> */
    private function lockedChart(string $label, string $ability): array
    {
        return ['label' => $label, 'gated' => true, 'gate' => $ability];
    }

    /* ---------------------------------------------------------------------- the rows */

    /** @return array{live: int, critical: int, open: int, muted: int} */
    #[Computed]
    public function alerts(): array
    {
        return [
            'live' => Alert::query()->live()->count(),
            'critical' => Alert::query()->live()->where('severity', AlertSeverity::Critical->value)->count(),
            'open' => Alert::query()->live()->where('state', AlertState::Open->value)->count(),
            'muted' => Alert::query()->live()->where('state', AlertState::Muted->value)->count(),
        ];
    }

    /**
     * The five rows that outrank everything else, on the register's own ordering: severity first, then
     * how long the condition has been standing — a critical alert nobody has moved in eleven days is not
     * behind one raised two minutes ago.
     *
     * @return Collection<int, Alert>
     */
    #[Computed]
    public function attention(): Collection
    {
        return Alert::query()->live()
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get();
    }

    /**
     * Current paper whose expiry is inside the warning window, oldest first, with what this seat may not
     * read already removed — the visibility narrowing applies to a title on this screen just as it
     * does to a row on the register.
     *
     * @return Collection<int, Document>
     */
    #[Computed]
    public function expiring(): Collection
    {
        $horizon = $this->businessToday()->addDays($this->warningDays());

        return Document::query()
            ->where('lifecycle', Lifecycle::Current->value)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $horizon)
            ->orderBy('expires_on')
            ->get()
            ->filter(fn (Document $document): bool => Gate::allows('view', $document))
            ->values();
    }

    /** @return array{inside: int, lapsed: int, soon: int} */
    #[Computed]
    public function expiry(): array
    {
        $rows = $this->expiring;
        $lapsed = $rows->filter(fn (Document $document): bool => $document->validity() === Validity::Expired)->count();

        return [
            'inside' => $rows->count(),
            'lapsed' => $lapsed,
            'soon' => $rows->count() - $lapsed,
        ];
    }

    /** @return array{pending: int, expiring: int} */
    #[Computed]
    public function invitations(): array
    {
        $pending = fn () => Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', Carbon::now());

        return [
            'pending' => $pending()->count(),
            'expiring' => $pending()->where('expires_at', '<=', Carbon::now()->addDays(7))->count(),
        ];
    }

    /** @return array{failed: int, waiting: int, delayed: int, unavailable: bool} */
    #[Computed]
    public function queue(): array
    {
        $rows = app(QueueHealth::class)->summary();

        return [
            'failed' => array_sum(array_column($rows, 'failed')),
            'waiting' => array_sum(array_column($rows, 'pending')),
            'delayed' => array_sum(array_column($rows, 'delayed')),
            'unavailable' => in_array(true, array_column($rows, 'unavailable'), true),
        ];
    }

    /** @return list<array{label: string, route: string, icon: string}> */
    #[Computed]
    public function destinations(): array
    {
        return array_values(array_filter(
            self::DESTINATIONS,
            // No `permission` key means the screen belongs to every authenticated seat — the same
            // convention config/navigation.php uses, so the two lists cannot disagree about who sees
            // what. An empty ability string would be a permission nobody can grant, not a wildcard.
            fn (array $item): bool => $item['permission'] === null || Gate::allows($item['permission']),
        ));
    }

    /** @return array{onTrip: int, total: int} */
    #[Computed]
    public function fleet(): array
    {
        return [
            'onTrip' => Truck::query()->where('status', FleetStatus::OnTrip->value)->count(),
            'total' => Truck::query()->where('status', '!=', FleetStatus::Sold->value)->count(),
        ];
    }

    /** @return array{inWorkshop: int, outOfService: int} */
    #[Computed]
    public function workshop(): array
    {
        return [
            'inWorkshop' => Truck::query()->where('status', FleetStatus::InWorkshop->value)->count(),
            'outOfService' => Truck::query()->where('status', FleetStatus::OutOfService->value)->count(),
        ];
    }

    /**
     * Drivers the company could put on a trip today, asked of the driver row rather than of a second
     * rule: `eligibleOn()` is what dispatch will ask on the day.
     *
     * @return array{cleared: int, onBooks: int}
     */
    #[Computed]
    public function drivers(): array
    {
        $today = $this->businessToday();

        $onBooks = Driver::query()
            ->whereIn('status', [DriverStatus::Active->value, DriverStatus::Standby->value])
            ->get();

        return [
            'cleared' => $onBooks->filter(fn (Driver $driver): bool => $driver->eligibleOn($today))->count(),
            'onBooks' => $onBooks->count(),
        ];
    }

    /** @return array{inside: int, lapsed: int} */
    #[Computed]
    public function driverPapers(): array
    {
        $today = $this->businessToday();
        $horizon = $today->copy()->addDays($this->warningDays());

        $rows = Driver::query()
            ->whereIn('status', [DriverStatus::Active->value, DriverStatus::Standby->value])
            ->where(function ($query) use ($horizon): void {
                $query->whereDate('licence_expires_on', '<=', $horizon)
                    ->orWhereDate('permit_expires_on', '<=', $horizon);
            })
            ->get();

        return [
            'inside' => $rows->count(),
            'lapsed' => $rows->filter(
                fn (Driver $driver): bool => ! $driver->licenceValidOn($today) || ! $driver->permitValidOn($today),
            )->count(),
        ];
    }

    /* ------------------------------------------------------------------------ helpers */

    /** This month's sheet, read once. */
    #[Computed]
    public function monthSheet(): ProfitAndLoss
    {
        $today = $this->businessToday();

        return app(LedgerStatements::class)->profitAndLoss($today->copy()->startOfMonth(), $today);
    }

    /**
     * A short word for how many things are sitting on a seam. The figure never carries the state alone
     * and the caption always names what the number counts, so this line is what a reader scans,
     * not a second way of saying the same thing twice.
     */
    private function plural(int $count, string $singular, string $plural): string
    {
        if ($count === 0) {
            return __('none');
        }

        return $count.' '.($count === 1 ? $singular : $plural);
    }

    public function money(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minorUnits, $base)->format($base);
    }

    public function businessToday(): Carbon
    {
        return Carbon::now(CurrentCompany::timezone())->startOfDay();
    }

    public function warningDays(): int
    {
        return (int) config('fleet.documents.expiry_warning_days', 60);
    }

    #[Computed]
    public function stamp(): string
    {
        return Carbon::now(CurrentCompany::timezone())->format('d M Y, H:i');
    }
}; ?>

<div class="grid gap-6">
    {{-- Masthead: who, where, the stamp the figures were counted at, and the day in one sentence. The
         zone and the stamp are the same pair the registers print, because a figure counted at
         02:00 in Gaborone is not the figure another company's clock would show. --}}
    <header class="fg-page-header grid gap-2">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <x-ui.heading size="xl" level="1">{{ __('Dashboard') }}</x-ui.heading>

            <p class="fg-identifier text-xs leading-4 text-fg-ink-muted">
                {{ CurrentCompany::model()->name }}
                · {{ __('counted :stamp (:zone)', ['stamp' => $this->stamp, 'zone' => CurrentCompany::timezone()]) }}
            </p>
        </div>

        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm leading-5 text-fg-ink-secondary">
            @if ($this->drift)
                {{-- The one continuous motion on this screen, and it is earned: a live finding is a
                     standing condition and the design says silence about one is worse than noise. It moves
                     the icon and never the words — a caption fading to 55 % is text whose measured
                     contrast falls under AA at the dimmest frame, which the first QA pass caught and
                     this shape answers. The word beside it carries the state, so the breath is never the
                     signal on its own. --}}
                <span class="inline-flex items-center gap-1.5 font-medium text-danger">
                    <x-ui.icon name="exclamation-triangle" size="4" class="fg-breathe" />
                    {{ __('Tie-out drifting') }}
                </span>
            @endif

            <span>{{ $this->headline }}</span>
        </p>
    </header>

    {{-- The argument, and the desk beside it. The band is the four seams in the order they are closed,
         and the first of them is the view's one Display figure — chosen for the
         decision inside it rather than for its size, because DESIGN-SYSTEM.md the design puts "what needs me"
         before "what happened" and a number nobody can act on is decoration. --}}
    <div class="grid gap-4">
        <section class="grid content-start gap-5 fg-panel p-5" aria-labelledby="seams-head">
            <div class="grid gap-1">
                <x-ui.heading id="seams-head">{{ __('Operations overview') }}</x-ui.heading>
                <x-ui.subheading>{{ __('Delivered work, unposted costs and unsettled balances. Select a figure to review the records.') }}</x-ui.subheading>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="border-s-2 border-accent">
                    <x-ui.figure
                        :label="$this->hero['label']"
                        :value="$this->hero['value'] ?? null"
                        :caption="$this->hero['caption'] ?? null"
                        :note="$this->hero['note'] ?? null"
                        :tone="$this->hero['tone'] ?? 'neutral'"
                        :href="$this->hero['href'] ?? null"
                        :gated="$this->hero['gated'] ?? false"
                        :gate="$this->hero['gate'] ?? null"
                        class="p-5"
                    />
                </div>

                @foreach ($this->seams as $index => $seam)
                    <div class="border-s-2 {{ ($seam['tone'] ?? 'neutral') === 'danger' ? 'border-danger' : (($seam['tone'] ?? 'neutral') === 'warning' ? 'border-warning' : 'border-fg-line') }}">
                        {{--
                             `--fg-settle-delay` in 40 ms steps, starting after the hero figure the same row
                             opens with. The four figures already settle — `x-ui.figure` puts `fg-settle` on the
                             value — but every one of them did it on the same frame, which is a block appearing
                             rather than a row of them arriving. The custom property is the house mechanism
                             rather than a class per position because Tailwind v4 cannot see a class name built
                             by interpolation.
                        --}}
                        <x-ui.figure
                            :label="$seam['label']"
                            :value="$seam['figure'] ?? null"
                            :caption="$seam['caption'] ?? null"
                            :note="$seam['word'] ?? null"
                            :tone="$seam['tone'] ?? 'neutral'"
                            :href="$seam['href'] ?? null"
                            :gated="$seam['gated'] ?? false"
                            :gate="$seam['gate'] ?? null"
                            :settle-delay="40 + ($index * 40)"
                            class="h-full p-5"
                        />
                    </div>
                @endforeach
            </div>
        </section>

        {{-- The desk: the rows behind the counts, capped at five each. The register is the screen that
             works through them; this is the screen that says which ones are next. --}}
        <section class="grid items-start gap-4 lg:grid-cols-2" aria-labelledby="desk-head">
            <h2 id="desk-head" class="sr-only">{{ __('Needs you now') }}</h2>

            @if (! $this->desk['alerts_gated'])
                <x-ui.card as="div" :title="__('Needs attention')">
                    <x-slot:header>
                        <x-ui.link :href="route('alerts.index')">
                            {{ __('Open register · :critical critical · :open open · :muted muted', [
                                'critical' => $this->desk['critical'],
                                'open' => $this->desk['open'],
                                'muted' => $this->desk['muted'],
                            ]) }}
                        </x-ui.link>
                    </x-slot:header>

                    @if ($this->attention->isEmpty())
                        <x-ui.empty-state
                            icon="check-circle"
                            :title="__('Nothing is waiting')"
                            :description="__('No source has reported a live condition for :company. Reminders run nightly, and every scheduled self-check raises an alert when it fails.', ['company' => CurrentCompany::model()->name])"
                        />
                    @else
                        <ul class="grid gap-1">
                            @foreach ($this->attention as $alert)
                                <li class="flex items-start gap-3 rounded-fg-sm px-2 py-2 transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-sunk">
                                    <x-ui.badge size="xs" :tone="$alert->severity->tone()">{{ $alert->severity->label() }}</x-ui.badge>

                                    <div class="grid min-w-0 gap-0.5">
                                        <p class="truncate text-sm text-fg-ink">{{ $alert->title() }}</p>
                                        <p class="text-xs text-fg-ink-muted">
                                            {{ $alert->type->label() }}
                                            · {{ $alert->state->label() }}
                                            · {{ $alert->last_seen_at->diffForHumans() }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @else
                <x-ui.card as="div" :title="__('Needs attention')">
                    <p class="flex items-center gap-2 text-sm text-fg-ink-muted">
                        <x-ui.icon name="lock-closed" size="4" />
                        {{ __('Not available to your role') }}
                        <span class="fg-identifier text-xs">alerts.view</span>
                    </p>
                </x-ui.card>
            @endif

            @if (! $this->desk['documents_gated'])
                <x-ui.card as="div" :title="__(':days-day expiry board', ['days' => $this->warningDays()])">
                    <x-slot:header>
                        <x-ui.link :href="route('documents.index')">{{ __('Open register · :count inside', ['count' => $this->expiry['inside']]) }}</x-ui.link>
                    </x-slot:header>

                    @if ($this->expiring->isEmpty())
                        <x-ui.empty-state
                            icon="check-circle"
                            :title="__('No paper lapses inside the window')"
                            :description="__('Only current documents are counted — a draft is paper nobody has relied on yet, and a superseded one is evidence of what was on file.')"
                        />
                    @else
                        <ul class="grid gap-1">
                            @foreach ($this->expiring->take(5) as $document)
                                @php
                                    $validity = $document->validity();
                                    $days = Validity::daysUntil($document->expires_on, $this->businessToday());
                                    $figure = $days === null ? '—' : ($days < 0 ? '−'.abs($days) : (string) $days);
                                @endphp

                                <li class="rounded-fg-sm transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-sunk">
                                    <a href="{{ route('documents.index', ['search' => $document->title]) }}" wire:navigate class="flex items-center gap-3 px-2 py-2">
                                        {{-- The column is days-to-expiry, not a date: the decision the row
                                             supports is "how long do I have", and the design puts the ISO date in
                                             the tooltip rather than in the column. --}}
                                        <span
                                            class="fg-numeric w-12 shrink-0 text-xs font-medium {{ $days !== null && $days < 0 ? 'text-danger' : 'text-fg-ink-secondary' }}"
                                            title="{{ $document->expires_on?->toDateString() }}"
                                        >{{ $figure }}</span>

                                        <span class="grid min-w-0 flex-1 gap-0.5">
                                            <span class="truncate text-sm text-fg-ink">{{ $document->title }}</span>
                                            <span class="text-xs text-fg-ink-muted">
                                                {{ $document->type->label() }}
                                                · {{ $document->expires_on->format('d M Y') }}
                                            </span>
                                        </span>

                                        <x-ui.badge size="xs" :tone="$validity->tone()">{{ $validity->label() }}</x-ui.badge>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            {{-- Two figures that used to be cards and are now one line: invitations waiting on a first
                 sign-in, and the queue's own health. Both are machinery, and machinery earns a sentence
                 rather than the same visual weight as money. --}}
            <p class="flex flex-wrap items-baseline gap-x-6 gap-y-1 px-1 text-xs leading-4 text-fg-ink-muted lg:col-span-2">
                <span>
                    {{ __(':count invitations awaiting a first sign-in', ['count' => $this->desk['invitations']]) }}

                    @if ($this->desk['invitations_expiring'] > 0)
                        · <span class="text-warning">{{ __(':n expire within 7 days', ['n' => $this->desk['invitations_expiring']]) }}</span>
                    @endif
                </span>

                <span>
                    @if ($this->desk['queue_unavailable'])
                        {{ __('The queue backend did not answer, so the backlog is unknown.') }}
                    @else
                        {{ __(':failed failed · :waiting waiting on the queue', ['failed' => $this->desk['failed_jobs'], 'waiting' => $this->desk['waiting_jobs']]) }}
                    @endif
                </span>
            </p>
        </section>
    </div>

    {{-- The month, as the ledger holds it. Same doors as the report library and the signed sheets, so a
         disagreement here is a bug in one of them rather than two definitions — and it reads
         across the page rather than as four more cards, because these four figures are context for the
         seams above, not four more decisions. --}}
    <section class="grid gap-5 fg-panel p-5" aria-labelledby="month-head">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <p id="month-head" class="text-xs font-medium tracking-label text-fg-ink-muted">{{ __('The month, as the ledger holds it') }}</p>

            @if ($this->ledgerNote !== '')
                <p class="text-xs leading-4 text-fg-ink-muted">{{ $this->ledgerNote }}</p>
            @endif
        </div>

        <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->ledgerStrip as $item)
                <div class="grid gap-0.5">
                    <dt class="text-xs leading-4 text-fg-ink-secondary">{{ $item['label'] }}</dt>

                    @if ($item['gated'] ?? false)
                        <dd class="flex items-center gap-1.5 text-sm text-fg-ink-muted">
                            <x-ui.icon name="lock-closed" size="4" />
                            <span class="fg-identifier text-xs">{{ $item['gate'] }}</span>
                        </dd>
                    @else
                        <dd>
                            <a
                                href="{{ $item['href'] }}"
                                wire:navigate
                                class="font-mono text-xl leading-7 font-medium tabular-nums transition duration-(--t-fast) ease-(--ease-standard) hover:underline {{ $item['tone'] === 'danger' ? 'text-danger' : ($item['tone'] === 'success' ? 'text-success' : 'text-fg-ink') }}"
                            >{{ $item['figure'] }}</a>
                        </dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </section>

    {{-- The road, in order. `x-ui.pipeline` is the band wearing its own sequence: the rules under
         the stages draw left-to-right on one clock, so the direction work moves is the first thing the
         band says rather than something the reader infers from four identical tiles. --}}
    <section class="grid gap-4" aria-labelledby="road-head">
        <div class="grid gap-1">
            <x-ui.heading id="road-head">{{ __('The road, in order') }}</x-ui.heading>
            <x-ui.subheading>{{ __('Enquiry to proof of delivery, in the order a job is actually worked. A held trip and a missing signature are different problems, so they read differently.') }}</x-ui.subheading>
        </div>

        <x-ui.pipeline :steps="$this->pipelineSteps" :label="__('The road, in order')" />
    </section>

    {{-- Instruments: each reading beside the limit it is graded against, and the limit named from wherever
         it is set — the derivation rule, because a dashboard that invents its own thresholds is how
         two screens begin disagreeing about the same twelve papers. --}}
    <section class="grid gap-4" aria-labelledby="instruments-head">
        <div class="grid gap-1">
            <x-ui.heading id="instruments-head">{{ __('Instruments') }}</x-ui.heading>
            <x-ui.subheading>{{ __('Each reading beside the limit the company set for it, not a threshold this screen guessed.') }}</x-ui.subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->instruments as $index => $instrument)
                <x-ui.threshold
                    :label="$instrument['label']"
                    :value-text="$instrument['valueText'] ?? null"
                    :value="$instrument['value'] ?? null"
                    :limit="$instrument['limit'] ?? null"
                    :limit-text="$instrument['limitText'] ?? null"
                    :caption="$instrument['caption'] ?? null"
                    :tone="$instrument['tone'] ?? 'neutral'"
                    :href="$instrument['href'] ?? null"
                    :gated="$instrument['gated'] ?? false"
                    :gate="$instrument['gate'] ?? null"
                    :settle-delay="$index * 40"
                    class="rounded-fg-xl border border-fg-line bg-fg-surface shadow-fg-1"
                />
            @endforeach
        </div>
    </section>

    {{-- What happened, drawn. Everything above is actionable; this band is the record, and it sits below
         it on purpose. The window belongs to this band alone — the figures above read their own
         labelled windows, and a filter that re-windowed them would make the label a lie. --}}
    <section class="grid gap-4" aria-labelledby="trends-head">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <x-ui.heading id="trends-head">{{ __('Trends and proportions') }}</x-ui.heading>
                <x-ui.subheading>{{ $this->chartWindow->label() }}</x-ui.subheading>
            </div>

            <div class="grid gap-3 sm:grid-cols-[repeat(2,minmax(0,1fr))_auto]">
                <x-ui.input
                    type="date"
                    name="from"
                    :label="__('From')"
                    :hint="__('Blank means six months back.')"
                    wire:model.live="from"
                    :value="$this->from"
                />

                <x-ui.input
                    type="date"
                    name="to"
                    :label="__('To')"
                    :hint="__('Blank means today.')"
                    wire:model.live="to"
                    :value="$this->to"
                />

                <x-ui.button variant="ghost" size="sm" wire:click="clearWindow" class="self-end" :disabled="$this->from === '' && $this->to === ''">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>
        </header>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ($this->charts as $chart)
                @if (isset($chart['trends']))
                    <x-patterns.trends-chart
                        :payload="$chart['trends']"
                        :href="$chart['href'] ?? null"
                        :href-label="$chart['hrefLabel'] ?? null"
                        :class="($chart['span'] ?? 1) > 1 ? 'xl:col-span-2' : ''"
                    />
                @else
                    <x-ui.chart
                        :series="$chart['series'] ?? null"
                        :label="$chart['label'] ?? null"
                        :gated="$chart['gated'] ?? false"
                        :gate="$chart['gate'] ?? null"
                        :href="$chart['href'] ?? null"
                        :href-label="$chart['hrefLabel'] ?? null"
                        :class="($chart['span'] ?? 1) > 1 ? 'xl:col-span-2' : ''"
                    />
                @endif
            @endforeach
        </div>
    </section>

    {{-- Navigation, subordinate to the work above it on purpose. --}}
    <x-ui.card :title="__('Where to work')" :description="__('Only the screens your role can open.')">
        <x-ui.navlist label="{{ __('Where to work') }}" class="sm:grid-cols-2">
            @foreach ($this->destinations as $destination)
                <x-ui.navlist.item
                    :href="route($destination['route'])"
                    :icon="$destination['icon']"
                    chevron
                    wire:navigate
                >{{ __($destination['label']) }}</x-ui.navlist.item>
            @endforeach
        </x-ui.navlist>
    </x-ui.card>
</div>
