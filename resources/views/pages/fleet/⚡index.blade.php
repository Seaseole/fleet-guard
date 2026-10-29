<?php

use App\Domain\Fleet\Actions\ChangeEquipmentStatus;
use App\Domain\Fleet\Actions\ChangeTrailerStatus;
use App\Domain\Fleet\Actions\ChangeTruckStatus;
use App\Domain\Fleet\Actions\CreateEquipment;
use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Actions\UpdateEquipment;
use App\Domain\Fleet\Actions\UpdateTrailer;
use App\Domain\Fleet\Actions\UpdateTruck;
use App\Domain\Fleet\Data\EquipmentDto;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Equipment;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\Models\VehicleAssignment;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The fleet register (§3.2, §4.3, §10.1): three tabs behind one route because a truck, a trailer and
 * a loader are the same question asked of different assets — what do we have, what state is it in,
 * and who has it.
 *
 * Every write goes through an Action and every state move through the §10.1 table, so this screen
 * never touches `status` directly. The status panel exists because that is the workflow the
 * register is actually used for: something broke, someone parked it, and the office has to record
 * why — a plain edit form would let the same move happen without the mandatory reason.
 *
 * The purchase price is a field gate, not a column (§9.4). A seat without `fleet.cost.view` sees the
 * truck and not the money, and the form hides the field entirely; the save path carries the stored
 * value forward so an ordinary edit by that seat cannot zero a number it never saw.
 */
