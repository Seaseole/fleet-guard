<?php

declare(strict_types=1);

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\HoldTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Queries\DriverDirectory;
use App\Domain\People\Data\DriverOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The dispatch board: a week of days across, the fleet down.
 *
 * Two duties make this screen rather than a table of trips. It shows a **truck that is not free** —
 * in the workshop, on another trip — because the dispatcher's question is "what can I still commit",
 * and a picker that lists only the available vehicles hides the reason a plan will not build. And it
 * carries the **validity findings** on the planned rows: the licence that lapses before the truck
 * leaves, the expired insurance certificate, the driver already on another vehicle. That is the
 * "conflict + validity checks" line of the phase, surfaced where the decision is made instead of at
 * the gate.
 *
 * Assignment is click-to-assign, not drag-and-drop. Sketches a drag grid; the deviation is
 * deliberate and recorded: a cell opens a dialog that names the driver, lists what is wrong
 * and refuses with the reason, which is what a yard phone at 06:00 needs — a gesture that drops a
 * truck into a day it cannot serve teaches the operator to distrust the board.
 *
 * No money appears anywhere on this screen. Hides margins and rates from the dispatcher, who is
 * the seat that reads a dispatch board most often, so a cell shows the job reference and never what
 * the job sells for.
 */
new #[Title('Dispatch')] class extends Component {
    /** The Monday the week starts on, in the company's day, kept in the URL so a link outlives a refresh. */
    #[Url('week')]
    public string $start = '';

    /** The planned trip whose dialog is open. */
    public string $tripId = '';

    /** An empty cell being planned into: the truck, the day, and the choices being made. */
    public string $planTruckId = '';

    public string $planDate = '';

    public string $planJobId = '';

    public string $planDriverId = '';

    public string $planTrailerId = '';

    public string $holdReason = '';

    /**
     * The job a register row handed over, and the day it asked for — both in the URL, so the link survives
     * a refresh, a second tab, and the trip back from the board.
     *
     * This is the accepted job's way into a trip. Before it, the register said "Accepted" and stopped: the
     * dispatcher had to find the board, remember the reference, and re-pick the job from a dropdown of
     * every accepted job in the company. `plan_job` carries the one fact the register already knew.
     */
    #[Url('plan_job')]
    public string $planJob = '';

    #[Url('on')]
    public string $planOn = '';

    /** The hour the trip leaves on its day. It used to be a constant written after the fact. */
    public string $planDepartureTime = '06:00';

    public function mount(): void
    {
        if ($this->start === '') {
            $this->start = $this->today()->toDateString();
        }

        // Arriving from a job row: open the plan dialog with that job already chosen, on the day the job
        // asked for, with the board already showing that week. The truck is the dispatcher's to pick, and
        // it is picked here rather than in a cell so the row's intent is not lost on the way.
        if ($this->planJob !== '') {
            /** @var Job|null $job */
            $job = Job::query()->find($this->planJob);

            if ($job !== null) {
                $day = $this->planOn !== ''
                    ? $this->planOn
                    : ($job->required_load_on?->timezone(CurrentCompany::timezone())->toDateString() ?? $this->today()->toDateString());

                $this->planJobId = $job->id;
                $this->planDate = $day;
                $this->start = $day;
                $this->resetErrorBag();
                $this->dispatch('open-dispatch-plan');
            } else {
                $this->planJob = '';
            }
        }
    }

    public function updated(string $property): void
    {
        // Moving the week invalidates the cell the dialog was opened from: leaving it set would offer to
        // plan into a truck and day that are no longer on screen. The job and the driver are not cell
        // facts — they survive the week they were typed in, because they describe the freight, not the grid.
        if ($property === 'start') {
            $this->reset('tripId', 'planTruckId', 'planDate', 'holdReason');
        }
    }

    public function openTrip(string $tripId): void
    {
        $this->reset('planTruckId', 'planDate', 'planJobId', 'planDriverId', 'planTrailerId');
        $this->tripId = $tripId;
        $this->resetErrorBag();
        $this->dispatch('open-dispatch-trip');
    }

    public function openPlan(string $truckId, string $date): void
    {
        $this->reset('tripId', 'planJobId', 'planDriverId', 'planTrailerId');
        $this->planTruckId = $truckId;
        $this->planDate = $date;
        $this->resetErrorBag();
        $this->dispatch('open-dispatch-plan');
    }

    /**
     * Closing the plan dialog wipes the choices, not the job the register handed over.
     *
     * The dialog is opened against whichever cell was clicked, so a half-typed plan left on the server was
     * one reopen away from being committed against a different truck. `planJob` is exempt: it is the URL,
     * and Escape is not a way to lose the job a dispatcher was told to move.
     */
    public function clearPlanForm(): void
    {
        $this->reset('planTruckId', 'planDate', 'planJobId', 'planDriverId', 'planTrailerId');
        $this->planDepartureTime = '06:00';
        $this->resetValidation();
    }

    public function clearTripDialog(): void
    {
        $this->reset('tripId', 'holdReason');
        $this->resetValidation();
    }

    public function shiftWeek(int $days): void
    {
        $this->start = $this->days()[0]->addDays($days)->toDateString();
        $this->reset('tripId', 'planTruckId', 'planDate', 'holdReason');
    }

    public function dispatchTrip(DispatchTrip $dispatch): void
    {
        $trip = $this->selectedTrip();

        if ($trip === null) {
            $this->addError('trip', __('That trip is no longer on this week. Open it again from the board.'));

            return;
        }

        try {
            $dispatch($trip, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('trip', $refused->getMessage());

            return;
        }

        $this->closeDialogs();
        unset($this->trips, $this->findings);
        $this->dispatch('toast', message: __(':trip is on the road.', ['trip' => $trip->trip_no]), kind: 'success');
    }

    public function holdTrip(HoldTrip $hold): void
    {
        $trip = $this->selectedTrip();

        if ($trip === null) {
            $this->addError('trip', __('That trip is no longer on this week. Open it again from the board.'));

            return;
        }

        // Captured before the Action runs: `HoldTrip` moves the very instance passed in, so reading the
        // status afterwards reports where the trip *is*, not what the operator just did to it.
        $wasHeld = $trip->status === TripStatus::OnHold;

        // The hold carries the reason; releasing it is the same edge run backwards, and demanding a
        // fresh excuse to undo a hold would be the screen asking for a fact it already stored.
        if (! $wasHeld) {
            $this->validate(['holdReason' => ['required', 'string', 'max:255']]);
        }

        try {
            $hold($trip, $this->holdReason === '' ? null : $this->holdReason, (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('trip', $refused->getMessage());

            return;
        }

        $this->closeDialogs();
        unset($this->trips, $this->findings);
        $this->dispatch('toast', message: $wasHeld
            ? __(':trip is planned again — the hold is off.', ['trip' => $trip->trip_no])
            : __(':trip is on hold.', ['trip' => $trip->trip_no]), kind: $wasHeld ? 'success' : 'warning');
    }

    public function planTrip(CreateTrip $create, PlanTrip $plan): void
    {
        $this->validate([
            'planJobId' => ['required', 'string'],
            'planDriverId' => ['required', 'integer'],
            'planTruckId' => ['required', 'integer', 'gt:0'],
            'planDate' => ['required', 'date'],
            'planDepartureTime' => ['required', 'date_format:H:i'],
        ]);

        try {
            /** @var Job|null $job */
            $job = Job::query()->find($this->planJobId);

            if ($job === null) {
                $this->addError('plan', __('That job is not in this company, or it has moved on. Reload the register and pick it again.'));

                return;
            }

            // The truck and the driver are named before the trip is opened, because the draft has no way
            // back: Lets a draft go only to `planned`, so a trip created under a refusal is a row
            // the board hides and nobody can cancel. PlanTrip asks the same two questions — checking them
            // here costs two lookups and saves the orphan rather than duplicating a state rule.
            $truck = app(FleetDirectory::class)->truck((int) $this->planTruckId);

            if ($truck === null) {
                $this->addError('plan', __('That truck is not in this company. Pick it again from the rows on the left of the board.'));

                return;
            }

            $driver = app(DriverDirectory::class)->find((int) $this->planDriverId);

            if ($driver === null) {
                $this->addError('plan', __('That driver is not in this company. Choose them again from the list.'));

                return;
            }

            $trip = $create($job, (int) auth()->id());

            $plan($trip, new TripDto(
                jobId: $job->id,
                truckId: (int) $this->planTruckId,
                driverId: (int) $this->planDriverId,
                plannedDepartureAt: $this->planDate.'T'.$this->planDepartureTime.':00',
                trailerId: $this->planTrailerId === '' ? null : (int) $this->planTrailerId,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('plan', $refused->getMessage());

            return;
        }

        $this->closeDialogs();
        unset($this->trips, $this->findings, $this->jobChoices);
        $this->dispatch('toast', message: __(':trip is planned for :day at :time — dispatch it from the board when it is ready to leave.', [
            'trip' => $trip->trip_no,
            'day' => $this->date($this->planDate)->format('d M'),
            'time' => $this->planDepartureTime,
        ]), kind: 'success');
    }

    public function closeDialogs(): void
    {
        $this->reset('tripId', 'planTruckId', 'planDate', 'planJobId', 'planDriverId', 'planTrailerId', 'holdReason');
        $this->planDepartureTime = '06:00';

        // The deep link is spent once the plan is made: leaving `?plan_job=` in the address bar would
        // reopen the dialog on the next refresh, on a trip that is already on the board.
        $this->planJob = '';
        $this->planOn = '';

        // Two separate browser events: `dispatch()` takes one event name and its *payload*, so passing
        // the second name as an argument would send a window listener nobody registered.
        $this->dispatch('close-dispatch-trip');
        $this->dispatch('close-dispatch-plan');
    }

    /**
     * The seven days shown, oldest first.
     *
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function days(): array
    {
        $start = $this->date($this->start === '' ? $this->today()->toDateString() : $this->start);

        return array_map(static fn (int $offset): CarbonImmutable => $start->addDays($offset), range(0, 6));
    }

    /** @return Collection<int, \App\Domain\Fleet\Data\FleetUnitOption> */
    #[Computed]
    public function trucks(): Collection
    {
        return app(FleetDirectory::class)->trucks();
    }

    /**
     * Trips in the window, keyed `truck id => ISO date => rows`.
     *
     * Drafts are excluded: a draft has no date and no crew, and a cell holding one is how two people
     * book the same truck — the blank space is the information.
     *
     * @return array<int, array<string, list<Trip>>>
     */
    #[Computed]
    public function trips(): array
    {
        $from = $this->days()[0]->startOfDay();
        $to = $this->days()[6]->endOfDay();

        return Trip::query()
            ->whereBetween('planned_departure_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereNotIn('status', [TripStatus::Draft->value])
            ->with('job')
            ->orderBy('planned_departure_at')
            ->get()
            ->groupBy(static fn (Trip $trip): string => (string) $trip->truck_id)
            ->map(fn ($perTruck): array => $perTruck
                ->groupBy(fn (Trip $trip): string => $trip->planned_departure_at === null
                    ? ''
                    : $trip->planned_departure_at->timezone(CurrentCompany::timezone())->toDateString())
                ->map(static fn ($perDay): array => $perDay->all())
                ->all())
            ->all();
    }

    /**
     * The findings, computed once for the week rather than per cell.
     *
     * Only `planned` rows are measured: `assigned` has already passed the gate, and anything later is
     * on the road — re-checking a trip that left would spend a query per cell to say something nobody
     * can act on from this screen any more.
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function findings(): array
    {
        $gate = app(\App\Domain\Operations\Support\DispatchGate::class);

        $from = $this->days()[0]->startOfDay();
        $to = $this->days()[6]->endOfDay();

        // One call for the week: the gate reads every truck, trailer, driver, paper and same-day
        // commit in bulk. Asking per row made the board's cost grow with the number of trips.
        return $gate->findingsFor(Trip::query()
            ->where('status', TripStatus::Planned->value)
            ->whereBetween('planned_departure_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get());
    }

    /** @return array<int, string> driver id => name, the labels the grid prints. */
    #[Computed]
    public function driverNames(): array
    {
        $options = app(DriverDirectory::class)->options();

        return array_combine(
            array_map(static fn (DriverOption $driver): int => $driver->id, $options),
            array_map(static fn (DriverOption $driver): string => $driver->name, $options),
        );
    }

    /**
     * The drivers offered for a cell, each marked with whether would let them leave that day.
     *
     * An ineligible driver is listed and labelled rather than hidden: a dispatcher who cannot find
     * "Thabo" assumes the board is broken, while a dispatcher who sees *why* phones the licensing
     * office. The select does not block the choice — {@see \App\Domain\Operations\Actions\DispatchTrip}
     * refuses at the commitment, which is where the design puts the test.
     *
     * The tone is the same two bands the people register prints, anchored on the planned day rather than
     * today: danger means the trip will be stopped at dispatch — status, a lapsed paper, or no paper at
     * all — and warning means it may leave but the paper runs out inside the window after that day.
     *
     * @return list<array{id: int, label: string, eligible: bool, why: string, tone: string, flags: list<array<string, string>>}>
     */
    #[Computed]
    public function driverChoices(): array
    {
        $on = $this->date($this->planDate === '' ? $this->today()->toDateString() : $this->planDate)->startOfDay();

        return array_values(array_map(
            function (DriverOption $driver) use ($on): array {
                $why = [];

                if (! $driver->status->mayBeDispatched()) {
                    $why[] = __('status: :status', ['status' => $driver->status->label()]);
                }

                $flags = Validity::paperFlags([
                    ['label' => __('licence'), 'date' => $driver->licenceExpiresOn],
                    ['label' => __('PRDP permit'), 'date' => $driver->permitExpiresOn],
                ], $on);

                // The reason is the same words the badge under the select prints, so the option and the
                // line beneath it cannot tell two stories about one driver.
                $note = Validity::optionNote($flags);

                if ($note !== '') {
                    $why[] = $note;
                }

                return [
                    'id' => $driver->id,
                    'label' => $driver->name.' · '.$driver->employeeNo,
                    'eligible' => $driver->eligibleOn($on),
                    'why' => $why === [] ? '' : implode(', ', $why),
                    // Danger on this list means the trip will be stopped at dispatch, whichever paper
                    // says so — including a driver whose file is simply empty.
                    'tone' => match (true) {
                        ! $driver->eligibleOn($on) => 'danger',
                        Validity::optionTone($flags) === 'warning' => 'warning',
                        default => '',
                    },
                    'flags' => $flags,
                ];
            },
            app(DriverDirectory::class)->options(),
        ));
    }

    /** The option's own class: the tone rides on the text, because a native select cannot carry a badge. */
    public function driverOptionClass(array $choice): string
    {
        return match ($choice['tone']) {
            'danger' => 'text-danger',
            'warning' => 'text-warning',
            default => '',
        };
    }

    /**
     * The papers of the driver actually chosen, for the line under the select.
     *
     * A coloured `<option>` is only visible while the list is open, and not at all on Safari; this line
     * is what a dispatcher still reads after choosing, and it re-derives when the day changes.
     *
     * @return list<array{id: int, label: string, flags: list<array<string, string>>, tone: string}>|null
     */
    #[Computed]
    public function pickedDriver(): ?array
    {
        if ($this->planDriverId === '') {
            return null;
        }

        $choice = collect($this->driverChoices)->firstWhere('id', (int) $this->planDriverId);

        return is_array($choice) ? [
            'id' => $choice['id'],
            'label' => $choice['label'],
            'flags' => $choice['flags'],
            'tone' => $choice['tone'],
        ] : null;
    }

    /**
     * Jobs a trip can be planned against — only accepted or running work executes.
     *
     * @return list<array{id: string, label: string}>
     */
    #[Computed]
    public function jobChoices(): array
    {
        return Job::query()
            ->whereIn('status', [JobStatus::Accepted->value, JobStatus::InExecution->value])
            ->with('route')
            ->orderByRaw('coalesce(required_load_on, created_at) desc')
            ->get()
            ->map(static fn (Job $job): array => [
                'id' => $job->id,
                'label' => $job->job_no.' · '.($job->route?->code ?? __('(no corridor)')).($job->commodity === null ? '' : ' · '.$job->commodity),
            ])
            ->all();
    }

    /** @return Collection<int, \App\Domain\Fleet\Data\FleetUnitOption> */
    #[Computed]
    public function trailers(): Collection
    {
        return app(FleetDirectory::class)->availableTrailers();
    }

    public function selectedTrip(): ?Trip
    {
        return $this->tripId === '' ? null : Trip::query()->find($this->tripId);
    }

    /**
     * Whether the open trip may still be put on hold.
     *
     * A method rather than an inline `TripStatus::` comparison in the template: Blade compiles the
     * markup of an SFC as its own view, where the class's `use` lines do not reach it — and naming a
     * status by string literal here is exactly the drift the enum exists to prevent.
     */
    public function tripIsHoldable(): bool
    {
        $trip = $this->selectedTrip();

        return $trip !== null && $trip->status->isPreDeparture();
    }

    /**
     * Whether the open trip is sitting on hold right now.
     *
     * The same reason as {@see tripIsHoldable()}: the template may not name a status by literal, and the
     * hold form asks this to decide whether to demand a reason at all.
     */
    public function tripIsHeld(): bool
    {
        return $this->selectedTrip()?->status === TripStatus::OnHold;
    }

    /**
     * What the one hold button does, in the operator's words.
     *
     * The design gives the edge both directions, so the board offers one control and names it from the trip's
     * own state — a held trip whose button still says "Put on hold" is how a dispatcher clicks twice and
     * gets a release they did not ask for.
     */
    public function tripHoldLabel(): string
    {
        return $this->tripIsHeld() ? __('Release the hold') : __('Put on hold');
    }

    /**
     * When the trip is due to leave, in the company's clock — the same day the gate tests papers against.
     */
    public function formatDeparture(Trip $trip): string
    {
        return $trip->planned_departure_at === null
            ? __('(not planned yet)')
            : $trip->planned_departure_at->timezone(CurrentCompany::timezone())->format('d M Y H:i');
    }

    /**
     * The cell's trips for one truck on one day.
     *
     * @return list<Trip>
     */
    public function cell(int $truckId, string $date): array
    {
        // The computed *property*, not `trips()`: a method call re-runs the week's read for every
        // cell, which is one query pair per truck per day.
        return $this->trips[$truckId][$date] ?? [];
    }

    private function date(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso, CurrentCompany::timezone());
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(CurrentCompany::timezone())->startOfDay();
    }
}; ?>

<div class="grid gap-6">
    <x-shell.nameplate
        :title="__('Dispatch')"
        :stamped="true"
        :description="__('A week across, the fleet down. A cell says what is committed and whether it may leave; an empty cell on a free truck is where a trip is planned.')"
    />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-1">
            <x-ui.button size="sm" variant="ghost" wire:click="shiftWeek(-7)" aria-label="{{ __('Previous week') }}">
                <x-ui.icon name="chevron-left" />
            </x-ui.button>

            <span class="text-sm text-fg-ink-secondary">
                {{ $this->days()[0]->format('j M') }} – {{ $this->days()[6]->format('j M Y') }}
            </span>

            <x-ui.button size="sm" variant="ghost" wire:click="shiftWeek(7)" aria-label="{{ __('Next week') }}">
                <x-ui.icon name="chevron-right" />
            </x-ui.button>
        </div>

        <x-ui.button size="sm" variant="outline" wire:click="shiftWeek(0)">{{ __('This week') }}</x-ui.button>
    </div>

    <div class="overflow-x-auto rounded-fg-lg border border-fg-line bg-fg-surface">
        <table class="w-full border-collapse text-left text-sm">
            <caption class="sr-only">{{ __('Dispatch board: trucks down the side, days across the top') }}</caption>
            <thead>
                <tr class="border-b border-fg-line bg-fg-surface-sunk">
                    <th scope="col" class="sticky left-0 z-10 min-w-44 bg-fg-surface-sunk px-3 py-2 font-medium">
                        {{ __('Truck') }}
                    </th>

                    @foreach ($this->days as $day)
                        <th scope="col" class="min-w-36 px-3 py-2 font-medium">
                            <span class="block text-fg-ink">{{ $day->format('D') }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $day->format('j M') }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($this->trucks as $truck)
                    <tr class="border-b border-fg-line last:border-0">
                        <th scope="row" class="sticky left-0 z-10 bg-fg-surface px-3 py-2 text-left font-normal">
                            <span class="block text-fg-ink">{{ $truck->label }}</span>
                            <x-ui.badge :tone="$truck->status->tone()" size="xs">{{ $truck->status->label() }}</x-ui.badge>
                        </th>

                        @foreach ($this->days as $day)
                            @php
                                $date = $day->toDateString();
                                $cellTrips = $this->cell($truck->id, $date);
                            @endphp

                            <td class="px-2 py-2 align-top">
                                @if ($cellTrips === [])
                                    @if ($truck->mayBeDispatched() && Gate::allows('trips.plan'))
                                        <button
                                            type="button"
                                            wire:click="openPlan({{ $truck->id }}, '{{ $date }}')"
                                            aria-label="{{ __('Plan a trip for :truck on :day', ['truck' => $truck->label, 'day' => $day->format('D j M')]) }}"
                                            class="w-full rounded-fg-sm border border-dashed border-fg-line-strong px-2 py-3 text-xs text-fg-ink-muted hover:border-accent hover:text-accent-ink"
                                        >
                                            + {{ __('Plan a trip') }}
                                        </button>
                                    @else
                                        {{-- The reason the cell cannot take a plan, printed instead of the dash
                                             that read as "the board is broken". --}}
                                        <span class="block px-2 py-3 text-xs text-fg-ink-muted" title="{{ $truck->status->label() }}">
                                            {{ $truck->mayBeDispatched() ? __('Planning is not on this seat') : __('— '.$truck->status->label()) }}
                                        </span>
                                    @endif
                                @else
                                    <div class="grid gap-1.5">
                                        @foreach ($cellTrips as $trip)
                                            @php
                                                $issues = $this->findings[$trip->id] ?? [];
                                            @endphp

                                            <button
                                                type="button"
                                                wire:click="openTrip('{{ $trip->id }}')"
                                                aria-label="{{ __('Open trip :trip, :status, with :driver', [
                                                    'trip' => $trip->trip_no,
                                                    'status' => $trip->status->label(),
                                                    'driver' => $this->driverNames[$trip->driver_id] ?? __('no driver named'),
                                                ]) }}"
                                                class="w-full rounded-fg-sm border border-fg-line bg-fg-surface-sunk px-2 py-1.5 text-left hover:border-accent"
                                            >
                                                <span class="flex items-center justify-between gap-2">
                                                    <span class="font-mono text-xs">{{ $trip->trip_no }}</span>
                                                    <x-ui.badge :tone="$trip->status->tone()" size="xs">{{ $trip->status->label() }}</x-ui.badge>
                                                </span>

                                                <span class="mt-0.5 block truncate text-xs text-fg-ink-secondary">
                                                    {{ $this->driverNames[$trip->driver_id] ?? __('(no driver)') }}
                                                    @if ($trip->job !== null)
                                                        · {{ $trip->job->job_no }}
                                                    @endif
                                                </span>

                                                @if ($issues !== [])
                                                    <span class="mt-1 flex items-start gap-1 text-xs text-danger">
                                                        <x-ui.icon name="exclamation-triangle" class="mt-0.5 shrink-0" />
                                                        {{ __(':count thing(s) to fix', ['count' => count($issues)]) }}
                                                    </span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-6">
                            <x-ui.empty-state
                                title="{{ __('No trucks on the board') }}"
                                description="{{ __('A week needs a fleet down the side of it. Nothing is registered yet, so there is no row to plan into.') }}"
                            >
                                @can('viewAny', App\Domain\Fleet\Models\Truck::class)
                                    <x-ui.link :href="route('fleet.index')" wire:navigate>{{ __('Open the fleet register') }}</x-ui.link>
                                @endcan
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- The trip dialog: what is wrong, and the one button that commits. --}}
    {{-- Resolved once: the dialog needs the row for its title and its body, and a second query per
         render is how a screen ends up showing one trip while acting on another. --}}
    @php $trip = $this->selectedTrip(); @endphp

    <x-ui.dialog name="dispatch-trip" :title="$trip === null ? '' : $trip->trip_no" x-on:dialog-closed="$wire.call('clearTripDialog')">
        @if ($trip !== null)
            <form wire:submit="dispatchTrip" class="grid gap-4 p-5">
                <div class="grid gap-1">
                    <x-ui.badge :tone="$trip->status->tone()">{{ $trip->status->label() }}</x-ui.badge>
                    <p class="text-sm text-fg-ink-secondary">
                        {{ __('Leaves :at with :driver.', [
                            'at' => $this->formatDeparture($trip),
                            'driver' => $this->driverNames[$trip->driver_id] ?? __('(none)'),
                        ]) }}
                    </p>
                </div>

                @if (($issues = ($this->findings[$trip->id] ?? [])) !== [])
                    <x-ui.alert tone="danger" :title="__('This trip cannot leave as planned')">
                        <ul class="grid gap-1 text-sm">
                            @foreach ($issues as $issue)
                                <li>· {{ $issue }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-fg-ink-secondary">
                            {{ __('Fix the paper or move the day, then come back to this cell. Nothing has been committed yet.') }}
                        </p>
                    </x-ui.alert>
                @endif

                @error('trip')
                    <x-ui.alert tone="danger" :title="__('Dispatch did not go through')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex flex-wrap items-center justify-between gap-3">
                    @can('trips.dispatch')
                        <x-ui.button type="submit" :disabled="$issues !== []" wire:loading.attr="disabled" wire:target="dispatchTrip">
                            <span wire:loading.remove wire:target="dispatchTrip">{{ __('Dispatch this trip') }}</span>
                            <span wire:loading wire:target="dispatchTrip">{{ __('Sending it out…') }}</span>
                        </x-ui.button>
                    @else
                        <span class="text-xs text-fg-ink-muted">{{ __('You can see the board, but this seat cannot send a truck out. Ask dispatch or the owner.') }}</span>
                    @endcan

                    {{-- The board commits; the trip is worked on its own page, where the schedule and the trail are. --}}
                    <x-ui.link :href="route('operations.trip', $trip)" wire:navigate>{{ __('Open the trip') }}</x-ui.link>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-dispatch-trip')">{{ __('Close') }}</x-ui.button>
                </div>
            </form>

            @if ($this->tripIsHoldable())
                @can('trips.dispatch')
                    {{-- The reason is asked by the hold and not by the release: the trip already carries the
                         reason it was held, and demanding a new excuse to undo a hold asks the operator for
                         a fact the screen is showing them. --}}
                    @php($holding = $this->tripIsHeld() === false)
                    <form wire:submit="holdTrip" class="grid gap-3 border-t border-fg-line p-5">
                        @if ($holding)
                            <x-ui.input
                                name="holdReason"
                                wire:model="holdReason"
                                :label="__('What is blocking it? The hold stays on the trip’s trail.')"
                            />
                        @else
                            <p class="text-xs text-fg-ink-secondary">
                                {{ __('Releasing the hold puts the trip back as planned. The reason it was held stays on its trail.') }}
                            </p>
                        @endif

                        <div>
                            <x-ui.button type="submit" variant="outline" size="sm" wire:loading.attr="disabled" wire:target="holdTrip">
                                <span wire:loading.remove wire:target="holdTrip">{{ $this->tripHoldLabel() }}</span>
                                <span wire:loading wire:target="holdTrip">{{ __('Working…') }}</span>
                            </x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        @endif
    </x-ui.dialog>

    {{-- The plan drawer. What the cell already decided sits in the sunken band at the top — the truck, the
         day, the hour — and the two things the dispatcher still has to answer, the load and the crew, get the
         full width below it. It opens on the inline end rather than over the middle of the screen, so the
         board the cell was clicked on stays readable behind it. --}}
    <x-ui.dialog name="dispatch-plan" :title="__('Plan a trip')" variant="drawer" x-on:dialog-closed="$wire.call('clearPlanForm')">
        <form id="plan-trip-form" wire:submit="planTrip" class="grid gap-5">
            {{-- Where the eye starts, so a refusal is read before the fields that caused it, not under them. --}}
            @error('plan')
                <x-ui.alert tone="danger" :title="__('This plan did not go through')">{{ $message }}</x-ui.alert>
            @enderror

            <p class="text-sm text-fg-ink-secondary">
                {{ $this->planTruckId === ''
                    ? __('Choose the truck and the day. The board books nothing until you press this.')
                    : __('Planning from the cell you opened — change any of it here.') }}
            </p>

            <div class="grid gap-3 rounded-fg-md border border-fg-line bg-fg-surface-sunk px-3 py-3">
                <x-ui.select name="planTruckId" id="plan-truck" :label="__('Truck')" wire:model="planTruckId">
                    <option value="">{{ __('Choose a truck…') }}</option>
                    @foreach ($this->trucks as $truck)
                        <option value="{{ $truck->id }}">
                            {{ $truck->label }}{{ $truck->mayBeDispatched() ? '' : ' — '.$truck->status->label() }}
                        </option>
                    @endforeach
                </x-ui.select>

                {{-- Two fields, two columns: a date and an hour are the narrowest pair on this form, and the
                     drawer is wide enough for both at full size. The band is the panel, not the viewport, so
                     the split is unconditional — which is what left Departure time alone in half a row. --}}
                <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                    <x-ui.input name="planDate" wire:model.live="planDate" :label="__('Leaves on')" type="date" />
                    <x-ui.input name="planDepartureTime" wire:model="planDepartureTime" :label="__('Departure time')" type="time" />
                </div>
            </div>

            <div class="grid gap-2">
                <x-ui.select name="planJobId" id="plan-job" :label="__('Job')" wire:model="planJobId">
                    <option value="">{{ __('Choose an accepted job…') }}</option>
                    @foreach ($this->jobChoices as $job)
                        <option value="{{ $job['id'] }}">{{ $job['label'] }}</option>
                    @endforeach
                </x-ui.select>

                @if ($this->jobChoices === [])
                    <p class="text-xs text-fg-ink-muted">
                        {{ __('Nothing is accepted and waiting, so there is nothing to plan. Jobs that are still an enquiry or a quote cannot carry a trip.') }}
                        <x-ui.link :href="route('operations.jobs').'?needs=trip'">{{ __('See the register') }}</x-ui.link>
                    </p>
                @endif
            </div>

            <div class="grid gap-2">
                <x-ui.select name="planDriverId"
                    id="plan-driver"
                    :label="__('Driver')"
                    wire:model.live="planDriverId"
                    template="fg-paper"
                    template-selection="fg-name"
                    :hint="__('Papers that miss the day are listed, and stopped at dispatch.')"
                >
                    <option value="">{{ __('Choose a driver…') }}</option>
                    @foreach ($this->driverChoices as $driver)
                        {{-- The tone rides on the option and the reason rides in the label, because a
                             coloured option is only visible while the list is open. The widget
                             reads the same three values and paints a row a native list never could. --}}
                        <option value="{{ $driver['id'] }}"
                            class="{{ $this->driverOptionClass($driver) }}"
                            data-name="{{ $driver['label'] }}"
                            data-note="{{ $driver['why'] }}"
                            data-tone="{{ $driver['tone'] }}">
                            {{ $driver['label'] }}{{ $driver['why'] === '' ? '' : ' — '.$driver['why'] }}
                        </option>
                    @endforeach
                </x-ui.select>

                {{-- Moving the day silently re-derives every driver's papers, and the list a dispatcher was
                     reading is no longer the list on the screen. Say so while the request is in flight. --}}
                <span wire:loading wire:target="planDate" class="text-xs text-fg-ink-muted">{{ __('Papers re-checked for the new day.') }}</span>

                @if (($picked = $this->pickedDriver) !== null && $picked['flags'] !== [])
                    <div class="flex flex-wrap items-center gap-1.5" role="status">
                        <span class="text-xs text-fg-ink-secondary">{{ $picked['label'] }}</span>

                        @foreach ($picked['flags'] as $flag)
                            <x-ui.badge size="xs" :tone="$flag['tone']">
                                {{ $flag['paper'] }} · {{ $flag['state'] }}@if ($flag['days'] !== '') · {{ $flag['days'] }}@endif
                            </x-ui.badge>
                        @endforeach
                    </div>
                @endif

                {{-- The paragraph this replaced told every dispatcher about a refusal that had not happened to
                     any of them. This names the consequence to the one person it now applies to. Planning is
                     still allowed, and the enabled button says so better than a sentence about permission. --}}
                @if (($picked = $this->pickedDriver) !== null && $picked['tone'] === 'danger')
                    <p class="flex items-start gap-1.5 text-xs text-danger">
                        <x-ui.icon name="exclamation-triangle" size="3" class="mt-0.5" />
                        {{ __('Dispatch will stop this trip until the paper above is fixed.') }}
                    </p>
                @endif
            </div>

            @if ($this->trailers->isNotEmpty())
                <x-ui.select name="planTrailerId" id="plan-trailer" :label="__('Trailer (optional)')" wire:model="planTrailerId">
                    <option value="">{{ __('None — truck only') }}</option>
                    @foreach ($this->trailers as $trailer)
                        <option value="{{ $trailer->id }}">{{ $trailer->label }}</option>
                    @endforeach
                </x-ui.select>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-dispatch-plan')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="plan-trip-form" wire:loading.attr="disabled" wire:target="planTrip">
                <span wire:loading.remove wire:target="planTrip">{{ __('Plan the trip') }}</span>
                <span wire:loading wire:target="planTrip">{{ __('Planning…') }}</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.dialog>
</div>
