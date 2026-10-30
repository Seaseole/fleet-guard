<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\RaiseWorkOrder;
use App\Domain\Maintenance\Actions\SaveServiceSchedule;
use App\Domain\Maintenance\Data\ServiceScheduleDto;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\ServiceDueState;
use App\Domain\Maintenance\ServiceScheduleKind;
use App\Domain\Maintenance\WorkOrderKind;
use App\Domain\Maintenance\WorkOrderLineKind;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Domain\People\Models\Employee;
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
 * The workshop register (§3.2, §4.3, §10.6): the schedules that say when a truck is due, and the
 * work orders that say what the workshop is doing about it.
 *
 * The schedule's state is printed, never stored (§10.8): `due_soon` is a function of the row, the
 * truck's odometer or hours and today's date, and a state column would be right only until the truck
 * next moved. That is why the badge is computed on render and the alert engine asks the same question
 * the same way — one rule, two readers.
 *
 * Two dialogs are separate because they write different things. A schedule is the rule (every
 * 25,000 km, every 90 days); a work order is one visit against it, priced line by line because
 * §10.6's approval threshold measures the estimate the workshop actually thinks it will spend. A
 * raise started from a schedule row pre-fills the truck and the service but still asks for the first
 * line: an order with no estimate is one whose price nobody considered.
 *
 * Reading is `maintenance.view` for every seat §9.3 names. Writing is the Actions' own gates —
 * `work_orders.create` for schedules and orders, `work_orders.approve` for the spend — so a
 * dispatcher can see that the truck is due without being able to book it in.
 */
