<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\RecordInspection;
use App\Domain\Maintenance\Data\InspectionDto;
use App\Domain\Maintenance\InspectionKind;
use App\Domain\Maintenance\InspectionResult;
use App\Domain\Maintenance\Models\Inspection;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\People\Models\Driver;
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
 * The inspection register: the pre-trip walk-around, the periodic workshop check and the
 * tachograph check, kept as evidence rather than as a form.
 *
 * The checklist is the kind's own, so the form cannot ask yesterday's
 * questions tomorrow. A pass must have every item answered yes and no complaint; a fail must name the
 * item that failed; an advisory may go with a note. The DTO enforces that, and this page only turns the
 * form into it — a screen that decided a verdict would be a second definition of `result`.
 *
 * Reading is `maintenance.view`; recording is `inspections.record` (Owner, Operations Manager,
 * Workshop Manager). The driver's own kiosk is a later slice; until then the office enters the sheet.
 */
new #[Title('Inspections')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('kind')]
    public string $kindFilter = '';

    #[Url('result')]
    public string $resultFilter = '';

    /* The record dialog. */
    public string $inspTruckId = '';

    public string $inspKind = InspectionKind::PreTrip->value;

    public string $inspDriverId = '';

    public string $inspTripId = '';

    public string $inspWorkOrderId = '';

    public string $inspDoneAt = '';

    public string $inspResult = InspectionResult::Pass->value;

    /** @var array<string, bool> */
    public array $answers = [];

    /** @var list<string> */
    public array $defects = [''];

    public function mount(): void
    {
        $this->inspDoneAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
        $this->resetAnswers();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kindFilter', 'resultFilter'], true)) {
            $this->resetPage();
            unset($this->inspections);
        }

        if ($property === 'inspKind') {
            $this->resetAnswers();
        }

        if ($property === 'inspTruckId') {
            $this->inspTripId = '';
            $this->inspWorkOrderId = '';
            unset($this->tripOptions, $this->workOrderOptions);
        }

        if ($property === 'inspResult' && $this->inspResult === InspectionResult::Pass->value) {
            $this->defects = [''];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'kindFilter', 'resultFilter']);
        $this->resetPage();
        unset($this->inspections);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->kindFilter !== '' || $this->resultFilter !== '';
    }

    public function canRecord(): bool
    {
        return Gate::allows('inspections.record');
    }

    /* ------------------------------------------------------------- the form */

    public function openRecord(): void
    {
        abort_unless($this->canRecord(), 403);

        $this->resetValidation();
        $this->reset(['inspTruckId', 'inspDriverId', 'inspTripId', 'inspWorkOrderId', 'inspKind', 'inspResult']);
        $this->inspKind = InspectionKind::PreTrip->value;
        $this->inspResult = InspectionResult::Pass->value;
        $this->inspDoneAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
        $this->defects = [''];
        $this->resetAnswers();

        $this->dispatch('open-inspection-record');
    }

    public function addDefect(): void
    {
        if (count($this->defects) >= 20) {
            return;
        }

        $this->defects[] = '';
    }

    public function removeDefect(int $index): void
    {
        unset($this->defects[$index]);
        $this->defects = array_values($this->defects);

        if ($this->defects === []) {
            $this->defects = [''];
        }
    }

    public function record(RecordInspection $record): void
    {
        abort_unless($this->canRecord(), 403);

        $validated = $this->validate([
            'inspTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'inspKind' => ['required', Rule::enum(InspectionKind::class)],
            'inspResult' => ['required', Rule::enum(InspectionResult::class)],
            'inspDriverId' => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', CurrentCompany::id())],
            'inspTripId' => ['nullable', 'string', Rule::exists('trips', 'id')->where('company_id', CurrentCompany::id())],
            'inspWorkOrderId' => ['nullable', 'string', Rule::exists('work_orders', 'id')->where('company_id', CurrentCompany::id())],
            'inspDoneAt' => ['required', 'date'],
            'answers' => ['array'],
            'defects' => ['array'],
            'defects.*' => ['nullable', 'string', 'max:200'],
        ]);

        $kind = InspectionKind::from($validated['inspKind']);
        $answers = [];

        foreach ($kind->checklistKeys() as $key) {
            $answers[$key] = (bool) ($this->answers[$key] ?? false);
        }

        try {
            $inspection = $record(new InspectionDto(
                kind: $kind,
                truckId: (int) $validated['inspTruckId'],
                doneAt: Carbon::parse($validated['inspDoneAt'], CurrentCompany::timezone()),
                result: InspectionResult::from($validated['inspResult']),
                answers: $answers,
                defects: $this->defects,
                tripId: ($validated['inspTripId'] ?? '') === '' ? null : $validated['inspTripId'],
                workOrderId: ($validated['inspWorkOrderId'] ?? '') === '' ? null : $validated['inspWorkOrderId'],
                driverId: ($validated['inspDriverId'] ?? '') === '' ? null : (int) $validated['inspDriverId'],
            ));
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('inspection', $refused->getMessage());

            return;
        }

        unset($this->inspections);

        $this->dispatch('close-inspection-record');
        $this->dispatch('toast', message: __('Recorded — :result.', ['result' => strtolower($inspection->result->label())]), kind: 'success');
    }

    private function resetAnswers(): void
    {
        $kind = InspectionKind::tryFrom($this->inspKind) ?? InspectionKind::PreTrip;

        $this->answers = array_fill_keys($kind->checklistKeys(), true);
    }

    /* --------------------------------------------------------------- reads */

    /** @return LengthAwarePaginator<int, Inspection> */
    #[Computed]
    public function inspections(): LengthAwarePaginator
    {
        return Inspection::query()
            ->with(['truck', 'driver.employee', 'trip', 'workOrder'])
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or
                    ->whereHas('truck', fn ($truck) => $truck
                        ->where('registration_no', 'like', $like)
                        ->orWhere('fleet_no', 'like', $like))
                    ->orWhereHas('trip', fn ($trip) => $trip->where('trip_no', 'like', $like)));
            })
            ->when($this->kindFilter !== '', fn ($query) => $query->where('kind', $this->kindFilter))
            ->when($this->resultFilter !== '', fn ($query) => $query->where('result', $this->resultFilter))
            ->orderByDesc('done_at')
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

    /** @return Collection<int, Driver> */
    #[Computed]
    public function drivers(): Collection
    {
        return Driver::query()->with('employee')->orderBy('id')->get();
    }

    /** @return Collection<int, Trip> */
    #[Computed]
    public function tripOptions(): Collection
    {
        if ($this->inspTruckId === '') {
            return new Collection;
        }

        return Trip::query()
            ->where('truck_id', (int) $this->inspTruckId)
            ->orderByDesc('planned_departure_at')
            ->limit(50)
            ->get(['id', 'trip_no', 'truck_id', 'planned_departure_at']);
    }

    /** @return Collection<int, WorkOrder> */
    #[Computed]
    public function workOrderOptions(): Collection
    {
        if ($this->inspTruckId === '') {
            return new Collection;
        }

        return WorkOrder::query()
            ->where('truck_id', (int) $this->inspTruckId)
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'wo_no', 'truck_id', 'opened_on']);
    }

    /** @return array<string, string> */
    public function kindChecklist(): array
    {
        return (InspectionKind::tryFrom($this->inspKind) ?? InspectionKind::PreTrip)->checklist();
    }

    /** @return list<InspectionKind> */
    public function kinds(): array
    {
        return InspectionKind::cases();
    }

    /** @return list<InspectionResult> */
    public function results(): array
    {
        return InspectionResult::cases();
    }

    public function driverLabel(Driver $driver): string
    {
        return $driver->employee?->name ?? __('Driver #:id', ['id' => $driver->id]);
    }

    /**
     * The day the check is dated, which is the day the attributed driver's papers are read against.
     *
     * An inspection names who walked the truck, so the question a picker has to answer is "could this
     * person hold the paper on that day", not today — the same anchor the assignment card uses.
     */
    #[Computed]
    public function inspectionDay(): Carbon
    {
        return $this->inspDoneAt === ''
            ? Carbon::now(CurrentCompany::timezone())
            : Carbon::parse($this->inspDoneAt, CurrentCompany::timezone());
    }

    /** @return list<array{paper: string, tone: string, state: string, days: string, on: string}> */
    public function driverPapers(Driver $driver): array
    {
        return Validity::paperFlags([
            ['label' => __('licence'), 'date' => $driver->licence_expires_on],
            ['label' => __('PRDP permit'), 'date' => $driver->permit_expires_on],
        ], $this->inspectionDay);
    }

    /** The class one `<option>` carries — a select cannot hold a badge, so the text takes the tone. */
    public function driverOptionClass(Driver $driver): string
    {
        $tone = Validity::optionTone($this->driverPapers($driver));

        return $tone === '' ? '' : 'text-'.$tone;
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Inspections') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Every safety check the fleet has had, with the answers that were given and the defects that were written down. A pass carries no complaint; a fail names the item that failed.') }}
            </x-ui.text>
        </div>

        @if ($this->canRecord())
            <x-ui.button wire:click="openRecord">{{ __('Record an inspection') }}</x-ui.button>
        @endif
    </header>

    <x-ui.card :title="__('Inspection register')" class="min-w-0">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Truck or trip')" />

            <div class="grid gap-1.5">
                <label for="inspection-kind-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                <x-ui.select name="kindFilter" id="inspection-kind-filter" wire:model.live="kindFilter">
                    <option value="">{{ __('Every kind') }}</option>

                    @foreach ($this->kinds() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-1.5">
                <label for="inspection-result-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Result') }}</label>

                <x-ui.select name="resultFilter" id="inspection-result-filter" wire:model.live="resultFilter">
                    <option value="">{{ __('Every result') }}</option>

                    @foreach ($this->results() as $result)
                        <option value="{{ $result->value }}">{{ $result->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Inspections, newest first')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Done') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Kind') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Result') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Defects') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Driver') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Against') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->inspections as $inspection)
                    <tr wire:key="inspection-{{ $inspection->id }}">
                        <x-ui.table.td nowrap>{{ $inspection->done_at->format('d M Y H:i') }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($inspection->truck)
                                <a href="{{ route('fleet.show', ['truck' => $inspection->truck->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $inspection->truck->label() }}</a>
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('Truck #:id', ['id' => $inspection->truck_id]) }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td nowrap hideBelow="lg">{{ $inspection->kind->label() }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$inspection->result->tone()">{{ $inspection->result->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @php($failed = $inspection->failedChecklistItems())
                            @php($defects = $inspection->defectLines())

                            @if ($defects === [] && $failed === [])
                                <span class="text-xs text-fg-ink-muted">{{ __('none') }}</span>
                            @else
                                @if ($defects !== [])
                                    <span class="text-fg-ink">{{ $defects[0] }}</span>

                                    @if (count($defects) > 1)
                                        <span class="block text-xs text-fg-ink-muted">{{ __('+:count more', ['count' => count($defects) - 1]) }}</span>
                                    @endif
                                @endif

                                @if ($failed !== [])
                                    <span class="block text-xs text-fg-ink-muted">{{ __('failed: :items', ['items' => implode(', ', array_keys($failed))]) }}</span>
                                @endif
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $inspection->driver?->employee?->name ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td hideBelow="lg">
                            <div class="grid gap-0.5 text-xs">
                                @if ($inspection->trip)
                                    <a href="{{ route('operations.trip', ['trip' => $inspection->trip->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $inspection->trip->trip_no }}</a>
                                @endif

                                @if ($inspection->workOrder)
                                    <a href="{{ route('maintenance.work_order', ['workOrder' => $inspection->workOrder->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $inspection->workOrder->wo_no }}</a>
                                @endif

                                @if ($inspection->trip === null && $inspection->workOrder === null)
                                    <span class="text-fg-ink-muted">{{ __('—') }}</span>
                                @endif
                            </div>
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state
                                icon="check-circle"
                                :title="$this->hasFilters() ? __('No inspections match these filters') : __('No inspections yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search, kind or result filter to see the rest of the register.')
                                    : __('Record the walk-around before a truck leaves, and the register will hold the evidence that it was checked.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->inspections->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->inspections->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($this->canRecord())
        <x-ui.dialog name="inspection-record" :title="__('Record an inspection')" maxWidth="2xl">
            <form wire:submit="record" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="inspection-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Truck') }}</label>

                        <x-ui.select name="inspTruckId" id="inspection-truck" wire:model.live="inspTruckId">
                            <option value="">{{ __('Choose a truck…') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="inspection-kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                        <x-ui.select name="inspKind" id="inspection-kind" wire:model.live="inspKind">
                            @foreach ($this->kinds() as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="inspection-driver" class="text-xs font-medium text-fg-ink-secondary">{{ __('Driver (optional)') }}</label>

                        <x-ui.select name="inspDriverId" id="inspection-driver" wire:model.live="inspDriverId" template="fg-paper" template-selection="fg-name">
                            <option value="">{{ __('Not a named driver') }}</option>

                            @foreach ($this->drivers as $driver)
                                @php($papers = $this->driverPapers($driver))
                                <option value="{{ $driver->id }}"
                                    class="{{ $this->driverOptionClass($driver) }}"
                                    data-name="{{ $this->driverLabel($driver) }}"
                                    data-note="{{ Validity::optionNote($papers) }}"
                                    data-tone="{{ Validity::optionTone($papers) }}">
                                    {{ $this->driverLabel($driver) }}
                                    @if (($note = Validity::optionNote($papers)) !== '')
                                        — {{ $note }}
                                    @endif
                                </option>
                            @endforeach
                        </x-ui.select>

                        @php($attributed = $this->inspDriverId === '' ? null : $this->drivers->firstWhere('id', (int) $this->inspDriverId))

                        @if ($attributed !== null && ($flags = $this->driverPapers($attributed)) !== [])
                            <div class="flex flex-wrap items-center gap-1.5 pt-1" role="status">
                                <span class="text-xs text-fg-ink-secondary">{{ $this->driverLabel($attributed) }}</span>

                                @foreach ($flags as $flag)
                                    <x-ui.badge size="xs" :tone="$flag['tone']">
                                        {{ $flag['paper'] }} · {{ $flag['state'] }}@if ($flag['days'] !== '') · {{ $flag['days'] }}@endif
                                    </x-ui.badge>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <x-ui.input name="inspDoneAt" wire:model.live.debounce.500ms="inspDoneAt" type="datetime-local" :label="__('Done at')" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="inspection-trip" class="text-xs font-medium text-fg-ink-secondary">{{ __('Against a trip (optional)') }}</label>

                        <x-ui.select name="inspTripId" id="inspection-trip" wire:model="inspTripId">
                            <option value="">{{ __('No trip') }}</option>

                            @foreach ($this->tripOptions as $trip)
                                <option value="{{ $trip->id }}">{{ $trip->trip_no }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="inspection-work-order" class="text-xs font-medium text-fg-ink-secondary">{{ __('Against a job (optional)') }}</label>

                        <x-ui.select name="inspWorkOrderId" id="inspection-work-order" wire:model="inspWorkOrderId">
                            <option value="">{{ __('No work order') }}</option>

                            @foreach ($this->workOrderOptions as $order)
                                <option value="{{ $order->id }}">{{ $order->wo_no }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <div class="grid gap-2">
                    <x-ui.subheading>{{ __('Checklist') }}</x-ui.subheading>

                    <x-ui.text size="sm" color="muted">
                        {{ __('Every item is answered. A “no” must lead to a fail or an advisory, and both must say what was seen.') }}
                    </x-ui.text>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->kindChecklist() as $key => $question)
                            <x-ui.checkbox name="answers.{{ $key }}" wire:model="answers.{{ $key }}" :label="$question" />
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="grid gap-1.5">
                        <label for="inspection-result" class="text-xs font-medium text-fg-ink-secondary">{{ __('Result') }}</label>

                        <x-ui.select name="inspResult" id="inspection-result" wire:model.live="inspResult">
                            @foreach ($this->results() as $result)
                                <option value="{{ $result->value }}">{{ $result->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                @if ($inspResult !== App\Domain\Maintenance\InspectionResult::Pass->value)
                    <div class="grid gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <x-ui.subheading>{{ __('What was seen') }}</x-ui.subheading>

                            <x-ui.button size="sm" variant="outline" type="button" wire:click="addDefect">{{ __('Add a line') }}</x-ui.button>
                        </div>

                        @foreach ($defects as $index => $line)
                            <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]" wire:key="defect-{{ $index }}">
                                <x-ui.input name="defects.{{ $index }}" wire:model="defects.{{ $index }}" :label="__('Defect :n', ['n' => $index + 1])" />

                                @if (count($defects) > 1)
                                    <x-ui.button size="sm" variant="ghost" type="button" wire:click="removeDefect({{ $index }})" class="self-end">{{ __('Remove') }}</x-ui.button>
                                @endif
                            </div>

                            @error('defects.'.$index)
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </div>
                @endif

                @error('inspection')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Record it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-inspection-record')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
