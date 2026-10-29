<?php

declare(strict_types=1);

use App\Domain\Fleet\Queries\FleetDirectory;
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
 * The dispatch board: a week of days across, the fleet down (§3.2, §15.2 Phase 2).
 *
 * Two duties make this screen rather than a table of trips. It shows a **truck that is not free** —
 * in the workshop, on another trip — because the dispatcher's question is "what can I still commit",
 * and a picker that lists only the available vehicles hides the reason a plan will not build. And it
 * carries §10.2's **validity findings** on the planned rows: the licence that lapses before the truck
 * leaves, the expired insurance certificate, the driver already on another vehicle. That is the
 * "conflict + validity checks" line of the phase, surfaced where the decision is made instead of at
 * the gate.
 *
 * Assignment is click-to-assign, not drag-and-drop. §3.2 sketches a drag grid; the deviation is
 * deliberate and recorded in §20.14: a cell opens a dialog that names the driver, lists what is wrong
 * and refuses with the reason, which is what a yard phone at 06:00 needs — a gesture that drops a
 * truck into a day it cannot serve teaches the operator to distrust the board.
 *
 * No money appears anywhere on this screen. §9.3 hides margins and rates from the dispatcher, who is
 * the seat that reads a dispatch board most often, so a cell shows the job reference and never what
 * the job sells for.
 */
