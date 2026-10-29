<?php

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use App\Domain\Security\Models\Invitation;
use App\Domain\Security\QueueHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The overview. DESIGN-SYSTEM.md §1's first rule is that this screen answers "what needs me now"
 * before it answers "what happened", which sets the order of the four bands: the figures a seat can
 * act on, then the rows behind those figures, then navigation, then the roadmap — the last of which
 * used to be half of this page.
 *
 * Nothing here is a second source of truth. Every figure counts rows the module already owns, and the
 * expiry bands are derived through {@see Validity} rather than re-computed, because a dashboard that
 * decided "expiring soon" for itself would disagree with the register two clicks away — the exact
 * failure §10.8's derivation rule exists to prevent.
 *
 * Permission masks rather than deletes (DESIGN-SYSTEM.md §5's metric-card row): a seat that cannot
 * open the staff register still sees that a staff figure exists and what ability unlocks it. Hiding
 * the card would report "nothing to see here", which is a different claim from one you cannot check.
 */
new #[Title('Dashboard')] class extends Component {
    /**
     * Where to work. Deliberately excludes the four registers the figures above already link to.
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

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function cards(): array
    {
        return [
            Gate::allows('alerts.view') ? $this->alertCard() : $this->lockedCard(__('Critical alerts'), 'alerts.view'),
            Gate::allows('documents.view') ? $this->documentCard() : $this->lockedCard(__('Papers expiring'), 'documents.view'),
            Gate::allows('users.view') ? $this->invitationCard() : $this->lockedCard(__('Invitations awaiting'), 'users.view'),
            Gate::allows('queues.view') ? $this->queueCard() : $this->lockedCard(__('Background jobs'), 'queues.view'),
        ];
    }

    /** @return array<string, mixed> */
    private function alertCard(): array
    {
        $counts = $this->alerts;

        return [
            'label' => __('Critical alerts'),
            'figure' => $counts['critical'],
            'tone' => $counts['critical'] > 0 ? 'danger' : 'neutral',
            'caption' => __(':open open · :muted muted on the register.', [
                'open' => $counts['open'],
                'muted' => $counts['muted'],
            ]),
            'zero_caption' => __('Nothing critical. The register still keeps what was closed.'),
            'href' => route('alerts.index'),
        ];
    }

    /** @return array<string, mixed> */
    private function documentCard(): array
    {
        $expiry = $this->expiry;

        return [
            'label' => __('Papers expiring'),
            'figure' => $expiry['inside'],
            'tone' => $expiry['lapsed'] > 0 ? 'danger' : ($expiry['inside'] > 0 ? 'warning' : 'neutral'),
            'caption' => __(':lapsed already lapsed · window :days days.', [
                'lapsed' => $expiry['lapsed'],
                'days' => $this->warningDays(),
            ]),
            'zero_caption' => __('No current paper lapses inside the warning window.'),
            'href' => route('documents.index'),
        ];
    }

    /** @return array<string, mixed> */
    private function invitationCard(): array
    {
        $invitations = $this->invitations;

        return [
            'label' => __('Invitations awaiting'),
            'figure' => $invitations['pending'],
            'tone' => $invitations['expiring'] > 0 ? 'warning' : 'neutral',
            'caption' => __(':expiring expire within 7 days.', ['expiring' => $invitations['expiring']]),
            'zero_caption' => __('Everyone invited has signed in. Public registration stays off.'),
            'href' => route('security.users'),
        ];
    }

    /** @return array<string, mixed> */
    private function queueCard(): array
    {
        $queue = $this->queue;

        return [
            'label' => __('Background jobs'),
            'figure' => $queue['failed'],
            'tone' => $queue['failed'] > 0 ? 'danger' : 'neutral',
            'caption' => $queue['unavailable']
                ? __('The queue backend did not answer, so the backlog is unknown.')
                : __(':waiting waiting · :delayed delayed.', ['waiting' => $queue['waiting'], 'delayed' => $queue['delayed']]),
            'zero_caption' => __('Nothing failed. Reminders and deliveries go out on schedule.'),
            'href' => route('security.queues'),
        ];
    }

    /** @return array<string, mixed> */
    private function lockedCard(string $label, string $ability): array
    {
        return ['label' => $label, 'gated' => true, 'gate' => $ability];
    }

    /**
     * Fleet and people, counted from the rows their registers own.
     *
     * The two dispatch questions — "is a truck free" and "may this driver drive" — are answered by
     * the asset's own status and the driver's own {@see Driver::eligibleOn()}, not by a second rule
     * written here. Four fixed slots, and the mask names the ability rather than the card vanishing.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function operationsCards(): array
    {
        return [
            Gate::allows('fleet.view') ? $this->fleetCard() : $this->lockedCard(__('Trucks on the road'), 'fleet.view'),
            Gate::allows('fleet.view') ? $this->workshopCard() : $this->lockedCard(__('Off the road'), 'fleet.view'),
            Gate::allows('people.view') ? $this->clearedDriversCard() : $this->lockedCard(__('Drivers cleared'), 'people.view'),
            Gate::allows('people.view') ? $this->driverPapersCard() : $this->lockedCard(__('Driver papers lapsing'), 'people.view'),
        ];
    }

    /** @return array<string, mixed> */
    private function fleetCard(): array
    {
        $fleet = $this->fleet;

        return [
            'label' => __('Trucks on the road'),
            'figure' => $fleet['onTrip'],
            'tone' => 'neutral',
            'caption' => __(':total trucks on the books; sold ones are not counted.', ['total' => $fleet['total']]),
            'zero_caption' => __('Every truck is at the yard. Nothing is moving right now.'),
            'href' => route('fleet.index', ['status' => FleetStatus::OnTrip->value]),
        ];
    }

    /** @return array<string, mixed> */
    private function workshopCard(): array
    {
        $workshop = $this->workshop;

        return [
            'label' => __('Off the road'),
            'figure' => $workshop['inWorkshop'] + $workshop['outOfService'],
            'tone' => $workshop['outOfService'] > 0 ? 'warning' : 'neutral',
            'caption' => __(':workshop in the workshop · :service out of service.', [
                'workshop' => $workshop['inWorkshop'],
                'service' => $workshop['outOfService'],
            ]),
            'zero_caption' => __('No truck is in the workshop or out of service.'),
            'href' => route('fleet.index', ['status' => FleetStatus::OutOfService->value]),
        ];
    }

    /** @return array<string, mixed> */
    private function clearedDriversCard(): array
    {
        $drivers = $this->drivers;

        return [
            'label' => __('Drivers cleared'),
            'figure' => $drivers['cleared'],
            'tone' => $drivers['cleared'] < $drivers['onBooks'] ? 'warning' : 'neutral',
            'caption' => __(':books on the books, standby included.', ['books' => $drivers['onBooks']]),
            'zero_caption' => $drivers['onBooks'] === 0
                ? __('No driver is on the books yet.')
                : __('No driver is cleared today — every licence or permit has lapsed.'),
            'href' => route('people.index', ['tab' => 'drivers']),
        ];
    }

    /** @return array<string, mixed> */
    private function driverPapersCard(): array
    {
        $papers = $this->driverPapers;

        return [
            'label' => __('Driver papers lapsing'),
            'figure' => $papers['inside'],
            'tone' => $papers['lapsed'] > 0 ? 'danger' : ($papers['inside'] > 0 ? 'warning' : 'neutral'),
            'caption' => __(':lapsed already lapsed · window :days days.', [
                'lapsed' => $papers['lapsed'],
                'days' => $this->warningDays(),
            ]),
            'zero_caption' => __('Every driver on the books holds a current licence and permit.'),
            'href' => route('people.index', ['tab' => 'drivers']),
        ];
    }

    /**
     * Band 1c — the four figures the Operations phase puts on the overview (§15.2's cross-phase rule:
     * every phase ships dashboard cards, and every card counts a row the module already owns).
     *
     * The order is the order of work, not of size: a job nobody has accepted can be dispatched as many
     * times as it likes and mean nothing, so the decision sits first, then the trip that is planned but
     * not committed, then the trucks actually moving, then the proof that has not come back.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function dispatchCards(): array
    {
        return [
            Gate::allows('jobs.view') ? $this->jobDecisionCard() : $this->lockedCard(__('Jobs waiting on a decision'), 'jobs.view'),
            Gate::allows('trips.view') ? $this->awaitingDispatchCard() : $this->lockedCard(__('Planned, not dispatched'), 'trips.view'),
            Gate::allows('trips.view') ? $this->onTheRoadCard() : $this->lockedCard(__('On the road'), 'trips.view'),
            Gate::allows('trips.view') ? $this->proofPendingCard() : $this->lockedCard(__('Proof of delivery outstanding'), 'trips.view'),
        ];
    }

    /** @return array<string, mixed> */
    private function jobDecisionCard(): array
    {
        $open = Job::query()->whereIn('status', [JobStatus::Enquiry->value, JobStatus::Quoted->value])->count();
        $quoted = Job::query()->where('status', JobStatus::Quoted->value)->count();

        return [
            'label' => __('Jobs waiting on a decision'),
            'figure' => $open,
            'tone' => $quoted > 0 ? 'warning' : 'neutral',
            'caption' => __(':quoted quoted and unanswered · :enquiries still unpriced.', [
                'quoted' => $quoted,
                'enquiries' => $open - $quoted,
            ]),
            'zero_caption' => __('Nothing is waiting: every job is accepted, declined or closed.'),
            'href' => route('operations.jobs', ['status' => JobStatus::Quoted->value]),
        ];
    }

    /** @return array<string, mixed> */
    private function awaitingDispatchCard(): array
    {
        $planned = Trip::query()->where('status', TripStatus::Planned->value)->count();
        $held = Trip::query()->where('status', TripStatus::OnHold->value)->count();

        return [
            'label' => __('Planned, not dispatched'),
            'figure' => $planned,
            'tone' => $held > 0 ? 'danger' : ($planned > 0 ? 'warning' : 'neutral'),
            'caption' => __(':held on hold — a truck the board cannot commit.', ['held' => $held]),
            'zero_caption' => $held > 0
                ? __('Nothing is waiting to be dispatched, but :held trips are held off the road.', ['held' => $held])
                : __('Every planned trip has its vehicle and crew committed.'),
            'href' => route('operations.dispatch'),
        ];
    }

    /** @return array<string, mixed> */
    private function onTheRoadCard(): array
    {
        $statuses = [
            TripStatus::Departed->value,
            TripStatus::InTransit->value,
            TripStatus::AtBorder->value,
            TripStatus::Arrived->value,
        ];

        $road = Trip::query()->whereIn('status', $statuses)->count();
        $border = Trip::query()->where('status', TripStatus::AtBorder->value)->count();

        return [
            'label' => __('On the road'),
            'figure' => $road,
            'tone' => $border > 0 ? 'warning' : 'neutral',
            'caption' => __(':border at a border post right now.', ['border' => $border]),
            'zero_caption' => __('No truck has left the yard today.'),
            'href' => route('operations.dispatch'),
        ];
    }

    /** @return array<string, mixed> */
    private function proofPendingCard(): array
    {
        $pending = Trip::query()->where('status', TripStatus::PodPending->value)->count();
        $deliveredToday = Trip::query()
            ->where('status', TripStatus::Delivered->value)
            ->whereDate('arrived_at', $this->businessToday()->toDateString())
            ->count();

        return [
            'label' => __('Proof of delivery outstanding'),
            'figure' => $pending,
            'tone' => $pending > 0 ? 'danger' : 'neutral',
            'caption' => __(':delivered arrived and signed for today.', ['delivered' => $deliveredToday]),
            'zero_caption' => __('Every arrived trip has its proof back.'),
            'href' => route('operations.dispatch'),
        ];
    }

    /**
     * Band 1d — the money Operations hands to billing and accounting (§20.17).
     *
     * The two figures are the two ends of the same handover: what a customer owes us and is not yet
     * on an invoice, and what the trip actually cost us and nobody has posted. One is a revenue
     * question (`invoices.view`), the other a cost one (`expenses.create`); a seat holding neither
     * still sees the slot with the ability named, per the mask-not-delete rule.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function costCards(): array
    {
        return [
            Gate::allows('invoices.view') ? $this->chargeableCard() : $this->lockedCard(__('Chargeables ready to bill'), 'invoices.view'),
            Gate::allows('expenses.create') ? $this->unpostedCostCard() : $this->lockedCard(__('Trips with no cost posted'), 'expenses.create'),
        ];
    }

    /** @return array<string, mixed> */
    private function chargeableCard(): array
    {
        $ready = Chargeable::query()->where('status', ChargeableStatus::Ready->value);
        $count = $ready->count();
        $trips = (clone $ready)->distinct()->count('trip_id');

        return [
            'label' => __('Chargeables ready to bill'),
            'figure' => $count,
            'tone' => $count > 0 ? 'success' : 'neutral',
            'caption' => __('Across :trips delivered trip(s). Invoicing raises the invoice in Phase 4.', ['trips' => $trips]),
            'zero_caption' => __('Nothing has been earned and not billed. Accepting a delivery proof puts it here.'),
            'href' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function unpostedCostCard(): array
    {
        $statuses = [
            TripStatus::Arrived->value,
            TripStatus::PodPending->value,
            TripStatus::Delivered->value,
            TripStatus::Completed->value,
        ];

        $unposted = Trip::query()
            ->whereIn('status', $statuses)
            ->whereNull('ledger_posted_at')
            ->count();

        return [
            'label' => __('Trips with no cost posted'),
            'figure' => $unposted,
            'tone' => $unposted > 0 ? 'warning' : 'neutral',
            'caption' => __('Arrived or finished, and the ledger has no direct cost for them yet.'),
            'zero_caption' => __('Every trip that has arrived carries its costs.'),
            'href' => route('operations.dispatch'),
        ];
    }

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
     * The five rows that outrank everything else, on the register's own ordering: severity first,
     * then how long the condition has been standing — a critical alert nobody has moved in eleven
     * days is not behind one raised two minutes ago.
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
     * Current paper whose expiry is inside the warning window, oldest first, with what this seat may
     * not read already removed — §14.4's visibility narrowing applies to a title on this screen just
     * as it does to a row on the register.
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
     * Drivers the company could put on a trip today, asked of the driver row rather than of a
     * second rule: `eligibleOn()` is what dispatch will ask on the day.
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
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Dashboard') }}</x-ui.heading>
        <x-ui.text>
            {{ __(':company · figures counted at :stamp (:zone)', [
                'company' => CurrentCompany::model()->name,
                'stamp' => $this->stamp,
                'zone' => CurrentCompany::timezone(),
            ]) }}
        </x-ui.text>
    </header>

    {{-- Band 1 — the figures a seat can act on. Four fixed slots, so the layout does not reflow
         when a permission is missing; the masked card says so instead of showing a zero. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->cards as $card)
            <x-ui.metric-card
                :label="$card['label']"
                :figure="$card['figure'] ?? null"
                :caption="$card['caption'] ?? null"
                :zero-caption="$card['zero_caption'] ?? null"
                :tone="$card['tone'] ?? 'neutral'"
                :href="$card['href'] ?? null"
                :gated="$card['gated'] ?? false"
                :gate="$card['gate'] ?? null"
            />
        @endforeach
    </div>

    {{-- Band 1b — fleet and people. Same four fixed slots: the figures a dispatcher acts on before
         the registers behind them. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->operationsCards as $card)
            <x-ui.metric-card
                :label="$card['label']"
                :figure="$card['figure'] ?? null"
                :caption="$card['caption'] ?? null"
                :zero-caption="$card['zero_caption'] ?? null"
                :tone="$card['tone'] ?? 'neutral'"
                :href="$card['href'] ?? null"
                :gated="$card['gated'] ?? false"
                :gate="$card['gate'] ?? null"
            />
        @endforeach
    </div>

    {{-- Band 1c — the work Operations owns: accepted work, committed work, moving work and the proof
         that has not come back. Same four fixed slots and the same mask-not-delete rule. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->dispatchCards as $card)
            <x-ui.metric-card
                :label="$card['label']"
                :figure="$card['figure'] ?? null"
                :caption="$card['caption'] ?? null"
                :zero-caption="$card['zero_caption'] ?? null"
                :tone="$card['tone'] ?? 'neutral'"
                :href="$card['href'] ?? null"
                :gated="$card['gated'] ?? false"
                :gate="$card['gate'] ?? null"
            />
        @endforeach
    </div>

    {{-- Band 1d — the handover to billing and accounting (§20.17). Two figures, two abilities, and
         the same mask-not-delete rule as every other band. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->costCards as $card)
            <x-ui.metric-card
                :label="$card['label']"
                :figure="$card['figure'] ?? null"
                :caption="$card['caption'] ?? null"
                :zero-caption="$card['zero_caption'] ?? null"
                :tone="$card['tone'] ?? 'neutral'"
                :href="$card['href'] ?? null"
                :gated="$card['gated'] ?? false"
                :gate="$card['gate'] ?? null"
            />
        @endforeach
    </div>

    {{-- Band 2 — the rows behind the figures, capped at five each. The register is the screen that
         works through them; this is the screen that says which ones are next. --}}
    <div class="grid gap-6 xl:grid-cols-2">
        @if (Gate::allows('alerts.view'))
            <x-ui.card as="section" :title="__('Needs attention')">
                <x-slot:header>
                    <x-ui.link :href="route('alerts.index')">{{ __('Open register · :count live', ['count' => $this->alerts['live']]) }}</x-ui.link>
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
                            <li class="flex items-start gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
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
        @endif

        @if (Gate::allows('documents.view'))
            <x-ui.card as="section" :title="__(':days-day expiry board', ['days' => $this->warningDays()])">
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

                            <li class="rounded-fg-sm hover:bg-fg-surface-sunk">
                                <a
                                    href="{{ route('documents.index', ['search' => $document->title]) }}"
                                    wire:navigate
                                    class="flex items-center gap-3 px-2 py-2"
                                >
                                    {{-- The column is days-to-expiry, not a date: the decision the row
                                         supports is "how long do I have", and §11 puts the ISO date in
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
    </div>

    {{-- Band 3 — navigation, subordinate to the work above it on purpose. --}}
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

    {{-- Band 4 — the roadmap, collapsed: it is context for the empty bands above, not work. --}}
    <details class="group rounded-fg-md border border-fg-line bg-fg-surface p-5">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-base font-semibold text-fg-ink [&::-webkit-details-marker]:hidden">
            {{ __('What arrives in later phases') }}

            <x-ui.icon name="chevron-down" size="4" class="text-fg-ink-muted transition duration-(--t-base) ease-(--ease-standard) group-open:rotate-180" />
        </summary>

        <p class="pt-3 text-sm text-fg-ink-secondary">
            {{ __('The figures above count real rows. The modules that fill the rest of this screen land in the order below (DESIGN.md §15).') }}
        </p>

        <ul class="mt-3 grid gap-2 text-sm text-fg-ink-secondary sm:grid-cols-2">
            @foreach ([
                __('The rest of Operations: kiosk POD, trip costs and margin, crossings, GPS') => 2,
                __('Fuel and maintenance') => 3,
                __('Invoicing and customer payments') => 4,
                __('Accounting, ledger, loans and cash flow') => 5,
                __('Cross-border SADC operations') => 6,
                __('GPS tracking and route planning') => 7,
                __('Reports and the executive dashboard') => 8,
            ] as $label => $phase)
                <li class="flex items-center gap-3 border-b border-fg-line pb-2 last:border-0">
                    <x-ui.badge tone="neutral" icon="clock">Phase {{ $phase }}</x-ui.badge>
                    <span>{{ $label }}</span>
                </li>
            @endforeach
        </ul>
    </details>
</div>
