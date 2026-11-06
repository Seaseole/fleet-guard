<?php

use App\Domain\Fleet\Actions\AssignVehicle;
use App\Domain\Fleet\Actions\ChangeTruckStatus;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Actions\ReleaseAssignment;
use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\OdometerReading;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\Models\VehicleAssignment;
use App\Domain\Fleet\Plausibility;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Formatter;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Kernel\Money\Money;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Maintenance\Models\Inspection;
use App\Domain\Maintenance\Models\TyrePosition;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Data\DriverOption;
use App\Domain\People\Queries\DriverDirectory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One truck's record: its details, who holds it, what the odometer has said, and the paper attached
 * to it.
 *
 * The four write panels are four separate permissions on purpose — assigning is `fleet.update`,
 * recording mileage is `fleet.odometer.record`, disposing is `fleet.sell`, and reading the purchase
 * price is a field gate. A single "edit this truck" form would have made every one of them the same
 * permission, which is exactly what splits them to avoid.
 *
 * The odometer form is not an edit form for `trucks.odometer_km`. Readings are evidence, the truck's
 * number is their projection, and a typed correction would erase the drop that is worth
 * investigating — so a lower reading is stored `suspect` and the projection keeps the higher number.
 */
new #[Title('Vehicle')] class extends Component {
    use ConfirmsRecentPassword;

    #[Locked]
    public int $truckId = 0;

    public string $statusTo = '';

    public string $statusReason = '';

    public string $fromAt = '';

    public string $trailerId = '';

    public string $driverId = '';

    public string $assignmentReason = '';

    public string $odometerKm = '';

    public string $engineHours = '';

    public string $readOn = '';

    public function mount(Truck $truck): void
    {
        abort_unless(Gate::allows('view', $truck), 403);

        $this->truckId = $truck->id;

        // A local wall time, because the office records "he took it at 06:30" in the company's day.
        // The Action stores UTC; this screen converts on the way in and out.
        $this->fromAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
        $this->readOn = now(CurrentCompany::timezone())->toDateString();
        $this->odometerKm = (string) $truck->odometer_km;
        $this->engineHours = $truck->engine_hours_10_2 === null ? '' : (string) $truck->engine_hours_10_2;
    }

    public function changeStatus(ChangeTruckStatus $change): void
    {
        $truck = $this->truck;

        abort_unless(Gate::allows('update', $truck), 403);

        $validated = $this->validate([
            'statusTo' => ['required', 'string', 'in:'.implode(',', array_column(FleetStatus::cases(), 'value'))],
            'statusReason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = FleetStatus::from($validated['statusTo']);

        if ($target === FleetStatus::Sold) {
            abort_unless(Gate::allows('sell', $truck), 403);

            if (! $this->confirmRecentPassword()) {
                return;
            }
        }

        try {
            $change($truck, $target, $validated['statusReason'] ?? null);
        } catch (IllegalTransition $refused) {
            $this->addError('statusTo', $refused->getMessage());

            return;
        }

        $this->reset(['statusTo', 'statusReason']);
        unset($this->truck, $this->statusActions);

        $this->dispatch('toast', message: __('Moved to :state.', ['state' => $target->label()]), kind: 'success');
    }

    public function assign(AssignVehicle $assign): void
    {
        $truck = $this->truck;

        abort_unless(Gate::allows('create', VehicleAssignment::class), 403);

        $validated = $this->validate([
            'fromAt' => ['required', 'date'],
            'trailerId' => ['nullable', 'integer', Rule::exists('trailers', 'id')->where('company_id', CurrentCompany::id())],
            'driverId' => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', CurrentCompany::id())],
            'assignmentReason' => ['nullable', 'string', 'max:255'],
        ]);

        $dto = AssignmentDto::fromArray([
            'truck_id' => $truck->id,
            'from_at' => CarbonImmutable::parse($validated['fromAt'], CurrentCompany::timezone())->utc()->toDateTimeString(),
            'trailer_id' => $validated['trailerId'] ?? null,
            'driver_id' => $validated['driverId'] ?? null,
            'reason' => $validated['assignmentReason'] ?? null,
        ]);

        try {
            $assign($dto);
        } catch (AssignmentRejected $refused) {
            $this->addError('assignment', $refused->getMessage());

            return;
        }

        $this->reset(['trailerId', 'driverId', 'assignmentReason']);
        unset($this->assignmentHistory, $this->openAssignment);

        $this->dispatch('toast', message: __('Assigned.'), kind: 'success');
    }

    public function release(int $assignmentId, ReleaseAssignment $release): void
    {
        $assignment = VehicleAssignment::query()
            ->where('truck_id', $this->truckId)
            ->findOrFail($assignmentId);

        abort_unless(Gate::allows('update', $assignment), 403);

        try {
            $release($assignment);
        } catch (AssignmentRejected $refused) {
            $this->addError('assignment', $refused->getMessage());

            return;
        }

        unset($this->assignmentHistory, $this->openAssignment);

        $this->dispatch('toast', message: __('Assignment closed.'), kind: 'success');
    }

    public function recordOdometer(RecordOdometerReading $record): void
    {
        $truck = $this->truck;

        abort_unless(Gate::allows('recordOdometer', $truck), 403);

        $validated = $this->validate([
            'odometerKm' => ['required', 'integer', 'min:0', 'max:9999999'],
            'engineHours' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'readOn' => ['required', 'date'],
        ]);

        $dto = OdometerReadingDto::fromArray([
            'truck_id' => $truck->id,
            'odometer_km' => (int) $validated['odometerKm'],
            'engine_hours' => $validated['engineHours'] ?? null,
            'read_on' => $validated['readOn'],
        ]);

        $reading = $record($dto);

        $this->reset('engineHours');
        unset($this->truck, $this->readings);

        if ($reading->plausibility === Plausibility::Suspect) {
            $this->dispatch(
                'toast',
                message: __('The reading is below the recorded odometer, so it is kept as suspect and the truck keeps the higher number.'),
                kind: 'warning',
            );

            return;
        }

        $this->dispatch('toast', message: __('Reading recorded.'), kind: 'success');
    }

    #[Computed]
    public function truck(): Truck
    {
        return Truck::query()->findOrFail($this->truckId);
    }

    /** @return array{current: FleetStatus, allowed: list<FleetStatus>, canSell: bool} */
    #[Computed]
    public function statusActions(): array
    {
        $truck = $this->truck;
        $canSell = Gate::allows('sell', $truck);
        $allowed = [];

        foreach ($truck->allowedTransitions() as $value) {
            $status = FleetStatus::from($value);

            if ($status === FleetStatus::Sold && ! $canSell) {
                continue;
            }

            $allowed[] = $status;
        }

        return ['current' => $truck->status, 'allowed' => $allowed, 'canSell' => $canSell];
    }

    #[Computed]
    public function openAssignment(): ?VehicleAssignment
    {
        return VehicleAssignment::query()
            ->with('trailer')
            ->where('truck_id', $this->truckId)
            ->whereNull('to_at')
            ->first();
    }

    /** @return Collection<int, VehicleAssignment> */
    #[Computed]
    public function assignmentHistory(): Collection
    {
        return VehicleAssignment::query()
            ->with('trailer')
            ->where('truck_id', $this->truckId)
            ->orderByDesc('from_at')
            ->limit(20)
            ->get();
    }

    /** @return array<int, DriverOption> */
    #[Computed]
    public function driverDirectory(): array
    {
        $options = [];

        foreach (app(DriverDirectory::class)->options() as $option) {
            $options[$option->id] = $option;
        }

        return $options;
    }

    /** @return list<DriverOption> */
    #[Computed]
    public function assignableDrivers(): array
    {
        return array_values(array_filter(
            $this->driverDirectory,
            static fn (DriverOption $option): bool => $option->status->mayBeDispatched(),
        ));
    }

    /**
     * The day the truck is handed over — the day the papers have to cover.
     *
     * The picker is asked before the form is posted, so an empty "From" is read as today, which is what
     * {@see \App\Domain\Fleet\Actions\AssignVehicle} will anchor its own refusal on.
     */
    #[Computed]
    public function assignmentDay(): CarbonInterface
    {
        return $this->fromAt === ''
            ? CarbonImmutable::now(CurrentCompany::timezone())
            : CarbonImmutable::parse($this->fromAt, CurrentCompany::timezone());
    }

    /**
     * The papers of one option that are not clean on the handover day.
     *
     * The bands, their words and their tones are {@see Validity::paperFlags()}'s, shared with the dispatch
     * board and the inspection list; this method only names the two papers and the day they must cover.
     *
     * @return list<array{paper: string, tone: string, state: string, days: string, on: string}>
     */
    public function driverPapers(DriverOption $option): array
    {
        return Validity::paperFlags([
            ['label' => __('licence'), 'date' => $option->licenceExpiresOn],
            ['label' => __('PRDP permit'), 'date' => $option->permitExpiresOn],
        ], $this->assignmentDay);
    }

    /** The tone of the worst paper, shared by the option's class and the widget's row. */
    public function driverOptionTone(DriverOption $option): string
    {
        return Validity::optionTone($this->driverPapers($option));
    }

    /** The class one `<option>` carries — a select cannot hold a badge, so the text takes the tone. */
    public function driverOptionClass(DriverOption $option): string
    {
        $tone = $this->driverOptionTone($option);

        return $tone === '' ? '' : 'text-'.$tone;
    }

    /**
     * The second line of one driver row.
     *
     * When the handover day would refuse the driver it says why, in {@see Validity::optionNote()}'s words.
     * When it would not, the two dates the day has to cover go there instead: the picker is asked before
     * the form is posted, and "who expires soonest" is the question a dispatcher is actually working
     * through while the list is open.
     */
    public function driverOptionNote(DriverOption $option): string
    {
        $note = Validity::optionNote($this->driverPapers($option));

        if ($note !== '') {
            return $note;
        }

        return __('licence').' '.($option->licenceExpiresOn?->format('d M Y') ?? __('none on file'))
            .' · '.__('permit').' '.($option->permitExpiresOn?->format('d M Y') ?? __('none on file'));
    }

    /** @return Collection<int, Trailer> */
    #[Computed]
    public function availableTrailers(): Collection
    {
        return Trailer::query()
            ->where('status', FleetStatus::Available->value)
            ->where('active', true)
            ->orderBy('fleet_no')
            ->orderBy('registration_no')
            ->get();
    }

    /** @return Collection<int, OdometerReading> */
    #[Computed]
    public function readings(): Collection
    {
        return OdometerReading::query()
            ->where('truck_id', $this->truckId)
            ->orderByDesc('read_on')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * The truck's shape of filling: the twelve latest fuel issues in time order, litres at one
     * decimal. Read from the same `FuelIssue` rows the fuel log aggregates — the sparkline is a shape,
     * the log stays the truth, and neither recomputes the other (docs/REDESIGN-CONCEPT.md §4).
     *
     * @return array{gated?: bool, values?: list<float>, count?: int, latest?: string}
     */
    #[Computed]
    public function fillTrend(): array
    {
        if (! Gate::allows('fuel.view')) {
            return ['gated' => true];
        }

        $rows = FuelIssue::query()
            ->where('truck_id', $this->truckId)
            ->where('status', '!=', FuelIssueStatus::Reversed->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('litres_x100')
            ->reverse()
            ->values();

        return [
            'values' => $rows->map(fn ($hundredths): float => round((int) $hundredths / 100, 1))->all(),
            'count' => $rows->count(),
            'latest' => $rows->isEmpty() ? null : Formatter::number((int) $rows->last() / 100, 1),
        ];
    }

    /**
     * Kilometres between each reading and the one before it, keyed by reading id.
     *
     * Computed over the truck's whole history rather than the twenty rows on show: the oldest row on
     * the screen still needs the reading that fell off the page to answer "how far was this leg", and
     * a column that silently blanks for the last row is a column nobody trusts.
     *
     * A reading **below** its predecessor has no distance to report. It is kept as evidence (R7) and
     * the Trust badge says what it is; inventing a kilometres figure out of a measurement the truck
     * contradicts would put a fabricated number in the same row as a real one.
     *
     * @return array<string, int|null>
     */
    #[Computed]
    public function travelled(): array
    {
        $previous = null;
        $out = [];

        foreach (OdometerReading::query()
            ->where('truck_id', $this->truckId)
            ->orderBy('read_on')
            ->orderBy('id')
            ->get(['id', 'odometer_km']) as $reading) {
            $delta = $previous === null ? null : $reading->odometer_km - $previous;
            $out[(string) $reading->id] = $delta !== null && $delta > 0 ? $delta : null;
            $previous = $reading->odometer_km;
        }

        return $out;
    }

    /** @return Collection<int, Document> */
    #[Computed]
    public function documents(): Collection
    {
        return Document::query()
            ->where('morph_type', 'truck')
            ->where('morph_id', (string) $this->truckId)
            ->orderByRaw('case when expires_on is null then 1 else 0 end')
            ->orderBy('expires_on')
            ->get();
    }

    /** @return Collection<int, TyrePosition> */
    #[Computed]
    public function fittedTyres(): Collection
    {
        return TyrePosition::query()
            ->with('tyre')
            ->where('truck_id', $this->truckId)
            ->whereNull('removed_at')
            ->orderBy('position')
            ->get();
    }

    /** @return Collection<int, WorkOrder> */
    #[Computed]
    public function workshopOrders(): Collection
    {
        return WorkOrder::query()
            ->where('truck_id', $this->truckId)
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    /** @return Collection<int, Inspection> */
    #[Computed]
    public function workshopInspections(): Collection
    {
        return Inspection::query()
            ->where('truck_id', $this->truckId)
            ->orderByDesc('done_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    /** @return Collection<int, Breakdown> */
    #[Computed]
    public function workshopBreakdowns(): Collection
    {
        return Breakdown::query()
            ->with('workOrder')
            ->where('truck_id', $this->truckId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function currencyCode(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function localTime(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(CurrentCompany::timezone())->format('d M Y H:i');
    }

    public function driverName(?int $driverId): string
    {
        if ($driverId === null) {
            return __('No driver');
        }

        return $this->driverDirectory[$driverId]->name ?? __('Driver #:id', ['id' => $driverId]);
    }
}; ?>

<div class="grid gap-6">
    @php($truck = $this->truck)

    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.link :href="route('fleet.index', ['tab' => 'trucks'])">{{ __('← Fleet register') }}</x-ui.link>

            <x-ui.heading size="xl" level="1">{{ $truck->label() }}</x-ui.heading>

            <x-ui.text>
                {{ trim(($truck->make ?? '').' '.($truck->model ?? '')) !== '' ? trim($truck->make.' '.$truck->model) : __('No make recorded') }}
                @if ($truck->year) · {{ $truck->year }} @endif
                · {{ __('Registration :no', ['no' => $truck->registration_no]) }}
            </x-ui.text>

            @php($fillTrend = $this->fillTrend)
            @if ($fillTrend['gated'] ?? false)
                <p class="flex items-center gap-1.5 pt-1 text-xs leading-4 text-fg-ink-muted">
                    <x-ui.icon name="lock-closed" size="4" />
                    {{ __('Fill history is not available to your role') }}
                </p>
            @elseif ($fillTrend['count'] >= 2)
                {{-- Masked rather than hidden above; here it earns its line only from two points up —
                     one fill is a number, not a shape. --}}
                <div class="flex items-center gap-2.5 pt-1">
                    <x-patterns.sparkline
                        :values="$fillTrend['values']"
                        :label="__('Sparkline of the :n latest fills for :truck', ['n' => $fillTrend['count'], 'truck' => $truck->label()])"
                    />
                    <p class="text-xs leading-4 text-fg-ink-muted">
                        {{ __(':n latest fills · newest :latest l', [
                            'n' => $fillTrend['count'],
                            'latest' => $fillTrend['latest'],
                        ]) }}
                    </p>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge :tone="$truck->status->tone()">{{ $truck->status->label() }}</x-ui.badge>

            @unless ($truck->active)
                <x-ui.badge tone="neutral" icon="x-circle">{{ __('inactive') }}</x-ui.badge>
            @endunless
        </div>
    </header>

    <x-ui.card :title="__('Vehicle record')">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Odometer') }}</dt>
                <dd class="fg-numeric text-sm font-medium text-fg-ink">{{ number_format($truck->odometer_km) }} km</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Engine hours') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->engine_hours_10_2 === null ? '—' : number_format($truck->engine_hours_10_2, 2) }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Capacity') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->capacity_tonnes_10_2 === null ? '—' : number_format($truck->capacity_tonnes_10_2, 2).' t' }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Fuel type') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->fuel_type->label() }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Fleet number') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->fleet_no ?: '—' }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('VIN') }}</dt>
                <dd class="font-mono text-xs text-fg-ink">{{ $truck->vin ?: '—' }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Tyre size') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->tyre_size ?: '—' }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Service every') }}</dt>
                <dd class="text-sm text-fg-ink">
                    {{ collect([
                        $truck->service_interval_km !== null ? number_format($truck->service_interval_km).' km' : null,
                        $truck->service_interval_hours !== null ? $truck->service_interval_hours.' h' : null,
                        $truck->service_interval_months !== null ? $truck->service_interval_months.' mo' : null,
                    ])->filter()->implode(' · ') ?: '—' }}
                </dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Purchased on') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->purchased_on?->format('d M Y') ?? '—' }}</dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Purchase price') }}</dt>
                <dd class="text-sm text-fg-ink">
                    <x-ui.gated :can="auth()->user()->can('seeVehicleCost')" :label="__('the purchase price')">
                        {{ $truck->purchase_amount_base === 0
                            ? '—'
                            : \App\Domain\Kernel\Money\Money::ofMinor($truck->purchase_amount_base, $this->currencyCode)->format($this->currencyCode) }}
                    </x-ui.gated>
                </dd>
            </div>

            <div class="grid gap-0.5">
                <dt class="text-xs font-medium text-fg-ink-muted">{{ __('Last state change') }}</dt>
                <dd class="text-sm text-fg-ink">{{ $truck->state_changed_at === null ? '—' : $this->localTime($truck->state_changed_at) }}</dd>
            </div>
        </dl>
    </x-ui.card>

    @can('update', $truck)
        <x-ui.card :title="__('Status')" :description="__('Every move is recorded with who made it and why. Selling also takes the truck out of the active fleet.')">
            <form class="grid gap-4" wire:submit="changeStatus">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :tone="$this->statusActions['current']->tone()">{{ $this->statusActions['current']->label() }}</x-ui.badge>
                    <x-ui.icon name="chevron-right" size="4" class="text-fg-ink-muted" />

                    @if ($this->statusActions['allowed'] === [])
                        <x-ui.text size="sm" color="muted">
                            {{ $this->statusActions['canSell'] ? __('No further moves from this state.') : __('No further moves you can make — disposing of an asset needs the fleet.sell capability.') }}
                        </x-ui.text>
                    @endif
                </div>

                @if ($this->statusActions['allowed'] !== [])
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <label for="status-to" class="text-xs font-medium text-fg-ink-secondary">{{ __('Move to') }}</label>

                            <x-ui.select name="statusTo" id="status-to" wire:model="statusTo">
                                <option value="">{{ __('Choose a state') }}</option>
                                @foreach ($this->statusActions['allowed'] as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </x-ui.select>

                        </div>

                        <x-ui.input wire:model="statusReason" name="statusReason" :label="__('Reason')" :hint="__('Required for out of service and sold.')" />
                    </div>

                    {{-- Outline: this transition has to carry its reason, and the odometer form below is the
                         action this page exists for (one reading, one owner). --}}
                    <x-ui.button variant="outline" type="submit">{{ __('Move status') }}</x-ui.button>
                @endif
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card :title="__('Assignment')" :description="__('Who holds the truck now, and who held it before. History is never overwritten.')">
        @error('assignment')
            <x-ui.alert tone="danger" :title="__('The assignment was refused')" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror

        @if ($this->openAssignment !== null)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-fg-sm border border-fg-line bg-fg-surface-sunk px-4 py-3">
                <div class="grid gap-0.5">
                    <p class="text-sm font-medium text-fg-ink">{{ $this->driverName($this->openAssignment->driver_id) }}</p>
                    <p class="text-xs text-fg-ink-muted">
                        {{ __('since :when', ['when' => $this->localTime($this->openAssignment->from_at)]) }}
                        @if ($this->openAssignment->trailer !== null)
                            · {{ __('trailer :reg', ['reg' => $this->openAssignment->trailer->registration_no]) }}
                        @endif
                    </p>
                </div>

                @can('update', $this->openAssignment)
                    <x-ui.button
                        variant="danger"
                        size="sm"
                        wire:click="release({{ $this->openAssignment->id }})"
                        wire:confirm="{{ __('Close this assignment now? The row stays as history.') }}"
                    >{{ __('Release') }}</x-ui.button>
                @endcan
            </div>
        @elseif ($truck->status->mayBeDispatched())
            @can('create', \App\Domain\Fleet\Models\VehicleAssignment::class)
                <form class="grid gap-4" wire:submit="assign">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input wire:model.live.debounce.500ms="fromAt" name="fromAt" type="datetime-local" :label="__('From')" required />

                        <div class="grid gap-1.5">
                            <label for="assignment-trailer" class="text-xs font-medium text-fg-ink-secondary">{{ __('Trailer (optional)') }}</label>

                            <x-ui.select name="trailerId" id="assignment-trailer" wire:model="trailerId">
                                <option value="">{{ __('No trailer') }}</option>
                                @foreach ($this->availableTrailers as $trailer)
                                    <option value="{{ $trailer->id }}">{{ $trailer->label() }}@if ($trailer->type) — {{ ucfirst($trailer->type) }}@endif</option>
                                @endforeach
                            </x-ui.select>

                            @if ($this->availableTrailers->isEmpty())
                                <p class="text-xs text-fg-ink-muted">{{ __('No trailer is available right now.') }}</p>
                            @endif

                        </div>

                        <div class="grid gap-1.5">
                            <label for="assignment-driver" class="text-xs font-medium text-fg-ink-secondary">{{ __('Driver (optional)') }}</label>

                            {{-- The tone rides on the `<option>`, and the reason rides beside it, because a
                                 coloured option is only visible while the list is open: the line under the
                                 control is what a seat still reads after it has chosen. The widget
                                 reads the same three values and paints them as a row. --}}
                            <x-ui.select name="driverId" id="assignment-driver" wire:model.live="driverId" template="fg-paper" template-selection="fg-name">
                                <option value="">{{ __('No driver') }}</option>
                                @foreach ($this->assignableDrivers as $option)
                                    <option value="{{ $option->id }}"
                                        class="{{ $this->driverOptionClass($option) }}"
                                        data-name="{{ $option->name }}"
                                        data-note="{{ $this->driverOptionNote($option) }}"
                                        data-tone="{{ $this->driverOptionTone($option) }}">
                                        {{ $option->name }} — {{ $this->driverOptionNote($option) }}
                                    </option>
                                @endforeach
                            </x-ui.select>

                            @php($picked = $this->driverId === '' ? null : ($this->driverDirectory[(int) $this->driverId] ?? null))

                            @if ($picked !== null && ($flags = $this->driverPapers($picked)) !== [])
                                <div class="flex flex-wrap items-center gap-1.5 pt-1" role="status">
                                    <span class="text-xs text-fg-ink-secondary">{{ $picked->name }}</span>

                                    @foreach ($flags as $flag)
                                        <x-ui.badge size="xs" :tone="$flag['tone']">
                                            {{ $flag['paper'] }} · {{ $flag['state'] }}@if ($flag['days'] !== '') · {{ $flag['days'] }}@endif
                                        </x-ui.badge>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-xs text-fg-ink-muted">{{ __('A driver whose status or papers do not cover :day is refused.', ['day' => $this->assignmentDay->format('d M Y')]) }}</p>

                        </div>

                        <x-ui.input wire:model="assignmentReason" name="assignmentReason" :label="__('Note (optional)')" />
                    </div>

                    @error('fromAt')
                        <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                    @enderror

                    {{-- Outline: an assignment is normally made from the board, where the other trucks and
                         the week are visible; the reading this page is for stays the filled action. --}}
                    <x-ui.button variant="outline" type="submit">{{ __('Assign') }}</x-ui.button>
                </form>
            @endcan
        @else
            <x-ui.text size="sm" color="muted">
                {{ __('A truck that is :state cannot start an assignment. Return it to available first.', ['state' => strtolower($truck->status->label())]) }}
            </x-ui.text>
        @endif

        @if ($this->assignmentHistory->isNotEmpty())
            <x-ui.table :caption="__('Assignment history')" class="mt-5">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('From') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('To') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Driver') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Trailer') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Note') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->assignmentHistory as $assignment)
                        <tr wire:key="assignment-{{ $assignment->id }}">
                            <x-ui.table.td nowrap>{{ $this->localTime($assignment->from_at) }}</x-ui.table.td>
                            <x-ui.table.td nowrap>
                                @if ($assignment->to_at === null)
                                    <x-ui.badge tone="accent" icon="clock">{{ __('open') }}</x-ui.badge>
                                @else
                                    {{ $this->localTime($assignment->to_at) }}
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>{{ $this->driverName($assignment->driver_id) }}</x-ui.table.td>
                            <x-ui.table.td>{{ $assignment->trailer?->registration_no ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $assignment->reason ?? '—' }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Odometer')" :description="__('A reading is evidence. A lower reading is kept as suspect and never drags the truck backwards. “Travelled” is the distance since the reading before it — the first row has none to compare with.')">
        @can('recordOdometer', $truck)
            <form class="grid gap-4" wire:submit="recordOdometer">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input wire:model="odometerKm" name="odometerKm" type="number" numeric :label="__('Odometer, km')" :hint="__('Currently :km km.', ['km' => number_format($truck->odometer_km)])" required />
                    <x-ui.input wire:model="engineHours" name="engineHours" type="number" step="0.01" numeric :label="__('Engine hours (optional)')" />
                    <x-ui.input wire:model="readOn" name="readOn" type="date" :label="__('Read on')" required />
                </div>

                <x-ui.button type="submit">{{ __('Record reading') }}</x-ui.button>
            </form>
        @endcan

        @if ($this->readings->isEmpty())
            <x-ui.empty-state
                icon="clock"
                :title="__('No readings yet')"
                :description="__('The odometer above is the figure typed when the truck was added. Every later measurement belongs here.')"
            />
        @else
            <x-ui.table :caption="__('Odometer readings')" class="mt-5">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Read on') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Travelled') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Engine hours') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Source') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Trust') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->readings as $reading)
                        <tr wire:key="reading-{{ $reading->id }}">
                            <x-ui.table.td nowrap>{{ $reading->read_on->format('d M Y') }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ number_format($reading->odometer_km) }}</x-ui.table.td>
                            <x-ui.table.td numeric>
                                {{ $this->travelled[$reading->id] === null ? '—' : number_format($this->travelled[$reading->id]).' km' }}
                            </x-ui.table.td>
                            <x-ui.table.td numeric>{{ $reading->engine_hours_10_2 === null ? '—' : number_format($reading->engine_hours_10_2, 2) }}</x-ui.table.td>
                            <x-ui.table.td>{{ $reading->source->label() }}</x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$reading->plausibility->tone()">{{ $reading->plausibility->label() }}</x-ui.badge>
                            </x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    @can('maintenance.view')
        {{-- Read-only: the tyres that are on this truck, the jobs raised for it, the checks it has had
             and the times it stopped. Each belongs to the workshop's own register; this card is the
             truck's side of the same rows, so a dispatcher does not have to leave the vehicle to ask. --}}
        <x-ui.card :title="__('Workshop history')" :description="__('What the workshop has on this truck right now, and what it has done to it recently. The registers hold the detail; every row here links back to it.')">
            @if ($this->fittedTyres->isEmpty() && $this->workshopOrders->isEmpty() && $this->workshopInspections->isEmpty() && $this->workshopBreakdowns->isEmpty())
                <x-ui.empty-state
                    icon="wrench"
                    :title="__('Nothing in the workshop yet')"
                    :description="__('Tyres, work orders, inspections and breakdowns appear here once the workshop records them against this truck.')"
                />
            @else
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <x-ui.subheading>{{ __('Tyres fitted') }}</x-ui.subheading>
                            <x-ui.link :href="route('maintenance.tyres')" wire:navigate>{{ __('Register') }}</x-ui.link>
                        </div>

                        @if ($this->fittedTyres->isEmpty())
                            <x-ui.text size="sm" color="muted">{{ __('No tyre is recorded as fitted.') }}</x-ui.text>
                        @else
                            <ul class="grid gap-1">
                                @foreach ($this->fittedTyres as $position)
                                    <li wire:key="truck-tyre-{{ $position->id }}" class="flex items-center justify-between gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
                                        <div class="grid min-w-0 gap-0.5">
                                            <p class="truncate text-sm text-fg-ink">{{ $position->tyre?->serial ?? __('Tyre #:id', ['id' => $position->tyre_id]) }}</p>
                                            <p class="text-xs text-fg-ink-muted">
                                                {{ $position->position->label() }}
                                                · {{ __('fitted :date', ['date' => $position->fitted_at->format('d M Y')]) }}
                                                · {{ number_format($position->fitted_odometer_km) }} km
                                            </p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="grid content-start gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <x-ui.subheading>{{ __('Recent jobs') }}</x-ui.subheading>
                            <x-ui.link :href="route('maintenance.index')" wire:navigate>{{ __('All work orders') }}</x-ui.link>
                        </div>

                        @if ($this->workshopOrders->isEmpty())
                            <x-ui.text size="sm" color="muted">{{ __('No work order has been raised for this truck.') }}</x-ui.text>
                        @else
                            <ul class="grid gap-1">
                                @foreach ($this->workshopOrders as $order)
                                    <li wire:key="truck-order-{{ $order->id }}" class="flex items-center justify-between gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
                                        <div class="grid min-w-0 gap-0.5">
                                            <a href="{{ route('maintenance.work_order', ['workOrder' => $order->id]) }}" wire:navigate class="truncate text-sm text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $order->title }}</a>
                                            <p class="text-xs text-fg-ink-muted">{{ $order->wo_no }} · {{ $order->opened_on->format('d M Y') }}</p>
                                        </div>

                                        <x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="grid content-start gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <x-ui.subheading>{{ __('Recent inspections') }}</x-ui.subheading>
                            <x-ui.link :href="route('maintenance.inspections')" wire:navigate>{{ __('Register') }}</x-ui.link>
                        </div>

                        @if ($this->workshopInspections->isEmpty())
                            <x-ui.text size="sm" color="muted">{{ __('No inspection has been recorded for this truck.') }}</x-ui.text>
                        @else
                            <ul class="grid gap-1">
                                @foreach ($this->workshopInspections as $inspection)
                                    <li wire:key="truck-inspection-{{ $inspection->id }}" class="flex items-center justify-between gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
                                        <div class="grid min-w-0 gap-0.5">
                                            <p class="truncate text-sm text-fg-ink">{{ $inspection->kind->label() }}</p>
                                            <p class="text-xs text-fg-ink-muted">{{ $inspection->done_at->format('d M Y H:i') }}</p>
                                        </div>

                                        <x-ui.badge :tone="$inspection->result->tone()">{{ $inspection->result->label() }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="grid content-start gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <x-ui.subheading>{{ __('Breakdowns') }}</x-ui.subheading>
                            <x-ui.link :href="route('maintenance.breakdowns')" wire:navigate>{{ __('Register') }}</x-ui.link>
                        </div>

                        @if ($this->workshopBreakdowns->isEmpty())
                            <x-ui.text size="sm" color="muted">{{ __('This truck has not been recorded as breaking down.') }}</x-ui.text>
                        @else
                            <ul class="grid gap-1">
                                @foreach ($this->workshopBreakdowns as $breakdown)
                                    <li wire:key="truck-breakdown-{{ $breakdown->id }}" class="flex items-center justify-between gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
                                        <div class="grid min-w-0 gap-0.5">
                                            <p class="truncate text-sm text-fg-ink">{{ $breakdown->location }}</p>
                                            <p class="text-xs text-fg-ink-muted">
                                                {{ $breakdown->occurred_at->format('d M Y') }}
                                                · {{ $breakdown->cause->label() }}
                                                @if ($breakdown->workOrder)
                                                    · <a href="{{ route('maintenance.work_order', ['workOrder' => $breakdown->workOrder->id]) }}" wire:navigate class="hover:text-accent-ink">{{ $breakdown->workOrder->wo_no }}</a>
                                                @else
                                                    · {{ __('no repair yet') }}
                                                @endif
                                            </p>
                                        </div>

                                        @if ($breakdown->recoverable_from_driver)
                                            <x-ui.badge tone="warning">{{ __('from driver') }}</x-ui.badge>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endcan

    <x-ui.card :title="__('Paper on file')":description="__('Registration, insurance, roadworthy — the documents whose subject is this truck.')">
        @if ($this->documents->isEmpty())
            <x-ui.empty-state
                icon="folder"
                :title="__('Nothing attached yet')"
                :description="__('Register the paper and choose this truck as its subject; the expiry then reaches the alerts board.')"
            >
                @can('documents.upload')
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        :href="route('documents.register', ['subject_type' => 'truck', 'subject_id' => (string) $truck->id])"
                    >{{ __('Register a document for this truck') }}</x-ui.button>
                @endcan
            </x-ui.empty-state>
        @else
            <ul class="grid gap-1">
                @foreach ($this->documents as $document)
                    <li wire:key="document-{{ $document->id }}" class="flex items-center justify-between gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk">
                        <div class="grid min-w-0 gap-0.5">
                            <p class="truncate text-sm text-fg-ink">{{ $document->title }}</p>
                            <p class="text-xs text-fg-ink-muted">
                                {{ $document->type->label() }}
                                @if ($document->expires_on)
                                    · {{ __('expires :date', ['date' => $document->expires_on->format('d M Y')]) }}
                                @endif
                            </p>
                        </div>

                        <x-ui.badge :tone="$document->validity()->tone()" icon="clock">{{ $document->validity()->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>

            @can('documents.upload')
                <div class="mt-4">
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        :href="route('documents.register', ['subject_type' => 'truck', 'subject_id' => (string) $truck->id])"
                    >{{ __('Register another document') }}</x-ui.button>
                </div>
            @endcan
        @endif
    </x-ui.card>
</div>