new #[Title('Fleet')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'trucks';

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    public ?int $editingId = null;

    public string $registrationNo = '';

    public string $code = '';

    public string $fleetNo = '';

    public string $vin = '';

    public string $capacityTonnes = '';

    public string $odometerKm = '0';

    public string $make = '';

    public string $model = '';

    public string $year = '';

    public string $engineHours = '';

    public string $purchasedOn = '';

    public string $tyreSize = '';

    public string $serviceIntervalKm = '';

    public string $serviceIntervalHours = '';

    public string $serviceIntervalMonths = '';

    public string $fuelType = 'diesel';

    public string $mainTankLitres = '';

    public string $auxTankLitres = '';

    public string $expectedConsumption = '';

    public string $trailerType = 'flatbed';

    public string $axleConfig = '';

    public string $equipmentKind = 'loader';

    public string $purchaseAmount = '';

    public bool $active = true;

    /** Which row the status panel is open for: a kind from {@see asset()} and its id. */
    public ?string $statusKind = null;

    public ?int $statusTargetId = null;

    public string $statusTo = '';

    public string $statusReason = '';

    public function updated(string $property): void
    {
        if ($property === 'tab') {
            $this->clearForm();
            $this->closeStatus();
        }

        if (in_array($property, ['tab', 'search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->trucks, $this->trailers, $this->equipment, $this->openAssignments);
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['trucks', 'trailers', 'equipment'], true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->trucks, $this->trailers, $this->equipment, $this->openAssignments);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function edit(int $id): void
    {
        $this->formReset();

        if ($this->tab === 'trucks') {
            $this->editTruck($id);
        } elseif ($this->tab === 'trailers') {
            $this->editTrailer($id);
        } else {
            $this->editEquipment($id);
        }
    }

    public function clearForm(): void
    {
        $this->editingId = null;
        $this->formReset();
    }

    public function save(
        CreateTruck $createTruck,
        UpdateTruck $updateTruck,
        CreateTrailer $createTrailer,
        UpdateTrailer $updateTrailer,
        CreateEquipment $createEquipment,
        UpdateEquipment $updateEquipment,
    ): void {
        try {
            if ($this->tab === 'trucks') {
                $this->saveTruck($createTruck, $updateTruck);
            } elseif ($this->tab === 'trailers') {
                $this->saveTrailer($createTrailer, $updateTrailer);
            } else {
                $this->saveEquipment($createEquipment, $updateEquipment);
            }
        } catch (InvalidArgumentException $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->clearForm();
        unset($this->trucks, $this->trailers, $this->equipment, $this->openAssignments);

        $this->dispatch('toast', message: __('Saved.'), kind: 'success');
    }

    public function openStatus(string $kind, int $id): void
    {
        $asset = $this->asset($kind, $id);

        abort_unless(Gate::allows('update', $asset), 403);

        $this->statusKind = $kind;
        $this->statusTargetId = $id;
        $this->statusTo = '';
        $this->statusReason = '';
        $this->resetValidation();
    }

    public function closeStatus(): void
    {
        $this->statusKind = null;
        $this->statusTargetId = null;
        $this->statusTo = '';
        $this->statusReason = '';
        $this->resetValidation();
    }

    public function changeStatus(
        ChangeTruckStatus $changeTruck,
        ChangeTrailerStatus $changeTrailer,
        ChangeEquipmentStatus $changeEquipment,
    ): void {
        if ($this->statusKind === null || $this->statusTargetId === null) {
            return;
        }

        $asset = $this->asset($this->statusKind, $this->statusTargetId);

        abort_unless(Gate::allows('update', $asset), 403);

        $validated = $this->validate([
            'statusTo' => ['required', 'string', 'in:'.implode(',', array_column(FleetStatus::cases(), 'value'))],
            'statusReason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = FleetStatus::from($validated['statusTo']);

        if ($to === FleetStatus::Sold) {
            abort_unless(Gate::allows('sell', $asset), 403);
        }

        $reason = $validated['statusReason'] ?? null;

        try {
            if ($this->statusKind === 'truck') {
                $changeTruck($asset, $to, $reason);
            } elseif ($this->statusKind === 'trailer') {
                $changeTrailer($asset, $to, $reason);
            } else {
                $changeEquipment($asset, $to, $reason);
            }
        } catch (IllegalTransition $refused) {
            $this->addError('statusTo', $refused->getMessage());

            return;
        }

        $label = $to->label();

        $this->closeStatus();
        unset($this->trucks, $this->trailers, $this->equipment, $this->openAssignments);

        $this->dispatch('toast', message: __('Moved to :state.', ['state' => $label]), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, Truck> */
    #[Computed]
    public function trucks(): LengthAwarePaginator
    {
        return Truck::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('registration_no', 'like', $like)
                    ->orWhere('fleet_no', 'like', $like)
                    ->orWhere('vin', 'like', $like)
                    ->orWhere('make', 'like', $like)
                    ->orWhere('model', 'like', $like));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("case status when 'available' then 0 when 'on_trip' then 1 when 'in_workshop' then 2 when 'out_of_service' then 3 else 4 end")
            ->orderBy('fleet_no')
            ->orderBy('registration_no')
            ->paginate(25);
    }

    /** @return LengthAwarePaginator<int, Trailer> */
    #[Computed]
    public function trailers(): LengthAwarePaginator
    {
        return Trailer::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('registration_no', 'like', $like)
                    ->orWhere('fleet_no', 'like', $like)
                    ->orWhere('vin', 'like', $like)
                    ->orWhere('type', 'like', $like));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("case status when 'available' then 0 when 'on_trip' then 1 when 'in_workshop' then 2 when 'out_of_service' then 3 else 4 end")
            ->orderBy('fleet_no')
            ->orderBy('registration_no')
            ->paginate(25);
    }

    /** @return LengthAwarePaginator<int, Equipment> */
    #[Computed]
    public function equipment(): LengthAwarePaginator
    {
        return Equipment::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('code', 'like', $like)->orWhere('kind', 'like', $like));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("case status when 'available' then 0 when 'in_workshop' then 1 when 'out_of_service' then 2 else 3 end")
            ->orderBy('code')
            ->paginate(25);
    }

    /**
     * Who currently holds each truck on this page — one query and one directory read for the whole
     * page rather than a join Fleet is forbidden to write across module boundaries (§13 D5).
     *
     * @return array<int, array{driver: ?string, from: \Illuminate\Support\Carbon, trailer: ?string}>
     */
    #[Computed]
    public function openAssignments(): array
    {
        if ($this->tab !== 'trucks') {
            return [];
        }

        /** @var list<int> $ids */
        $ids = array_map(static fn (Truck $truck): int => $truck->id, $this->trucks->items());

        if ($ids === []) {
            return [];
        }

        $drivers = collect(app(DriverDirectory::class)->options())->keyBy('id');

        $rows = [];

        $assignments = VehicleAssignment::query()
            ->with('trailer')
            ->whereNull('to_at')
            ->whereIn('truck_id', $ids)
            ->get();

        foreach ($assignments as $assignment) {
            /** @var \App\Domain\People\Data\DriverOption|null $driver */
            $driver = $assignment->driver_id === null ? null : $drivers->get($assignment->driver_id);

            $rows[$assignment->truck_id] = [
                'driver' => $driver?->name,
                'from' => $assignment->from_at,
                'trailer' => $assignment->trailer?->registration_no,
            ];
        }

        return $rows;
    }

    /**
     * The status panel's target, resolved fresh on every render: the allowed destinations come from
     * the model's own transition table, so the select cannot offer a move the guard would refuse.
     *
     * @return array{label: string, current: FleetStatus, allowed: list<FleetStatus>, canSell: bool}|null
     */
    #[Computed]
    public function statusTarget(): ?array
    {
        if ($this->statusKind === null || $this->statusTargetId === null) {
            return null;
        }

        $asset = $this->asset($this->statusKind, $this->statusTargetId);
        $canSell = Gate::allows('sell', $asset);
        $allowed = [];

        foreach ($asset->allowedTransitions() as $value) {
            $status = FleetStatus::from($value);

            if ($status === FleetStatus::Sold && ! $canSell) {
                continue;
            }

            $allowed[] = $status;
        }

        return [
            'label' => $this->assetLabel($asset),
            'current' => $asset->status,
            'allowed' => $allowed,
            'canSell' => $canSell,
        ];
    }

    #[Computed]
    public function currencyCode(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    /** @return list<FleetStatus> */
    public function statuses(): array
    {
        return FleetStatus::cases();
    }

    public function formTitle(): string
    {
        if ($this->editingId !== null) {
            return match ($this->tab) {
                'trucks' => __('Edit :asset', ['asset' => __('truck')]),
                'trailers' => __('Edit :asset', ['asset' => __('trailer')]),
                default => __('Edit :asset', ['asset' => __('equipment')]),
            };
        }

        return match ($this->tab) {
            'trucks' => __('Add a truck'),
            'trailers' => __('Add a trailer'),
            default => __('Add equipment'),
        };
    }

    private function editTruck(int $id): void
    {
        $truck = Truck::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $truck), 403);

        $this->editingId = $truck->id;
        $this->registrationNo = $truck->registration_no;
        $this->fleetNo = (string) $truck->fleet_no;
        $this->vin = (string) $truck->vin;
        $this->make = (string) $truck->make;
        $this->model = (string) $truck->model;
        $this->year = (string) $truck->year;
        $this->capacityTonnes = $truck->capacity_tonnes_10_2 === null ? '' : (string) $truck->capacity_tonnes_10_2;
        $this->odometerKm = (string) $truck->odometer_km;
        $this->engineHours = $truck->engine_hours_10_2 === null ? '' : (string) $truck->engine_hours_10_2;
        $this->purchasedOn = (string) $truck->purchased_on?->format('Y-m-d');
        $this->tyreSize = (string) $truck->tyre_size;
        $this->serviceIntervalKm = (string) $truck->service_interval_km;
        $this->serviceIntervalHours = (string) $truck->service_interval_hours;
        $this->serviceIntervalMonths = (string) $truck->service_interval_months;
        $this->fuelType = $truck->fuel_type;
        $this->mainTankLitres = (string) $truck->main_tank_litres;
        $this->auxTankLitres = (string) $truck->aux_tank_litres;
        $this->expectedConsumption = $truck->expected_consumption_per_100 === null ? '' : (string) $truck->expected_consumption_per_100;
        $this->purchaseAmount = $this->majorInput($truck->purchase_amount_base);
        $this->active = $truck->active;
    }

    private function editTrailer(int $id): void
    {
        $trailer = Trailer::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $trailer), 403);

        $this->editingId = $trailer->id;
        $this->registrationNo = $trailer->registration_no;
        $this->fleetNo = (string) $trailer->fleet_no;
        $this->vin = (string) $trailer->vin;
        $this->trailerType = $trailer->type;
        $this->capacityTonnes = $trailer->capacity_tonnes_10_2 === null ? '' : (string) $trailer->capacity_tonnes_10_2;
        $this->axleConfig = (string) $trailer->axle_config;
        $this->odometerKm = (string) $trailer->odometer_km;
        $this->active = $trailer->active;
    }

    private function editEquipment(int $id): void
    {
        $equipment = Equipment::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $equipment), 403);

        $this->editingId = $equipment->id;
        $this->code = $equipment->code;
        $this->equipmentKind = $equipment->kind;
        $this->purchaseAmount = $this->majorInput($equipment->purchase_amount_base);
    }

    private function saveTruck(CreateTruck $create, UpdateTruck $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingId;

        $validated = $this->validate([
            'registrationNo' => ['required', 'string', 'max:24', Rule::unique('trucks', 'registration_no')->where('company_id', $companyId)->ignore($ignoring)],
            'fleetNo' => ['nullable', 'string', 'max:24', Rule::unique('trucks', 'fleet_no')->where('company_id', $companyId)->ignore($ignoring)],
            'vin' => ['nullable', 'string', 'max:32', Rule::unique('trucks', 'vin')->where('company_id', $companyId)->ignore($ignoring)],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'capacityTonnes' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'odometerKm' => ['required', 'integer', 'min:0', 'max:9999999'],
            'engineHours' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'purchasedOn' => ['nullable', 'date'],
            'tyreSize' => ['nullable', 'string', 'max:40'],
            'serviceIntervalKm' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'serviceIntervalHours' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'serviceIntervalMonths' => ['nullable', 'integer', 'min:0', 'max:120'],
            'fuelType' => ['required', 'string', 'max:16'],
            'mainTankLitres' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'auxTankLitres' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'expectedConsumption' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'purchaseAmount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $existing = $this->editingId === null ? null : Truck::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Truck::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        $dto = TruckDto::fromArray([
            'registration_no' => $validated['registrationNo'],
            'fleet_no' => $validated['fleetNo'] ?? null,
            'vin' => $validated['vin'] ?? null,
            'make' => $validated['make'] ?? null,
            'model' => $validated['model'] ?? null,
            'year' => $validated['year'] ?? null,
            'capacity_tonnes' => $validated['capacityTonnes'] ?? null,
            'odometer_km' => (int) $validated['odometerKm'],
            'engine_hours' => $validated['engineHours'] ?? null,
            'purchase_amount_base' => $this->purchaseMinor($existing?->purchase_amount_base ?? 0),
            'purchased_on' => $validated['purchasedOn'] ?? null,
            'tyre_size' => $validated['tyreSize'] ?? null,
            'service_interval_km' => $validated['serviceIntervalKm'] ?? null,
            'service_interval_hours' => $validated['serviceIntervalHours'] ?? null,
            'service_interval_months' => $validated['serviceIntervalMonths'] ?? null,
            'fuel_type' => $validated['fuelType'],
            'main_tank_litres' => $validated['mainTankLitres'] ?? null,
            'aux_tank_litres' => $validated['auxTankLitres'] ?? null,
            'expected_consumption_per_100' => $validated['expectedConsumption'] ?? null,
            'compliance_flags' => $existing?->compliance_flags,
            'active' => $this->active,
            'status' => $existing?->status->value ?? FleetStatus::Available->value,
        ]);

        if ($existing === null) {
            $create($dto);
        } else {
            $update($existing, $dto);
        }
    }

    private function saveTrailer(CreateTrailer $create, UpdateTrailer $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingId;

        $validated = $this->validate([
            'registrationNo' => ['required', 'string', 'max:24', Rule::unique('trailers', 'registration_no')->where('company_id', $companyId)->ignore($ignoring)],
            'fleetNo' => ['nullable', 'string', 'max:24', Rule::unique('trailers', 'fleet_no')->where('company_id', $companyId)->ignore($ignoring)],
            'vin' => ['nullable', 'string', 'max:32', Rule::unique('trailers', 'vin')->where('company_id', $companyId)->ignore($ignoring)],
            'trailerType' => ['required', 'string', 'in:'.implode(',', TrailerDto::TYPES)],
            'capacityTonnes' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'axleConfig' => ['nullable', 'string', 'max:40'],
            'odometerKm' => ['required', 'integer', 'min:0', 'max:9999999'],
        ]);

        $existing = $this->editingId === null ? null : Trailer::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Trailer::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        $dto = TrailerDto::fromArray([
            'registration_no' => $validated['registrationNo'],
            'type' => $validated['trailerType'],
            'fleet_no' => $validated['fleetNo'] ?? null,
            'vin' => $validated['vin'] ?? null,
            'capacity_tonnes' => $validated['capacityTonnes'] ?? null,
            'axle_config' => $validated['axleConfig'] ?? null,
            'odometer_km' => (int) $validated['odometerKm'],
            'active' => $this->active,
            'status' => $existing?->status->value ?? FleetStatus::Available->value,
        ]);

        if ($existing === null) {
            $create($dto);
        } else {
            $update($existing, $dto);
        }
    }

    private function saveEquipment(CreateEquipment $create, UpdateEquipment $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingId;

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:24', Rule::unique('equipment', 'code')->where('company_id', $companyId)->ignore($ignoring)],
            'equipmentKind' => ['required', 'string', 'in:'.implode(',', EquipmentDto::KINDS)],
            'purchaseAmount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $existing = $this->editingId === null ? null : Equipment::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Equipment::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        $dto = EquipmentDto::fromArray([
            'code' => $validated['code'],
            'kind' => $validated['equipmentKind'],
            'purchase_amount_base' => $this->purchaseMinor($existing?->purchase_amount_base ?? 0),
            'status' => $existing?->status->value ?? FleetStatus::Available->value,
        ]);

        if ($existing === null) {
            $create($dto);
        } else {
            $update($existing, $dto);
        }
    }

    /**
     * The purchase price as minor units, or the stored value when the seat cannot read the field.
     *
     * That second branch is what makes hiding the input safe: `''` means "not rendered", not
     * "erase", and an edit by an Operations Manager without `fleet.cost.view` keeps the price the
     * Owner entered.
     */
    private function purchaseMinor(int $stored): int
    {
        if (! Gate::allows(FieldGate::SeeVehicleCost->value)) {
            return $stored;
        }

        return $this->purchaseAmount === ''
            ? 0
            : Money::ofMajor($this->purchaseAmount, $this->currencyCode())->minorUnits;
    }

    private function majorInput(int $minor): string
    {
        if ($minor === 0 || ! Gate::allows(FieldGate::SeeVehicleCost->value)) {
            return '';
        }

        return Money::ofMinor($minor, $this->currencyCode())->toBigDecimal()->toString();
    }

    /**
     * @return Truck|Trailer|Equipment
     */
    private function asset(string $kind, int $id)
    {
        return match ($kind) {
            'truck' => Truck::query()->findOrFail($id),
            'trailer' => Trailer::query()->findOrFail($id),
            'equipment' => Equipment::query()->findOrFail($id),
            default => abort(404),
        };
    }

    private function assetLabel(Truck|Trailer|Equipment $asset): string
    {
        return $asset instanceof Equipment ? $asset->code : $asset->label();
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }

    private function formReset(): void
    {
        $this->reset([
            'registrationNo', 'code', 'fleetNo', 'vin', 'capacityTonnes', 'odometerKm',
            'make', 'model', 'year', 'engineHours', 'purchasedOn', 'tyreSize',
            'serviceIntervalKm', 'serviceIntervalHours', 'serviceIntervalMonths',
            'fuelType', 'mainTankLitres', 'auxTankLitres', 'expectedConsumption',
            'trailerType', 'axleConfig', 'equipmentKind', 'purchaseAmount', 'active',
        ]);

        $this->resetValidation();
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Fleet') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Trucks, trailers and yard equipment. Status moves through the transition table, and every move is recorded with who made it and why.') }}
        </x-ui.text>
    </header>

    <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="{{ __('Fleet registers') }}">
        @foreach ([
            'trucks' => __('Trucks'),
            'trailers' => __('Trailers'),
            'equipment' => __('Equipment'),
        ] as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="setTab('{{ $key }}')"
                class="rounded-fg-md border px-3 py-1.5 text-sm font-medium transition duration-(--t-fast) ease-(--ease-standard) {{ $tab === $key ? 'border-fg-line-strong bg-fg-surface text-fg-ink shadow-fg-1' : 'border-transparent text-fg-ink-secondary hover:bg-fg-surface-sunk hover:text-fg-ink' }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($this->statusTarget !== null)
        <x-ui.card :title="__('Change status — :asset', ['asset' => $this->statusTarget['label']])">
            <form class="grid gap-4" wire:submit="changeStatus">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :tone="$this->statusTarget['current']->tone()">{{ $this->statusTarget['current']->label() }}</x-ui.badge>
                    <x-ui.icon name="chevron-right" size="4" class="text-fg-ink-muted" />

                    @if ($this->statusTarget['allowed'] === [])
                        <x-ui.text size="sm" color="muted">
                            {{ $this->statusTarget['canSell']
                                ? __('No further moves from this state.')
                                : __('No further moves you can make — disposing of an asset needs the fleet.sell capability.') }}
                        </x-ui.text>
                    @endif
                </div>

                @if ($this->statusTarget['allowed'] !== [])
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <label for="status-to" class="text-xs font-medium text-fg-ink-secondary">{{ __('Move to') }}</label>

                            <select id="status-to" wire:model="statusTo" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                <option value="">{{ __('Choose a state') }}</option>
                                @foreach ($this->statusTarget['allowed'] as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>

                            @error('statusTo')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-ui.input wire:model="statusReason" name="statusReason" :label="__('Reason')" :hint="__('Required for out of service and sold.')" />
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    @if ($this->statusTarget['allowed'] !== [])
                        <x-ui.button type="submit">{{ __('Move status') }}</x-ui.button>
                    @endif

                    <x-ui.button variant="ghost" type="button" wire:click="closeStatus">{{ __('Close') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="match ($tab) { 'trucks' => __('Trucks'), 'trailers' => __('Trailers'), default => __('Yard equipment') }">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Registration, fleet number, VIN, make or model')" />

            <div class="grid gap-1.5">
                <label for="status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <select id="status-filter" wire:model.live="statusFilter" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                    <option value="">{{ __('Every state') }}</option>
                    @foreach ($this->statuses() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        @if ($tab === 'trucks')
            <x-ui.table :caption="__('Trucks')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Capacity') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Assigned to') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->trucks as $truck)
                        <tr wire:key="truck-{{ $truck->id }}">
                            <x-ui.table.td>
                                <a href="{{ route('fleet.show', ['truck' => $truck->id]) }}" wire:navigate class="font-medium text-fg-ink hover:text-accent">{{ $truck->label() }}</a>

                                <div class="text-xs text-fg-ink-muted">
                                    {{ trim(($truck->make ?? '').' '.($truck->model ?? '')) !== '' ? trim($truck->make.' '.$truck->model) : __('No make recorded') }}
                                    @if ($truck->year) · {{ $truck->year }} @endif
                                </div>
                            </x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$truck->status->tone()">{{ $truck->status->label() }}</x-ui.badge>

                                @unless ($truck->active)
                                    <span class="ms-1 text-xs text-fg-ink-muted">{{ __('inactive') }}</span>
                                @endunless
                            </x-ui.table.td>
                            <x-ui.table.td mono>{{ number_format($truck->odometer_km) }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $truck->capacity_tonnes_10_2 === null ? '—' : number_format($truck->capacity_tonnes_10_2, 2) }}</x-ui.table.td>
                            <x-ui.table.td>
                                @php($open = $this->openAssignments[$truck->id] ?? null)

                                @if ($open === null)
                                    <span class="text-xs text-fg-ink-muted">{{ __('unassigned') }}</span>
                                @else
                                    <div class="text-sm text-fg-ink">{{ $open['driver'] ?? __('No driver') }}</div>
                                    <div class="text-xs text-fg-ink-muted">
                                        {{ __('since :when', ['when' => $open['from']->format('d M Y H:i')]) }}
                                        @if ($open['trailer']) · {{ $open['trailer'] }} @endif
                                    </div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    @can('update', $truck)
                                        <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $truck->id }})">{{ __('Edit') }}</x-ui.button>
                                        <x-ui.button variant="ghost" size="sm" wire:click="openStatus('truck', {{ $truck->id }})">{{ __('Status') }}</x-ui.button>
                                    @endcan

                                    <x-ui.button variant="ghost" size="sm" :href="route('fleet.show', ['truck' => $truck->id])">{{ __('Open') }}</x-ui.button>
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state
                                    icon="truck"
                                    :title="$this->hasFilters() ? __('No trucks match these filters') : __('No trucks yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Add the tractor units the fleet runs, with their registration and service intervals.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->trucks->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->trucks->links() }}

                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total trucks.', [
                            'first' => $this->trucks->firstItem(),
                            'last' => $this->trucks->lastItem(),
                            'total' => $this->trucks->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        @elseif ($tab === 'trailers')
            <x-ui.table :caption="__('Trailers')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Trailer') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Type') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Capacity') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->trailers as $trailer)
                        <tr wire:key="trailer-{{ $trailer->id }}">
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $trailer->label() }}</span>

                                @if ($trailer->axle_config)
                                    <div class="text-xs text-fg-ink-muted">{{ $trailer->axle_config }}</div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>{{ ucfirst($trailer->type) }}</x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$trailer->status->tone()">{{ $trailer->status->label() }}</x-ui.badge>

                                @unless ($trailer->active)
                                    <span class="ms-1 text-xs text-fg-ink-muted">{{ __('inactive') }}</span>
                                @endunless
                            </x-ui.table.td>
                            <x-ui.table.td mono>{{ number_format($trailer->odometer_km) }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $trailer->capacity_tonnes_10_2 === null ? '—' : number_format($trailer->capacity_tonnes_10_2, 2) }}</x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    @can('update', $trailer)
                                        <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $trailer->id }})">{{ __('Edit') }}</x-ui.button>
                                        <x-ui.button variant="ghost" size="sm" wire:click="openStatus('trailer', {{ $trailer->id }})">{{ __('Status') }}</x-ui.button>
                                    @endcan
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state
                                    icon="truck"
                                    :title="$this->hasFilters() ? __('No trailers match these filters') : __('No trailers yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Record the trailers that carry the load; dispatch measures load against both capacities.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->trailers->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->trailers->links() }}

                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total trailers.', [
                            'first' => $this->trailers->firstItem(),
                            'last' => $this->trailers->lastItem(),
                            'total' => $this->trailers->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        @else
            <x-ui.table :caption="__('Yard equipment')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Code') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Kind') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Purchase price') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->equipment as $item)
                        <tr wire:key="equipment-{{ $item->id }}">
                            <x-ui.table.td class="font-medium text-fg-ink">{{ $item->code }}</x-ui.table.td>
                            <x-ui.table.td>{{ ucfirst($item->kind) }}</x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.badge>
                            </x-ui.table.td>
                            <x-ui.table.td numeric>
                                <x-ui.gated gate="seeVehicleCost" :label="__('the purchase price')">
                                    {{ \App\Domain\Kernel\Money\Money::ofMinor($item->purchase_amount_base, $this->currencyCode)->format($this->currencyCode) }}
                                </x-ui.gated>
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    @can('update', $item)
                                        <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $item->id }})">{{ __('Edit') }}</x-ui.button>
                                        <x-ui.button variant="ghost" size="sm" wire:click="openStatus('equipment', {{ $item->id }})">{{ __('Status') }}</x-ui.button>
                                    @endcan
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    icon="cube"
                                    :title="$this->hasFilters() ? __('No equipment matches these filters') : __('No equipment yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Loaders, generators and toolboxes are kept apart from the trucks so the vehicle count stays honest.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->equipment->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->equipment->links() }}

                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total items.', [
                            'first' => $this->equipment->firstItem(),
                            'last' => $this->equipment->lastItem(),
                            'total' => $this->equipment->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        @endif
    </x-ui.card>

    @can('fleet.create')
        <x-ui.card :title="$this->formTitle()">
            <form class="grid gap-4" wire:submit="save">
                @if ($tab === 'trucks')
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.input wire:model="registrationNo" name="registrationNo" :label="__('Registration number')" required />
                        <x-ui.input wire:model="fleetNo" name="fleetNo" :label="__('Fleet number (optional)')" :hint="__('How the yard names it, e.g. T-07.')" />
                        <x-ui.input wire:model="vin" name="vin" :label="__('VIN (optional)')" />
                        <x-ui.input wire:model="make" name="make" :label="__('Make (optional)')" />
                        <x-ui.input wire:model="model" name="model" :label="__('Model (optional)')" />
                        <x-ui.input wire:model="year" name="year" type="number" :label="__('Model year (optional)')" />
                        <x-ui.input wire:model="capacityTonnes" name="capacityTonnes" type="number" step="0.01" numeric :label="__('Capacity, tonnes (optional)')" />
                        <x-ui.input
                            wire:model="odometerKm"
                            name="odometerKm"
                            type="number"
                            numeric
                            :label="__('Odometer, km')"
                            :hint="$editingId === null ? __('The reading as it stands today.') : __('Odometer moves through readings, not edits.')"
                            :disabled="$editingId !== null"
                        />
                        <x-ui.input wire:model="engineHours" name="engineHours" type="number" step="0.01" numeric :label="__('Engine hours (optional)')" />
                        <x-ui.input wire:model="purchasedOn" name="purchasedOn" type="date" :label="__('Purchased on (optional)')" />
                        <x-ui.input wire:model="tyreSize" name="tyreSize" :label="__('Tyre size (optional)')" />
                        <x-ui.input wire:model="fuelType" name="fuelType" :label="__('Fuel type')" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input wire:model="serviceIntervalKm" name="serviceIntervalKm" type="number" numeric :label="__('Service every, km (optional)')" />
                        <x-ui.input wire:model="serviceIntervalHours" name="serviceIntervalHours" type="number" numeric :label="__('Service every, hours (optional)')" />
                        <x-ui.input wire:model="serviceIntervalMonths" name="serviceIntervalMonths" type="number" numeric :label="__('Service every, months (optional)')" />
                        <x-ui.input wire:model="mainTankLitres" name="mainTankLitres" type="number" numeric :label="__('Main tank, litres (optional)')" />
                        <x-ui.input wire:model="auxTankLitres" name="auxTankLitres" type="number" numeric :label="__('Auxiliary tank, litres (optional)')" />
                        <x-ui.input wire:model="expectedConsumption" name="expectedConsumption" type="number" step="0.01" numeric :label="__('Expected consumption, l/100 km (optional)')" />
                    </div>
                @elseif ($tab === 'trailers')
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.input wire:model="registrationNo" name="registrationNo" :label="__('Registration number')" required />
                        <x-ui.input wire:model="fleetNo" name="fleetNo" :label="__('Fleet number (optional)')" />
                        <x-ui.input wire:model="vin" name="vin" :label="__('VIN (optional)')" />

                        <div class="grid gap-1.5">
                            <label for="trailer-type" class="text-xs font-medium text-fg-ink-secondary">{{ __('Type') }}</label>

                            <select id="trailer-type" wire:model="trailerType" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach (\App\Domain\Fleet\Data\TrailerDto::TYPES as $type)
                                    <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input wire:model="capacityTonnes" name="capacityTonnes" type="number" step="0.01" numeric :label="__('Capacity, tonnes (optional)')" />
                        <x-ui.input wire:model="axleConfig" name="axleConfig" :label="__('Axle configuration (optional)')" />
                        <x-ui.input wire:model="odometerKm" name="odometerKm" type="number" numeric :label="__('Hubodometer, km')" :hint="__('Zero when the trailer has none.')" />
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input wire:model="code" name="code" :label="__('Code')" required />

                        <div class="grid gap-1.5">
                            <label for="equipment-kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                            <select id="equipment-kind" wire:model="equipmentKind" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach (\App\Domain\Fleet\Data\EquipmentDto::KINDS as $kind)
                                    <option value="{{ $kind }}">{{ ucfirst($kind) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                @can('seeVehicleCost')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input
                            wire:model="purchaseAmount"
                            name="purchaseAmount"
                            numeric
                            :label="__('Purchase price (optional)')"
                            :prefix="$this->currencyCode"
                            :hint="__('In the company base currency. Recorded for the asset register and depreciation.')"
                        />
                    </div>
                @endcan

                @if ($tab !== 'equipment')
                    <x-ui.checkbox name="active" wire:model="active" :label="__('Active — still part of the fleet')" />
                @endif

                @error('form')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ $editingId === null ? __('Save') : __('Update') }}</x-ui.button>

                    @if ($editingId !== null)
                        <x-ui.button variant="ghost" type="button" wire:click="clearForm">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan
</div>