new #[Title('Dispatch')] class extends Component {
    /** The Monday the week starts on, in the company's day (§2), kept in the URL so a link outlives a refresh. */
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

    public function mount(): void
    {
        if ($this->start === '') {
            $this->start = $this->today()->toDateString();
        }
    }

    public function updated(string $property): void
    {
        // Moving the week or switching trips invalidates whatever was typed into a dialog for the
        // previous selection; leaving it set would offer to dispatch a trip that has scrolled away.
        if ($property === 'start') {
            $this->reset('tripId', 'planTruckId', 'planDate', 'planJobId', 'planDriverId', 'planTrailerId', 'holdReason');
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

        // §10.2's hold carries the reason; releasing it is the same edge run backwards, and demanding a
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
        ]);

        if ((int) $this->planTruckId <= 0 || $this->planDate === '') {
            $this->addError('plan', __('The cell lost its truck or day. Reopen it and try again.'));

            return;
        }

        try {
            /** @var Job|null $job */
            $job = Job::query()->find($this->planJobId);

            if ($job === null) {
                throw new InvalidArgumentException('No such job is open in this company.');
            }

            $trip = $create($job, (int) auth()->id());

            $plan($trip, new TripDto(
                jobId: $job->id,
                truckId: (int) $this->planTruckId,
                driverId: (int) $this->planDriverId,
                plannedDepartureAt: $this->planDate.'T06:00:00',
                trailerId: $this->planTrailerId === '' ? null : (int) $this->planTrailerId,
            ), (int) auth()->id());
        } catch (Throwable $refused) {
            $this->addError('plan', $refused->getMessage());

            return;
        }

        $this->closeDialogs();
        unset($this->trips, $this->findings, $this->jobChoices);
        $this->dispatch('toast', message: __(':trip planned on :day.', ['trip' => $trip->trip_no, 'day' => $this->planDate]), kind: 'success');
    }

    public function closeDialogs(): void
    {
        $this->reset('tripId', 'planTruckId', 'planDate', 'planJobId', 'planDriverId', 'planTrailerId', 'holdReason');

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
     * §10.2's findings, computed once for the week rather than per cell.
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

        $found = [];

        foreach (Trip::query()
            ->where('status', TripStatus::Planned->value)
            ->whereBetween('planned_departure_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get() as $trip
        ) {
            $found[$trip->id] = $gate->findings($trip);
        }

        return $found;
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
     * The drivers offered for a cell, each marked with whether §10.2 would let them leave that day.
     *
     * An ineligible driver is listed and labelled rather than hidden: a dispatcher who cannot find
     * "Thabo" assumes the board is broken, while a dispatcher who sees *why* phones the licensing
     * office. The select does not block the choice — {@see \App\Domain\Operations\Actions\DispatchTrip}
     * refuses at the commitment, which is where §10.2 puts the test.
     *
     * @return list<array{id: int, label: string, eligible: bool, why: string}>
     */
    #[Computed]
    public function driverChoices(): array
    {
        $on = $this->date($this->planDate === '' ? $this->today()->toDateString() : $this->planDate)->startOfDay();

        return array_map(
            static function (DriverOption $driver) use ($on): array {
                $why = [];

                if (! $driver->status->mayBeDispatched()) {
                    $why[] = __('status: :status', ['status' => $driver->status->label()]);
                }

                if (! $driver->licenceValidOn($on)) {
                    $why[] = $driver->licenceExpiresOn === null
                        ? __('no licence on file')
                        : __('licence ends :date', ['date' => $driver->licenceExpiresOn->toDateString()]);
                }

                if (! $driver->permitValidOn($on)) {
                    $why[] = $driver->permitExpiresOn === null
                        ? __('no PDP permit on file')
                        : __('permit ends :date', ['date' => $driver->permitExpiresOn->toDateString()]);
                }

                return [
                    'id' => $driver->id,
                    'label' => $driver->name.' · '.$driver->employeeNo,
                    'eligible' => $driver->eligibleOn($on),
                    'why' => $why === [] ? '' : implode(', ', $why),
                ];
            },
            app(DriverDirectory::class)->options(),
        );
    }

    /**
     * Jobs a trip can be planned against — §4.1: only accepted or running work executes.
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
     * What the one hold button does, in the operator's words.
     *
     * §10.2 gives the edge both directions, so the board offers one control and names it from the trip's
     * own state — a held trip whose button still says "Put on hold" is how a dispatcher clicks twice and
     * gets a release they did not ask for.
     */
    public function tripHoldLabel(): string
    {
        return $this->selectedTrip()?->status === TripStatus::OnHold
            ? __('Release the hold')
            : __('Put on hold');
    }

    /** The cell's truck by the name the register uses, so the dialog confirms the row that was clicked. */
    public function planTruckLabel(): string
    {
        return $this->trucks()->firstWhere('id', (int) $this->planTruckId)?->label ?? '#'.$this->planTruckId;
    }

    /**
     * When the trip is due to leave, in the company's clock (§2) — the same day the gate tests papers against.
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
        return $this->trips()[$truckId][$date] ?? [];
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
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Dispatch') }}</x-ui.heading>
        <x-ui.text>
            {{ __('A week across, the fleet down. A cell says what is committed and whether it may leave; an empty cell on a free truck is where a trip is planned.') }}
        </x-ui.text>
    </header>

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
                                            class="w-full rounded-fg-sm border border-dashed border-fg-line-strong px-2 py-3 text-xs text-fg-ink-muted hover:border-accent hover:text-accent"
                                        >
                                            + {{ __('Plan a trip') }}
                                        </button>
                                    @else
                                        <span class="block px-2 py-3 text-xs text-fg-ink-muted">—</span>
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
                                title="{{ __('No trucks') }}"
                                description="{{ __('Nothing is on the fleet register. Add a truck before the board can hold a day.') }}"
                            />
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

    <x-ui.dialog name="dispatch-trip" :title="$trip === null ? '' : $trip->trip_no">
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
                    </x-ui.alert>
                @endif

                @error('trip')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex flex-wrap items-center justify-between gap-3">
                    @can('trips.dispatch')
                        <x-ui.button type="submit" :disabled="$issues !== []">
                            {{ __('Dispatch this trip') }}
                        </x-ui.button>
                    @else
                        <span class="text-xs text-fg-ink-muted">{{ __('You may read the board but not dispatch from it (§9.3).') }}</span>
                    @endcan

                    {{-- The board commits; the trip is worked on its own page, where the schedule and the trail are. --}}
                    <x-ui.link :href="route('operations.trip', $trip)" wire:navigate>{{ __('Open the trip') }}</x-ui.link>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-dispatch-trip')">{{ __('Close') }}</x-ui.button>
                </div>
            </form>

            @if ($this->tripIsHoldable())
                @can('trips.dispatch')
                    <form wire:submit="holdTrip" class="grid gap-3 border-t border-fg-line p-5">
                        <x-ui.input
                            name="holdReason"
                            wire:model="holdReason"
                            :label="__('Reason for the hold (§10.2: a blocker, not a cancellation)')"
                        />

                        <div>
                            <x-ui.button type="submit" variant="outline" size="sm">{{ $this->tripHoldLabel() }}</x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        @endif
    </x-ui.dialog>

    {{-- The empty-cell dialog: pick the job, the driver, optionally the trailer. --}}
    <x-ui.dialog name="dispatch-plan" :title="__('Plan a trip')">
        <form wire:submit="planTrip" class="grid gap-4 p-5">
            <p class="text-sm text-fg-ink-secondary">
                {{ __('Truck :truck · :day, leaving 06:00', [
                    'truck' => $this->planTruckLabel(),
                    'day' => $this->planDate,
                ]) }}
            </p>

            <div class="grid gap-1">
                <label for="plan-job" class="text-sm text-fg-ink">{{ __('Job') }}</label>
                <select
                    id="plan-job"
                    wire:model="planJobId"
                    class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                >
                    <option value="">{{ __('Choose an accepted job…') }}</option>
                    @foreach ($this->jobChoices as $job)
                        <option value="{{ $job['id'] }}">{{ $job['label'] }}</option>
                    @endforeach
                </select>
                @error('planJobId') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>

            <div class="grid gap-1">
                <label for="plan-driver" class="text-sm text-fg-ink">{{ __('Driver') }}</label>
                <select
                    id="plan-driver"
                    wire:model="planDriverId"
                    class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                >
                    <option value="">{{ __('Choose a driver…') }}</option>
                    @foreach ($this->driverChoices as $driver)
                        <option value="{{ $driver['id'] }}">
                            {{ $driver['label'] }}{{ $driver['eligible'] ? '' : ' — '.$driver['why'] }}
                        </option>
                    @endforeach
                </select>
                @error('planDriverId') <span class="text-xs text-danger">{{ $message }}</span> @enderror
                <p class="text-xs text-fg-ink-muted">
                    {{ __('A driver whose papers do not cover the day is still listed — dispatch refuses at the commitment and says why (§10.2).') }}
                </p>
            </div>

            @if ($this->trailers->isNotEmpty())
                <div class="grid gap-1">
                    <label for="plan-trailer" class="text-sm text-fg-ink">{{ __('Trailer (optional)') }}</label>
                    <select
                        id="plan-trailer"
                        wire:model="planTrailerId"
                        class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                    >
                        <option value="">{{ __('None — truck only') }}</option>
                        @foreach ($this->trailers as $trailer)
                            <option value="{{ $trailer->id }}">{{ $trailer->label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @error('plan')
                <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <x-ui.button type="submit">{{ __('Plan the trip') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-dispatch-plan')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
