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
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Money\Money;
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
 * to it (§4.3, §10.1, §14.4).
 *
 * The four write panels are four separate permissions on purpose — assigning is `fleet.update`,
 * recording mileage is `fleet.odometer.record`, disposing is `fleet.sell`, and reading the purchase
 * price is a field gate. A single "edit this truck" form would have made every one of them the same
 * permission, which is exactly what §9.3 splits them to avoid.
 *
 * The odometer form is not an edit form for `trucks.odometer_km`. Readings are evidence, the truck's
 * number is their projection, and a typed correction would erase the drop that is worth
 * investigating — so a lower reading is stored `suspect` and the projection keeps the higher number.
 */
new #[Title('Vehicle')] class extends Component {
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
        // The Action stores UTC; this screen converts on the way in and out (§2).
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

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="grid gap-1">
            <x-ui.link :href="route('fleet.index', ['tab' => 'trucks'])">{{ __('← Fleet register') }}</x-ui.link>

            <x-ui.heading size="xl" level="1">{{ $truck->label() }}</x-ui.heading>

            <x-ui.text>
                {{ trim(($truck->make ?? '').' '.($truck->model ?? '')) !== '' ? trim($truck->make.' '.$truck->model) : __('No make recorded') }}
                @if ($truck->year) · {{ $truck->year }} @endif
                · {{ __('Registration :no', ['no' => $truck->registration_no]) }}
            </x-ui.text>
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
                <dd class="text-sm text-fg-ink">{{ ucfirst($truck->fuel_type) }}</dd>
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
                    <x-ui.gated gate="seeVehicleCost" :label="__('the purchase price')">
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
                         action this page exists for (§4.3: one reading, one owner). --}}
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
                        <x-ui.input wire:model="fromAt" name="fromAt" type="datetime-local" :label="__('From')" required />

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

                            <x-ui.select name="driverId" id="assignment-driver" wire:model="driverId">
                                <option value="">{{ __('No driver') }}</option>
                                @foreach ($this->assignableDrivers as $option)
                                    <option value="{{ $option->id }}">
                                        {{ $option->name }}
                                        — {{ __('licence') }} {{ $option->licenceExpiresOn?->format('d M Y') ?? __('none on file') }}
                                        · {{ __('permit') }} {{ $option->permitExpiresOn?->format('d M Y') ?? __('none on file') }}
                                    </option>
                                @endforeach
                            </x-ui.select>

                            <p class="text-xs text-fg-ink-muted">{{ __('A driver whose status or papers do not cover the start date is refused.') }}</p>

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

    <x-ui.card :title="__('Odometer')" :description="__('A reading is evidence. A lower reading is kept as suspect and never drags the truck backwards.')">
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
                        <x-ui.table.th numeric>{{ __('Engine hours') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Source') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Trust') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->readings as $reading)
                        <tr wire:key="reading-{{ $reading->id }}">
                            <x-ui.table.td nowrap>{{ $reading->read_on->format('d M Y') }}</x-ui.table.td>
                            <x-ui.table.td mono>{{ number_format($reading->odometer_km) }}</x-ui.table.td>
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

    <x-ui.card :title="__('Paper on file')" :description="__('Registration, insurance, roadworthy — the §4.4 documents whose subject is this truck.')">
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
