<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\RaiseBreakdownRepair;
use App\Domain\Maintenance\Actions\RecordBreakdown;
use App\Domain\Maintenance\BreakdownCause;
use App\Domain\Maintenance\Data\BreakdownDto;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Maintenance\WorkOrderKind;
use App\Domain\Maintenance\WorkOrderLineKind;
use App\Domain\Operations\Models\Trip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Breakdowns: the trucks that stopped, where they stopped, and the job each stop needed.
 *
 * A breakdown is recorded before anything else happens to it. The row is the availability record —
 * date, place, hours lost — and the work order is raised from it afterwards, carrying the same truck
 * and the same trip. One stop, one repair: the Action refuses a second job and the register prints
 * the one that exists, because two jobs against one failure is two places for the cost to hide.
 *
 * Recording is `breakdowns.record` (Owner, Operations Manager, Workshop Manager, Dispatcher — a
 * dispatcher is who hears about it first). Raising the repair is `work_orders.create`, because the
 * job goes through the same estimate and approval threshold as any other: a breakdown is a
 * reason to need the owner's signature, not a way around it.
 */
new #[Title('Breakdowns')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('cause')]
    public string $causeFilter = '';

    /* The record dialog. */
    public string $bdTruckId = '';

    public string $bdOccurredAt = '';

    public string $bdLocation = '';

    public string $bdDescription = '';

    public string $bdCause = BreakdownCause::Mechanical->value;

    public string $bdHoursLost = '';

    public string $bdTripId = '';

    public bool $bdRecoverable = false;

    /* The raise-the-repair dialog. */
    public ?string $repairBreakdownId = null;

    public string $repairTitle = '';

    public string $repairHours = '';

    public string $repairRate = '';

    public function mount(): void
    {
        $this->bdOccurredAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'causeFilter'], true)) {
            $this->resetPage();
            unset($this->breakdowns);
        }

        if ($property === 'bdTruckId') {
            $this->bdTripId = '';
            unset($this->tripOptions);
        }

        // Recovery is only a driver-caused stop's option (the CHECK); the form follows the cause.
        if ($property === 'bdCause' && $this->bdCause !== BreakdownCause::Driver->value) {
            $this->bdRecoverable = false;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'causeFilter']);
        $this->resetPage();
        unset($this->breakdowns);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->causeFilter !== '';
    }

    public function canRecord(): bool
    {
        return Gate::allows('breakdowns.record');
    }

    public function canRepair(): bool
    {
        return Gate::allows('work_orders.create');
    }

    /* -------------------------------------------------------------- record */

    public function openRecord(): void
    {
        abort_unless($this->canRecord(), 403);

        $this->resetValidation();
        $this->reset(['bdTruckId', 'bdLocation', 'bdDescription', 'bdHoursLost', 'bdTripId', 'bdRecoverable', 'bdCause']);
        $this->bdCause = BreakdownCause::Mechanical->value;
        $this->bdOccurredAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');

        $this->dispatch('open-breakdown-record');
    }

    public function record(RecordBreakdown $record): void
    {
        abort_unless($this->canRecord(), 403);

        $validated = $this->validate([
            'bdTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'bdOccurredAt' => ['required', 'date'],
            'bdLocation' => ['required', 'string', 'max:120'],
            'bdDescription' => ['required', 'string', 'max:500'],
            'bdCause' => ['required', Rule::enum(BreakdownCause::class)],
            'bdHoursLost' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'bdTripId' => ['nullable', 'string', Rule::exists('trips', 'id')->where('company_id', CurrentCompany::id())],
        ]);

        try {
            $breakdown = $record(new BreakdownDto(
                truckId: (int) $validated['bdTruckId'],
                occurredAt: Carbon::parse($validated['bdOccurredAt'], CurrentCompany::timezone()),
                location: $validated['bdLocation'],
                description: $validated['bdDescription'],
                cause: BreakdownCause::from($validated['bdCause']),
                hoursLost: (int) ($validated['bdHoursLost'] ?? 0),
                tripId: ($validated['bdTripId'] ?? '') === '' ? null : $validated['bdTripId'],
                recoverableFromDriver: $this->bdRecoverable,
            ));
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('breakdown', $refused->getMessage());

            return;
        }

        unset($this->breakdowns);

        $this->dispatch('close-breakdown-record');
        $this->dispatch('toast', message: __('Recorded — :truck, :place.', [
            'truck' => $breakdown->truck?->label() ?? __('the truck'),
            'place' => $breakdown->location,
        ]), kind: 'success');
    }

    /* --------------------------------------------------------------- repair */

    public function openRepair(string $id): void
    {
        abort_unless($this->canRepair(), 403);

        $breakdown = Breakdown::query()->with('truck')->findOrFail($id);

        $this->resetValidation();

        if ($breakdown->hasRepair()) {
            $this->addError('repair', __('That breakdown already has its repair.'));

            return;
        }

        $this->repairBreakdownId = $breakdown->id;
        $this->repairTitle = mb_substr($breakdown->description, 0, 140);
        $this->repairHours = '';
        $this->repairRate = '';

        $this->dispatch('open-breakdown-repair');
    }

    public function raiseRepair(RaiseBreakdownRepair $raise): void
    {
        abort_unless($this->canRepair(), 403);

        $validated = $this->validate([
            'repairTitle' => ['required', 'string', 'max:140'],
            'repairHours' => ['required', 'string'],
            'repairRate' => ['required', 'string'],
        ]);

        $breakdown = $this->repairBreakdownId === null
            ? null
            : Breakdown::query()->find($this->repairBreakdownId);

        if ($breakdown === null) {
            $this->addError('repair', __('That breakdown is no longer on the register.'));

            return;
        }

        try {
            $order = $raise(
                $breakdown,
                new WorkOrderDto(
                    truckId: $breakdown->truck_id,
                    kind: WorkOrderKind::Breakdown,
                    title: $validated['repairTitle'],
                    lines: [
                        new WorkOrderLineDto(
                            kind: WorkOrderLineKind::Labour,
                            description: $validated['repairTitle'],
                            quantityX100: $this->scaledHundredths($validated['repairHours'], __('Estimated hours')),
                            unitCostMinor: $this->scaledHundredths($validated['repairRate'], __('Labour rate')),
                            currencyCode: $this->currencyCode(),
                        ),
                    ],
                    description: $breakdown->description,
                    estimatedHours: (float) $validated['repairHours'],
                    tripId: $breakdown->trip_id,
                ),
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('repairForm', $refused->getMessage());

            return;
        }

        $this->repairBreakdownId = null;
        unset($this->breakdowns);

        $this->redirectRoute('maintenance.work_order', ['workOrder' => $order->id], navigate: true);
    }

    /* ---------------------------------------------------------------- reads */

    /** @return LengthAwarePaginator<int, Breakdown> */
    #[Computed]
    public function breakdowns(): LengthAwarePaginator
    {
        return Breakdown::query()
            ->with(['truck', 'trip', 'workOrder'])
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or
                    ->where('location', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('truck', fn ($truck) => $truck
                        ->where('registration_no', 'like', $like)
                        ->orWhere('fleet_no', 'like', $like))
                    ->orWhereHas('trip', fn ($trip) => $trip->where('trip_no', 'like', $like)));
            })
            ->when($this->causeFilter !== '', fn ($query) => $query->where('cause', $this->causeFilter))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()
            ->where('active', true)
            ->orderBy('fleet_no')
            ->get(['id', 'fleet_no', 'registration_no']);
    }

    /** @return Collection<int, Trip> */
    #[Computed]
    public function tripOptions(): Collection
    {
        if ($this->bdTruckId === '') {
            return new Collection;
        }

        return Trip::query()
            ->where('truck_id', (int) $this->bdTruckId)
            ->orderByDesc('planned_departure_at')
            ->limit(50)
            ->get(['id', 'trip_no', 'truck_id', 'planned_departure_at']);
    }

    #[Computed]
    public function repairBreakdown(): ?Breakdown
    {
        return $this->repairBreakdownId === null
            ? null
            : Breakdown::query()->with('truck')->find($this->repairBreakdownId);
    }

    /** @return list<BreakdownCause> */
    public function causes(): array
    {
        return BreakdownCause::cases();
    }

    public function currencyCode(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    /** "2.5" → 250, without ever building a float. */
    private function scaledHundredths(string $input, string $label): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__(':field must be a number with at most two decimals.', ['field' => $label]));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Breakdowns') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Where a truck stopped, for how long, and what the workshop did about it. The repair is raised from the stop, on the same truck and the same trip.') }}
            </x-ui.text>
        </div>

        @if ($this->canRecord())
            <x-ui.button wire:click="openRecord">{{ __('Record a breakdown') }}</x-ui.button>
        @endif
    </header>

    @error('repair')
        <x-ui.alert tone="danger" :title="__('The repair was refused')">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card :title="__('Breakdown register')" class="min-w-0">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Truck, place, trip or what happened')" />

            <div class="grid gap-1.5">
                <label for="breakdown-cause-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Cause') }}</label>

                <x-ui.select name="causeFilter" id="breakdown-cause-filter" wire:model.live="causeFilter">
                    <option value="">{{ __('Every cause') }}</option>

                    @foreach ($this->causes() as $cause)
                        <option value="{{ $cause->value }}">{{ $cause->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Breakdowns, newest first')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Stopped') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Where and what') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Cause') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Hours lost') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="md">{{ __('Trip / repair') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->breakdowns as $breakdown)
                    <tr wire:key="breakdown-{{ $breakdown->id }}">
                        <x-ui.table.td nowrap>{{ $breakdown->occurred_at->format('d M Y H:i') }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($breakdown->truck)
                                <a href="{{ route('fleet.show', ['truck' => $breakdown->truck->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $breakdown->truck->label() }}</a>
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('Truck #:id', ['id' => $breakdown->truck_id]) }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <span class="text-fg-ink">{{ $breakdown->location }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $breakdown->description }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td hideBelow="lg">
                            <x-ui.badge>{{ $breakdown->cause->label() }}</x-ui.badge>

                            @if ($breakdown->recoverable_from_driver)
                                <x-ui.badge tone="warning">{{ __('from driver') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ $breakdown->hours_lost === 0 ? '—' : number_format($breakdown->hours_lost).' h' }}</x-ui.table.td>
                        <x-ui.table.td hideBelow="md">
                            <div class="grid gap-0.5 text-xs">
                                @if ($breakdown->trip)
                                    <a href="{{ route('operations.trip', ['trip' => $breakdown->trip->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $breakdown->trip->trip_no }}</a>
                                @endif

                                @if ($breakdown->workOrder)
                                    <a href="{{ route('maintenance.work_order', ['workOrder' => $breakdown->workOrder->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $breakdown->workOrder->wo_no }}</a>
                                @else
                                    <span class="text-fg-ink-muted">{{ __('no repair yet') }}</span>
                                @endif
                            </div>
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @if ($this->canRepair() && ! $breakdown->hasRepair())
                                <x-ui.button variant="ghost" size="sm" wire:click="openRepair('{{ $breakdown->id }}')">{{ __('Raise the repair') }}</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state
                                icon="exclamation-triangle"
                                :title="$this->hasFilters() ? __('No breakdowns match these filters') : __('No breakdowns recorded')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search or cause filter to see the rest of the register.')
                                    : __('Record a stop with its place, its cause and the hours the truck was down; the repair is raised from that row.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->breakdowns->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->breakdowns->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($this->canRecord())
        <x-ui.dialog name="breakdown-record" :title="__('Record a breakdown')" maxWidth="2xl">
            <form wire:submit="record" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="breakdown-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Truck') }}</label>

                        <x-ui.select name="bdTruckId" id="breakdown-truck" wire:model.live="bdTruckId">
                            <option value="">{{ __('Choose a truck…') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="bdOccurredAt" wire:model="bdOccurredAt" type="datetime-local" :label="__('Stopped at')" required />
                </div>

                <x-ui.input name="bdLocation" wire:model="bdLocation" :label="__('Where')" :hint="__('The place the workshop would have to drive to.')" required />
                <x-ui.input name="bdDescription" wire:model="bdDescription" :label="__('What happened')" required />

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <label for="breakdown-cause" class="text-xs font-medium text-fg-ink-secondary">{{ __('Cause') }}</label>

                        <x-ui.select name="bdCause" id="breakdown-cause" wire:model.live="bdCause">
                            @foreach ($this->causes() as $cause)
                                <option value="{{ $cause->value }}">{{ $cause->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="bdHoursLost" wire:model="bdHoursLost" numeric :label="__('Hours lost (optional)')" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="breakdown-trip" class="text-xs font-medium text-fg-ink-secondary">{{ __('On a trip (optional)') }}</label>

                        <x-ui.select name="bdTripId" id="breakdown-trip" wire:model="bdTripId">
                            <option value="">{{ __('No trip') }}</option>

                            @foreach ($this->tripOptions as $trip)
                                <option value="{{ $trip->id }}">{{ $trip->trip_no }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                @if ($bdCause === App\Domain\Maintenance\BreakdownCause::Driver->value)
                    <x-ui.checkbox name="bdRecoverable" wire:model="bdRecoverable" :label="__('Recover the cost from the driver')" />
                @endif

                @error('breakdown')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Record it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-breakdown-record')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif

    @if ($this->canRepair())
        <x-ui.dialog name="breakdown-repair" :title="__('Raise the repair')">
            <form wire:submit="raiseRepair" class="grid gap-4 p-5">
                @if ($this->repairBreakdown !== null)
                    <x-ui.text size="sm" color="muted">
                        {{ __(':truck stopped at :place on :date. The job is raised on that truck and that trip, priced like any other work order.', [
                            'truck' => $this->repairBreakdown->truck?->label() ?? __('The truck'),
                            'place' => $this->repairBreakdown->location,
                            'date' => $this->repairBreakdown->occurred_at->format('d M Y'),
                        ]) }}
                    </x-ui.text>
                @endif

                <x-ui.input name="repairTitle" wire:model="repairTitle" :label="__('Job title')" required />

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="repairHours" wire:model="repairHours" numeric :label="__('Estimated hours')" :hint="__('e.g. 2.5')" required />
                    <x-ui.input name="repairRate" wire:model="repairRate" numeric :label="__('Labour rate per hour (:currency)', ['currency' => $this->currencyCode()])" required />
                </div>

                <x-ui.text size="sm" color="muted">
                    {{ __('The estimate is one labour line. Parts and further lines are added on the job itself, and the spend follows the same approval threshold as any other work order.') }}
                </x-ui.text>

                @error('repairForm')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Raise it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-breakdown-repair')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