new #[Title('Maintenance')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = 'open';

    #[Url('kind')]
    public string $kindFilter = '';

    /* The schedule dialog: a rule for one truck. */
    public ?int $scheduleId = null;

    public string $scheduleTruckId = '';

    public string $scheduleName = '';

    public string $scheduleKind = ServiceScheduleKind::Km->value;

    public string $scheduleInterval = '';

    public string $scheduleLastAt = '';

    public string $scheduleLastOn = '';

    public bool $scheduleActive = true;

    /* The raise dialog: one visit, priced by its lines. */
    public string $woTruckId = '';

    public string $woKind = WorkOrderKind::Service->value;

    public string $woTitle = '';

    public string $woDescription = '';

    public string $woEstimatedHours = '';

    public string $woScheduleId = '';

    public string $woMechanicId = '';

    public string $woSupplierId = '';

    /** @var list<array{kind: string, description: string, quantity: string, unitCost: string, capital: bool}> */
    public array $woLines = [];

    public function mount(): void
    {
        $this->resetWoLines();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'kindFilter'], true)) {
            $this->resetPage();
            unset($this->orders);
        }

        if ($property === 'woTruckId') {
            $this->woScheduleId = '';
            unset($this->raiseScheduleOptions);
        }

        if ($property === 'scheduleKind') {
            $this->scheduleLastAt = '';
            $this->scheduleLastOn = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'kindFilter']);
        $this->statusFilter = 'open';
        $this->resetPage();
        unset($this->orders);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->kindFilter !== '' || $this->statusFilter !== 'open';
    }

    public function canCreate(): bool
    {
        return Gate::allows('work_orders.create');
    }

    public function canApprove(): bool
    {
        return Gate::allows('work_orders.approve');
    }

    /* ------------------------------------------------------------- schedules */

    public function openScheduleDialog(?int $id = null): void
    {
        abort_unless($this->canCreate(), 403);

        $this->clearSchedule();

        if ($id !== null) {
            $schedule = ServiceSchedule::query()->findOrFail($id);

            $this->scheduleId = $schedule->id;
            $this->scheduleTruckId = (string) $schedule->truck_id;
            $this->scheduleName = $schedule->name;
            $this->scheduleKind = $schedule->kind->value;
            $this->scheduleInterval = $this->intervalAsTyped($schedule);
            $this->scheduleLastAt = $this->lastDoneAtAsTyped($schedule);
            $this->scheduleLastOn = $schedule->last_done_on?->toDateString() ?? '';
            $this->scheduleActive = $schedule->active;
        }

        $this->dispatch('open-schedule-form');
    }

    public function clearSchedule(): void
    {
        $this->scheduleId = null;
        $this->reset(['scheduleTruckId', 'scheduleName', 'scheduleInterval', 'scheduleLastAt', 'scheduleLastOn', 'scheduleActive', 'scheduleKind']);
        $this->scheduleKind = ServiceScheduleKind::Km->value;
        $this->scheduleActive = true;

        $this->resetValidation();
    }

    public function saveSchedule(SaveServiceSchedule $save): void
    {
        abort_unless($this->canCreate(), 403);

        $validated = $this->validate([
            'scheduleTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'scheduleName' => ['required', 'string', 'max:80'],
            'scheduleKind' => ['required', Rule::enum(ServiceScheduleKind::class)],
            'scheduleInterval' => ['nullable', 'string'],
            'scheduleLastAt' => ['nullable', 'string'],
            'scheduleLastOn' => ['nullable', 'date'],
        ]);

        $kind = ServiceScheduleKind::from($validated['scheduleKind']);

        try {
            [$interval, $lastAt] = $this->intervalValues(
                $kind,
                (string) ($validated['scheduleInterval'] ?? ''),
                (string) ($validated['scheduleLastAt'] ?? ''),
            );

            $schedule = $save(new ServiceScheduleDto(
                truckId: (int) $validated['scheduleTruckId'],
                name: $validated['scheduleName'],
                kind: $kind,
                intervalValue: $interval,
                lastDoneAtValue: $lastAt,
                lastDoneOn: ($validated['scheduleLastOn'] ?? '') === '' ? null : Carbon::parse($validated['scheduleLastOn']),
                active: $this->scheduleActive,
            ), $this->scheduleId === null ? null : ServiceSchedule::query()->findOrFail($this->scheduleId), (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('schedule', $refused->getMessage());

            return;
        }

        $this->clearSchedule();
        unset($this->schedules, $this->stats);

        $this->dispatch('close-schedule-form');
        $this->dispatch('toast', message: __(':name is on the register.', ['name' => $schedule->name]), kind: 'success');
    }

    /** Open the raise dialog from a schedule row, with the truck and the service already named. */
    public function raiseForSchedule(int $id): void
    {
        abort_unless($this->canCreate(), 403);

        $schedule = ServiceSchedule::query()->with('truck')->findOrFail($id);

        $this->clearRaise();

        $this->woTruckId = (string) $schedule->truck_id;
        $this->woScheduleId = (string) $schedule->id;
        $this->woKind = WorkOrderKind::Service->value;
        $this->woTitle = $schedule->name;
        $this->woLines[0]['description'] = $schedule->name;

        $this->dispatch('open-wo-raise');
    }

    /* ---------------------------------------------------------- work orders */

    public function openRaise(): void
    {
        abort_unless($this->canCreate(), 403);

        $this->clearRaise();
        $this->dispatch('open-wo-raise');
    }

    public function clearRaise(): void
    {
        $this->reset(['woTruckId', 'woKind', 'woTitle', 'woDescription', 'woEstimatedHours', 'woScheduleId', 'woMechanicId', 'woSupplierId', 'woLines']);

        $this->woKind = WorkOrderKind::Service->value;
        $this->resetWoLines();
        $this->resetValidation();
    }

    public function addLine(): void
    {
        $this->woLines[] = [
            'kind' => WorkOrderLineKind::Labour->value,
            'description' => '',
            'quantity' => '',
            'unitCost' => '',
            'capital' => false,
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->woLines[$index]);
        $this->woLines = array_values($this->woLines);
    }

    public function raiseWorkOrder(RaiseWorkOrder $raise): void
    {
        abort_unless($this->canCreate(), 403);

        $validated = $this->validate([
            'woTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'woKind' => ['required', Rule::enum(WorkOrderKind::class)],
            'woTitle' => ['required', 'string', 'max:140'],
            'woDescription' => ['nullable', 'string', 'max:500'],
            'woEstimatedHours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'woScheduleId' => ['nullable', 'integer', Rule::exists('service_schedules', 'id')->where('company_id', CurrentCompany::id())],
            'woMechanicId' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', CurrentCompany::id())],
            'woSupplierId' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', CurrentCompany::id())],
        ]);

        $base = $this->baseCurrency();
        $lines = [];

        foreach ($this->woLines as $index => $line) {
            try {
                $lines[] = new WorkOrderLineDto(
                    kind: WorkOrderLineKind::from((string) $line['kind']),
                    description: (string) $line['description'],
                    quantityX100: $this->scaledHundredths((string) $line['quantity'], __('Line :n quantity', ['n' => $index + 1])),
                    unitCostMinor: $this->scaledHundredths((string) $line['unitCost'], __('Line :n unit cost', ['n' => $index + 1])),
                    currencyCode: $base,
                    capitalCandidate: (bool) $line['capital'],
                );
            } catch (InvalidArgumentException|DomainException $refused) {
                $this->addError('woForm', __('Line :n: :message', ['n' => $index + 1, 'message' => $refused->getMessage()]));

                return;
            }
        }

        try {
            $order = $raise(new WorkOrderDto(
                truckId: (int) $validated['woTruckId'],
                kind: WorkOrderKind::from($validated['woKind']),
                title: $validated['woTitle'],
                lines: $lines,
                description: ($validated['woDescription'] ?? '') === '' ? null : $validated['woDescription'],
                estimatedHours: ($validated['woEstimatedHours'] ?? '') === '' ? null : (float) $validated['woEstimatedHours'],
                serviceScheduleId: ($validated['woScheduleId'] ?? '') === '' ? null : (int) $validated['woScheduleId'],
                mechanicEmployeeId: ($validated['woMechanicId'] ?? '') === '' ? null : (int) $validated['woMechanicId'],
                supplierId: ($validated['woSupplierId'] ?? '') === '' ? null : (int) $validated['woSupplierId'],
            ), (int) auth()->id());
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('woForm', $refused->getMessage());

            return;
        }

        $this->redirectRoute('maintenance.work_order', ['workOrder' => $order->id], navigate: true);
    }

    /* ----------------------------------------------------------------- reads */

    public function today(): Carbon
    {
        return Carbon::now(CurrentCompany::timezone());
    }

    /** @return Collection<int, ServiceSchedule> */
    #[Computed]
    public function schedules(): Collection
    {
        return ServiceSchedule::query()
            ->with('truck')
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('truck_id')
            ->orderBy('name')
            ->get();
    }

    /** @return LengthAwarePaginator<int, WorkOrder> */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $open = $this->openStatusValues();
        $search = trim($this->search);

        return WorkOrder::query()
            ->with(['truck', 'schedule'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($or) => $or
                    ->where('wo_no', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereHas('truck', fn ($truck) => $truck
                        ->where('registration_no', 'like', $like)
                        ->orWhere('fleet_no', 'like', $like)));
            })
            ->when($this->statusFilter === 'open', fn ($query) => $query->whereIn('status', $open))
            ->when($this->statusFilter !== '' && $this->statusFilter !== 'open', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->kindFilter !== '', fn ($query) => $query->where('kind', $this->kindFilter))
            ->orderByRaw("case when status in ('closed', 'rejected', 'cancelled') then 1 else 0 end")
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->paginate(20, pageName: 'orders');
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()
            ->where('active', true)
            ->where('status', '!=', FleetStatus::Sold->value)
            ->orderBy('fleet_no')
            ->get(['id', 'fleet_no', 'registration_no', 'odometer_km', 'engine_hours_10_2']);
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function mechanics(): Collection
    {
        return Employee::query()->where('active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Supplier> */
    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** @return Collection<int, ServiceSchedule> */
    #[Computed]
    public function raiseScheduleOptions(): Collection
    {
        if ($this->woTruckId === '') {
            return new Collection;
        }

        return ServiceSchedule::query()
            ->where('truck_id', (int) $this->woTruckId)
            ->orderBy('name')
            ->get(['id', 'truck_id', 'name']);
    }

    /** @return list<array{label: string, figure: int, caption: string, tone: string, href: string|null}> */
    #[Computed]
    public function stats(): array
    {
        $open = WorkOrder::query()->whereIn('status', $this->openStatusValues())->count();
        $waiting = WorkOrder::query()->where('status', WorkOrderStatus::AwaitingApproval->value)->count();
        $inWorkshop = Truck::query()->where('status', FleetStatus::InWorkshop->value)->count();

        $today = $this->today();
        $overdue = 0;
        $dueSoon = 0;

        foreach ($this->schedules as $schedule) {
            $state = $schedule->stateOn($today);

            if ($state === ServiceDueState::Overdue) {
                $overdue++;
            } elseif ($state === ServiceDueState::DueSoon) {
                $dueSoon++;
            }
        }

        return [
            [
                'label' => __('Open work orders'),
                'figure' => $open,
                'caption' => __('Every job not yet closed, rejected or withdrawn.'),
                'tone' => 'neutral',
                'href' => route('maintenance.index', ['status' => 'open']),
            ],
            [
                'label' => __('Waiting for approval'),
                'figure' => $waiting,
                'caption' => $waiting > 0 ? __('Spend above the limit, waiting on the Owner or Operations Manager.') : __('Nothing is waiting on a signature.'),
                'tone' => $waiting > 0 ? 'warning' : 'success',
                'href' => route('maintenance.index', ['status' => WorkOrderStatus::AwaitingApproval->value]),
            ],
            [
                'label' => __('Services overdue'),
                'figure' => $overdue,
                'caption' => $dueSoon > 0 ? __(':count more are due soon.', ['count' => $dueSoon]) : __('No schedule has passed its due point.'),
                'tone' => $overdue > 0 ? 'danger' : 'success',
                'href' => null,
            ],
            [
                'label' => __('Trucks in the workshop'),
                'figure' => $inWorkshop,
                'caption' => $inWorkshop > 0 ? __('Off the road while the work is under way.') : __('Every truck is available to dispatch.'),
                'tone' => 'neutral',
                'href' => route('fleet.index'),
            ],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function statusChoices(): array
    {
        return [
            ['value' => 'open', 'label' => __('Open only')],
            ['value' => '', 'label' => __('Any status')],
            ...array_map(
                static fn (WorkOrderStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                WorkOrderStatus::cases(),
            ),
        ];
    }

    /** @return list<WorkOrderKind> */
    public function kinds(): array
    {
        return WorkOrderKind::cases();
    }

    /** @return list<WorkOrderLineKind> */
    public function lineKinds(): array
    {
        return WorkOrderLineKind::cases();
    }

    /** @return list<ServiceScheduleKind> */
    public function serviceKinds(): array
    {
        return ServiceScheduleKind::cases();
    }

    /** The interval's unit for the form's hint — tolerant of a stale snapshot value. */
    public function scheduleUnit(): string
    {
        return ServiceScheduleKind::tryFrom($this->scheduleKind)?->unit() ?? '';
    }

    public function baseCurrency(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function money(Money $money): string
    {
        return $money->format($this->baseCurrency());
    }

    /** "1,250 km", "12 Sep 2026" — when the service was last done, in the schedule's own unit. */
    public function lastDoneLabel(ServiceSchedule $schedule): string
    {
        return match ($schedule->kind) {
            ServiceScheduleKind::Km => $schedule->last_done_at_value === null
                ? '—'
                : number_format((int) $schedule->last_done_at_value).' km',
            ServiceScheduleKind::Hours => $schedule->last_done_at_value === null
                ? '—'
                : number_format(((int) $schedule->last_done_at_value) / 100, 2, '.', '').' hours',
            ServiceScheduleKind::Date => $schedule->last_done_on?->format('d M Y') ?? '—',
            ServiceScheduleKind::Condition => $schedule->last_done_on?->format('d M Y') ?? '—',
        };
    }

    /** The interval back in the unit the form typed it in ("250.00" hours, "25000" km). */
    private function intervalAsTyped(ServiceSchedule $schedule): string
    {
        if ($schedule->interval_value === null) {
            return '';
        }

        return $schedule->kind === ServiceScheduleKind::Hours
            ? number_format($schedule->interval_value / 100, 2, '.', '')
            : (string) $schedule->interval_value;
    }

    private function lastDoneAtAsTyped(ServiceSchedule $schedule): string
    {
        if ($schedule->last_done_at_value === null) {
            return '';
        }

        return $schedule->kind === ServiceScheduleKind::Hours
            ? number_format($schedule->last_done_at_value / 100, 2, '.', '')
            : (string) $schedule->last_done_at_value;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function intervalValues(ServiceScheduleKind $kind, string $interval, string $lastAt): array
    {
        if ($kind === ServiceScheduleKind::Condition) {
            return [null, null];
        }

        if (trim($interval) === '') {
            throw new InvalidArgumentException(__('A service interval must be a positive number in its own unit.'));
        }

        if ($kind === ServiceScheduleKind::Hours) {
            $scaled = $this->scaledHundredths($interval, __('Interval'));
        } else {
            if (preg_match('/^\d{1,9}$/', trim($interval)) !== 1) {
                throw new InvalidArgumentException(__('A distance or calendar interval is a whole number.'));
            }

            $scaled = (int) trim($interval);
        }

        if ($scaled <= 0) {
            throw new InvalidArgumentException(__('A service interval must be a positive number in its own unit.'));
        }

        $last = null;

        if (trim($lastAt) !== '') {
            $last = $kind === ServiceScheduleKind::Hours
                ? $this->scaledHundredths($lastAt, __('Last done at'))
                : (int) $lastAt;
        }

        return [$scaled, $last];
    }

    /** "45.32" → 4532, without ever building a float (§20.4). */
    private function scaledHundredths(string $input, string $label): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__(':field must be a number with at most two decimals.', ['field' => $label]));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /** @return list<string> */
    private function openStatusValues(): array
    {
        return array_values(array_map(
            static fn (WorkOrderStatus $status): string => $status->value,
            array_filter(WorkOrderStatus::cases(), static fn (WorkOrderStatus $status): bool => $status->isOpen()),
        ));
    }

    private function resetWoLines(): void
    {
        $this->woLines = [[
            'kind' => WorkOrderLineKind::Labour->value,
            'description' => '',
            'quantity' => '',
            'unitCost' => '',
            'capital' => false,
        ]];
    }
}; ?>

<div class="grid gap-6">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Maintenance') }}</x-ui.heading>
            <x-ui.text>
                {{ __('What the fleet is due for, and what the workshop is doing about it. A schedule is the rule; a work order is one visit against it, priced before anyone picks up a spanner.') }}
            </x-ui.text>
        </div>

        @if ($this->canCreate())
            <x-ui.button wire:click="openRaise">{{ __('Raise a work order') }}</x-ui.button>
        @endif
    </header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->stats as $card)
            <x-ui.metric-card
                :label="$card['label']"
                :figure="$card['figure']"
                :caption="$card['caption']"
                :tone="$card['tone']"
                :href="$card['href']"
            />
        @endforeach
    </div>

    {{-- The rules. State and remaining are computed here on every render, never read from a column. --}}
    <x-ui.card :title="__('Service schedules')" class="min-w-0">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-ui.text size="sm" color="muted">
                {{ __('A schedule says when a truck is due, in kilometres, engine hours or days. The state beside it is worked out from the truck as it stands right now — nothing writes it down, so it cannot go stale.') }}
            </x-ui.text>

            @if ($this->canCreate())
                <x-ui.button size="sm" variant="outline" wire:click="openScheduleDialog">{{ __('Add a schedule') }}</x-ui.button>
            @endif
        </div>

        @if ($this->schedules->isEmpty())
            <x-ui.empty-state
                icon="wrench"
                title="{{ __('No service schedules yet') }}"
                description="{{ __('Set the interval a truck is serviced at — every 25,000 km, every 500 engine hours, every 90 days — and the register will tell you when it is due before the truck does.') }}"
            />
        @else
            @php($today = $this->today())

            <x-ui.table :caption="__('Service schedules, with the interval, the last service and the state the truck is in today')">
                <x-slot name="head">
                    <x-ui.table.th>{{ __('Service') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Interval') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Last done') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Next due') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Remaining') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </x-slot>

                @foreach ($this->schedules as $schedule)
                    @php($state = $schedule->stateOn($today))
                    <tr wire:key="schedule-{{ $schedule->id }}">
                        <x-ui.table.td>
                            <span class="text-fg-ink">{{ $schedule->name }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $schedule->kind->label() }}</span>
                            @unless ($schedule->active)
                                <x-ui.badge tone="neutral" class="mt-1">{{ __('retired') }}</x-ui.badge>
                            @endunless
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($schedule->truck !== null)
                                <a href="{{ route('fleet.show', ['truck' => $schedule->truck->id]) }}" wire:navigate class="text-fg-ink hover:text-accent">{{ $schedule->truck->label() }}</a>
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('No truck') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $schedule->intervalLabel() }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $this->lastDoneLabel($schedule) }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $schedule->dueLabel() }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @if ($state === App\Domain\Maintenance\ServiceDueState::Blocked)
                                <span class="text-xs text-fg-ink-muted">{{ __('—') }}</span>
                            @else
                                {{ $schedule->remainingLabel($today) }}
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($schedule->active)
                                <x-ui.badge :tone="$state->tone()">{{ $state->label() }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('not chased') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @if ($this->canCreate())
                                @if ($schedule->active)
                                    <x-ui.button size="sm" variant="ghost" wire:click="raiseForSchedule({{ $schedule->id }})">{{ __('Book work') }}</x-ui.button>
                                @endif

                                <x-ui.button size="sm" variant="ghost" wire:click="openScheduleDialog({{ $schedule->id }})">{{ __('Edit') }}</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- The visits. The default filter is "open", because a register full of closed orders answers nothing. --}}
    <x-ui.card :title="__('Work orders')" class="min-w-0">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Number, title or truck')" />

            <div class="grid gap-1.5">
                <label for="wo-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <x-ui.select name="statusFilter" id="wo-status-filter" wire:model.live="statusFilter">
                    @foreach ($this->statusChoices() as $choice)
                        <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-1.5">
                <label for="wo-kind-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                <x-ui.select name="kindFilter" id="wo-kind-filter" wire:model.live="kindFilter">
                    <option value="">{{ __('Every kind') }}</option>

                    @foreach ($this->kinds() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Work orders, newest first — the order, the truck, what it is for and where it has got to')">
            <x-slot name="head">
                <x-ui.table.th>{{ __('Work order') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Kind') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Opened') }}</x-ui.table.th>
                <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Estimate') }}</x-ui.table.th>
                <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
            </x-slot>

            @forelse ($this->orders as $order)
                <tr wire:key="work-order-{{ $order->id }}">
                    <x-ui.table.td>
                        <a href="{{ route('maintenance.work_order', ['workOrder' => $order->id]) }}" wire:navigate class="font-medium text-fg-ink hover:text-accent">{{ $order->wo_no }}</a>
                        <span class="block text-xs text-fg-ink-muted">{{ $order->title }}</span>
                        @if ($order->schedule !== null)
                            <span class="block text-xs text-fg-ink-muted">{{ __('for :service', ['service' => $order->schedule->name]) }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>
                        @if ($order->truck !== null)
                            <a href="{{ route('fleet.show', ['truck' => $order->truck->id]) }}" wire:navigate class="text-fg-ink hover:text-accent">{{ $order->truck->label() }}</a>
                        @else
                            <span class="text-xs text-fg-ink-muted">{{ __('Truck #:id', ['id' => $order->truck_id]) }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :tone="$order->kind->isUrgent() ? 'danger' : 'neutral'">{{ $order->kind->label() }}</x-ui.badge>
                    </x-ui.table.td>
                    <x-ui.table.td nowrap>{{ $order->opened_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge>
                        @if ($order->status === App\Domain\Maintenance\WorkOrderStatus::OnHold && $order->hold_reason !== null)
                            <span class="block text-xs text-fg-ink-muted">{{ $order->hold_reason->label() }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td numeric nowrap>
                        {{ $this->money($order->total()) }}
                        @if ($order->neededApproval() && $order->approved_by === null)
                            <span class="block text-xs text-warning">{{ __('needs approval') }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td numeric nowrap>
                        <x-ui.button size="sm" variant="ghost" :href="route('maintenance.work_order', ['workOrder' => $order->id])">{{ __('Open') }}</x-ui.button>
                    </x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state
                            icon="wrench"
                            :title="$this->hasFilters() ? __('No work orders match these filters') : __('No work orders yet')"
                            :description="$this->hasFilters()
                                ? __('Clear the search, status or kind filter to see the rest of the register.')
                                : __('Raise one when a truck needs work: the estimate decides whether it needs the Owner’s approval.')"
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        @if ($this->orders->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->orders->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- The schedule form: the rule, not a visit. --}}
    @if ($this->canCreate())
        <x-ui.dialog name="schedule-form" :title="$scheduleId === null ? __('Add a service schedule') : __('Edit schedule')">
            <form wire:submit="saveSchedule" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="schedule-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Truck') }}</label>

                        <x-ui.select name="scheduleTruckId" id="schedule-truck" wire:model="scheduleTruckId">
                            <option value="">{{ __('Choose a truck…') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="scheduleName" wire:model="scheduleName" :label="__('What is being serviced')" :hint="__('“Engine service”, “Tyres rotated”, “Brake check”.')" />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <label for="schedule-kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Measured in') }}</label>

                        <x-ui.select name="scheduleKind" id="schedule-kind" wire:model.live="scheduleKind">
                            @foreach ($this->serviceKinds() as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    @if ($scheduleKind !== App\Domain\Maintenance\ServiceScheduleKind::Condition->value)
                        <x-ui.input
                            name="scheduleInterval"
                            wire:model="scheduleInterval"
                            numeric
                            :label="__('Every')"
                            :hint="__('In :unit.', ['unit' => $this->scheduleUnit()])"
                        />
                    @endif

                    @if ($scheduleKind === App\Domain\Maintenance\ServiceScheduleKind::Date->value)
                        <x-ui.input name="scheduleLastOn" wire:model="scheduleLastOn" type="date" :label="__('Last done on (optional)')" />
                    @elseif ($scheduleKind === App\Domain\Maintenance\ServiceScheduleKind::Condition->value)
                        <x-ui.input name="scheduleLastOn" wire:model="scheduleLastOn" type="date" :label="__('Last seen on (optional)')" />
                    @else
                        <x-ui.input
                            name="scheduleLastAt"
                            wire:model="scheduleLastAt"
                            numeric
                            :label="__('Last done at (optional)')"
                            :hint="__('Blank starts from the truck’s current reading.')"
                        />
                    @endif
                </div>

                <x-ui.checkbox name="scheduleActive" wire:model="scheduleActive" :label="__('Active — the register chases this service')" />

                @error('schedule')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ $scheduleId === null ? __('Save') : __('Update') }}</x-ui.button>

                    @if ($scheduleId !== null)
                        <x-ui.button variant="ghost" type="button" wire:click="clearSchedule">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.dialog>
    @endif

    {{-- The raise form: the truck, what it is for and the lines that make up the estimate. --}}
    @if ($this->canCreate())
        <x-ui.dialog name="wo-raise" :title="__('Raise a work order')" maxWidth="2xl">
            <form wire:submit="raiseWorkOrder" class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="wo-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Truck') }}</label>

                        <x-ui.select name="woTruckId" id="wo-truck" wire:model.live="woTruckId">
                            <option value="">{{ __('Choose a truck…') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="wo-kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Why is it in') }}</label>

                        <x-ui.select name="woKind" id="wo-kind" wire:model="woKind">
                            @foreach ($this->kinds() as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <x-ui.input name="woTitle" wire:model="woTitle" :label="__('Title')" :hint="__('What a mechanic is being handed: “front brakes”, “10,000 km service”.')" />
                <x-ui.input name="woDescription" wire:model="woDescription" :label="__('Description (optional)')" />

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <label for="wo-schedule" class="text-xs font-medium text-fg-ink-secondary">{{ __('Closes a schedule (optional)') }}</label>

                        <x-ui.select name="woScheduleId" id="wo-schedule" wire:model="woScheduleId">
                            <option value="">{{ __('None') }}</option>

                            @foreach ($this->raiseScheduleOptions as $schedule)
                                <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="wo-mechanic" class="text-xs font-medium text-fg-ink-secondary">{{ __('Mechanic (optional)') }}</label>

                        <x-ui.select name="woMechanicId" id="wo-mechanic" wire:model="woMechanicId">
                            <option value="">{{ __('Unassigned') }}</option>

                            @foreach ($this->mechanics as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="woEstimatedHours" wire:model="woEstimatedHours" numeric :label="__('Hours (optional)')" />
                </div>

                <div class="grid gap-1.5">
                    <label for="wo-supplier" class="text-xs font-medium text-fg-ink-secondary">{{ __('External garage (optional)') }}</label>

                    <x-ui.select name="woSupplierId" id="wo-supplier" wire:model="woSupplierId">
                        <option value="">{{ __('Work stays in-house') }}</option>

                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <x-ui.subheading>{{ __('Estimate') }}</x-ui.subheading>

                        <x-ui.button size="sm" variant="outline" type="button" wire:click="addLine">{{ __('Add a line') }}</x-ui.button>
                    </div>

                    <x-ui.text size="sm" color="muted">
                        {{ __('The estimate is the lines added up, in :currency — this is the figure the approval limit is measured against.', ['currency' => $this->baseCurrency()]) }}
                    </x-ui.text>

                    @foreach ($woLines as $index => $line)
                        <div class="grid gap-2" wire:key="wo-line-{{ $index }}">
                            <div class="grid gap-3 rounded-fg-md border border-fg-line p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)_minmax(0,0.7fr)_minmax(0,1fr)_auto]">
                                <div class="grid gap-1.5">
                                    <label for="wo-line-kind-{{ $index }}" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                                    <x-ui.select name="woLines.{{ $index }}.kind" id="wo-line-kind-{{ $index }}" wire:model="woLines.{{ $index }}.kind">
                                        @foreach ($this->lineKinds() as $kind)
                                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>

                                <x-ui.input name="woLines.{{ $index }}.description" wire:model="woLines.{{ $index }}.description" :label="__('What')" />

                                <x-ui.input name="woLines.{{ $index }}.quantity" wire:model="woLines.{{ $index }}.quantity" numeric :label="__('Qty')" />

                                <x-ui.input name="woLines.{{ $index }}.unitCost" wire:model="woLines.{{ $index }}.unitCost" numeric :label="__('Unit cost')" />

                                @if (count($woLines) > 1)
                                    <x-ui.button size="sm" variant="ghost" type="button" wire:click="removeLine({{ $index }})" class="self-end">{{ __('Remove') }}</x-ui.button>
                                @endif
                            </div>

                            <x-ui.checkbox name="woLines.{{ $index }}.capital" wire:model="woLines.{{ $index }}.capital" :label="__('This line may be an asset — a capital candidate at close')" />

                            @error('woLines.'.$index.'.description')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                            @error('woLines.'.$index.'.quantity')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                            @error('woLines.'.$index.'.unitCost')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>

                @error('woForm')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center justify-between gap-3">
                    <x-ui.button type="submit">{{ __('Raise it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-wo-raise')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
