<?php

use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\ChangeDriverStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Actions\UpdateDriver;
use App\Domain\People\Actions\UpdateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The staff register (§3.2, §4.3): everyone on the books, and the subset who drive.
 *
 * Two rows about one person, on one screen, because the questions arrive together: "who is on the
 * books" and "who can take a truck tomorrow". The second answer is not a column — it is the driver's
 * status plus two dates, and this screen evaluates it the same way dispatch will.
 *
 * Three fields are gated rather than permission-filtered (§9.4): salary and the default allowance
 * behind `seePayroll`, and the national ID and licence number are §9.5 masked columns with no
 * decrypt path in this phase. A seat without the payroll gate sees the person and not the pay, and an
 * ordinary edit by that seat preserves the stored figure it never saw.
 *
 * Driver status moves only through `ChangeDriverStatus`; suspension and leaving require a reason, and
 * the form cannot post its way around that because the Action asks the §10.1 guard.
 */
new #[Title('People')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'employees';

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    public ?int $editingEmployeeId = null;

    public ?int $editingDriverId = null;

    public string $employeeNo = '';

    public string $name = '';

    public string $roleOrGrade = '';

    public string $hiredOn = '';

    public string $leftOn = '';

    public string $nationalId = '';

    public string $salary = '';

    public string $payFrequency = 'monthly';

    public bool $active = true;

    public string $driverEmployeeId = '';

    public string $licenceNo = '';

    public string $licenceCode = '';

    public string $licenceExpiresOn = '';

    public string $permitExpiresOn = '';

    public string $allowance = '';

    public string $fuelEfficiency = '';

    public bool $kioskEnabled = false;

    public ?int $statusDriverId = null;

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
            unset($this->employees, $this->drivers);
        }
    }

    /** Normalised because the register comes from the URL: see the same guard on the fleet register. */
    public function mount(): void
    {
        if (! in_array($this->tab, ['employees', 'drivers'], true)) {
            $this->tab = 'employees';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->employees, $this->drivers);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function editEmployee(int $id): void
    {
        $employee = Employee::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $employee), 403);

        $this->formReset();

        $this->editingEmployeeId = $employee->id;
        $this->employeeNo = $employee->employee_no;
        $this->name = $employee->name;
        $this->roleOrGrade = (string) $employee->role_or_grade;
        $this->hiredOn = (string) $employee->hired_on?->format('Y-m-d');
        $this->leftOn = (string) $employee->left_on?->format('Y-m-d');
        $this->salary = $this->majorInput($employee->gross_salary_base);
        $this->payFrequency = $employee->pay_frequency;
        $this->active = $employee->active;

        // Never the mask: a form field that round-trips `••••0123` would store the mask as the ID.
        $this->nationalId = '';
    }

    public function editDriver(int $id): void
    {
        $driver = Driver::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $driver), 403);

        $this->formReset();

        $this->editingDriverId = $driver->id;
        $this->driverEmployeeId = (string) $driver->employee_id;
        $this->licenceCode = (string) $driver->licence_code;
        $this->licenceExpiresOn = (string) $driver->licence_expires_on?->format('Y-m-d');
        $this->permitExpiresOn = (string) $driver->permit_expires_on?->format('Y-m-d');
        $this->allowance = $this->majorInput($driver->default_allowance_rate_base);
        $this->fuelEfficiency = $driver->fuel_efficiency_baseline_10_2 === null ? '' : (string) $driver->fuel_efficiency_baseline_10_2;
        $this->kioskEnabled = $driver->kiosk_enabled;
        $this->licenceNo = '';
    }

    public function clearForm(): void
    {
        $this->formReset();
    }

    public function save(
        CreateEmployee $createEmployee,
        UpdateEmployee $updateEmployee,
        CreateDriver $createDriver,
        UpdateDriver $updateDriver,
    ): void {
        try {
            if ($this->tab === 'employees') {
                $this->saveEmployee($createEmployee, $updateEmployee);
            } else {
                $this->saveDriver($createDriver, $updateDriver);
            }
        } catch (InvalidArgumentException $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->clearForm();
        unset($this->employees, $this->drivers, $this->driverCandidates);

        $this->dispatch('toast', message: __('Saved.'), kind: 'success');
    }

    public function openStatus(int $driverId): void
    {
        $driver = Driver::query()->findOrFail($driverId);

        abort_unless(Gate::allows('changeStatus', $driver), 403);

        $this->statusDriverId = $driver->id;
        $this->statusTo = '';
        $this->statusReason = '';
        $this->resetValidation();
    }

    public function closeStatus(): void
    {
        $this->statusDriverId = null;
        $this->statusTo = '';
        $this->statusReason = '';
        $this->resetValidation();
    }

    public function changeDriverStatus(ChangeDriverStatus $change): void
    {
        if ($this->statusDriverId === null) {
            return;
        }

        $driver = Driver::query()->findOrFail($this->statusDriverId);

        abort_unless(Gate::allows('changeStatus', $driver), 403);

        $validated = $this->validate([
            'statusTo' => ['required', 'string', 'in:'.implode(',', array_column(DriverStatus::cases(), 'value'))],
            'statusReason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = DriverStatus::from($validated['statusTo']);

        try {
            $change($driver, $target, $validated['statusReason'] ?? null);
        } catch (IllegalTransition $refused) {
            $this->addError('statusTo', $refused->getMessage());

            return;
        }

        $this->closeStatus();
        unset($this->drivers, $this->statusTarget);

        $this->dispatch('toast', message: __('Moved to :state.', ['state' => $target->label()]), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, Employee> */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        return Employee::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like)
                    ->orWhere('role_or_grade', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'left', fn ($query) => $query->where('active', false))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return LengthAwarePaginator<int, Driver> */
    #[Computed]
    public function drivers(): LengthAwarePaginator
    {
        return Driver::query()
            ->with('employee')
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->whereHas('employee', fn ($employee) => $employee
                    ->where('name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("case status when 'active' then 0 when 'standby' then 1 when 'suspended' then 2 else 3 end")
            ->orderBy(Employee::select('name')->whereColumn('employees.id', 'drivers.employee_id'))
            ->paginate(25);
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function driverCandidates(): Collection
    {
        return Employee::query()
            ->whereDoesntHave('drivers')
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return array{name: string, current: DriverStatus, allowed: list<DriverStatus>}|null */
    #[Computed]
    public function statusTarget(): ?array
    {
        if ($this->statusDriverId === null) {
            return null;
        }

        $driver = Driver::query()->with('employee')->findOrFail($this->statusDriverId);

        return [
            'name' => $driver->employee?->name ?? __('Driver #:id', ['id' => $driver->id]),
            'current' => $driver->status,
            'allowed' => array_map(
                static fn (string $value): DriverStatus => DriverStatus::from($value),
                $driver->allowedTransitions(),
            ),
        ];
    }

    /** @return list<DriverStatus> */
    public function driverStatuses(): array
    {
        return DriverStatus::cases();
    }

    #[Computed]
    public function currencyCode(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    public function today(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now(CurrentCompany::timezone())->startOfDay();
    }

    public function canSeePay(): bool
    {
        return Gate::allows(FieldGate::SeePayroll->value);
    }

    public function formTitle(): string
    {
        if ($this->tab === 'employees') {
            return $this->editingEmployeeId === null ? __('Add an employee') : __('Edit employee');
        }

        return $this->editingDriverId === null ? __('Add a driver') : __('Edit driver');
    }

    private function saveEmployee(CreateEmployee $create, UpdateEmployee $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingEmployeeId;

        $validated = $this->validate([
            'employeeNo' => ['required', 'string', 'max:24', Rule::unique('employees', 'employee_no')->where('company_id', $companyId)->ignore($ignoring)],
            'name' => ['required', 'string', 'max:120'],
            'roleOrGrade' => ['nullable', 'string', 'max:80'],
            'hiredOn' => ['required', 'date'],
            'leftOn' => ['nullable', 'date', 'after_or_equal:hiredOn'],
            'nationalId' => ['nullable', 'string', 'max:32'],
            'salary' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'payFrequency' => ['required', 'string', 'in:'.implode(',', EmployeeDto::PAY_FREQUENCIES)],
        ]);

        $existing = $this->editingEmployeeId === null ? null : Employee::query()->findOrFail($this->editingEmployeeId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Employee::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        $dto = EmployeeDto::fromArray([
            'employee_no' => $validated['employeeNo'],
            'name' => $validated['name'],
            'role_or_grade' => $validated['roleOrGrade'] ?? null,
            'hired_on' => $validated['hiredOn'],
            'left_on' => $validated['leftOn'] ?? null,
            'national_id' => $validated['nationalId'] ?? null,
            'gross_salary_base' => $this->moneyMinor($this->salary, $existing?->gross_salary_base ?? 0),
            'pay_frequency' => $validated['payFrequency'],
            'active' => $this->active,
        ]);

        if ($existing === null) {
            $create($dto);
        } else {
            $update($existing, $dto);
        }
    }

    private function saveDriver(CreateDriver $create, UpdateDriver $update): void
    {
        $companyId = CurrentCompany::id();
        $creating = $this->editingDriverId === null;

        $rules = [
            'driverEmployeeId' => $creating
                ? ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId), Rule::unique('drivers', 'employee_id')->where('company_id', $companyId)]
                : ['nullable', 'integer'],
            'licenceNo' => ['nullable', 'string', 'max:32'],
            'licenceCode' => ['nullable', 'string', 'max:10'],
            'licenceExpiresOn' => ['nullable', 'date'],
            'permitExpiresOn' => ['nullable', 'date'],
            'allowance' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fuelEfficiency' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'kioskEnabled' => ['boolean'],
        ];

        $validated = $this->validate($rules);

        $existing = $this->editingDriverId === null ? null : Driver::query()->findOrFail($this->editingDriverId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Driver::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        $dto = DriverDto::fromArray([
            'employee_id' => $existing?->employee_id ?? (int) $validated['driverEmployeeId'],
            'licence_no' => $validated['licenceNo'] ?? null,
            'licence_code' => $validated['licenceCode'] ?? null,
            'licence_expires_on' => $validated['licenceExpiresOn'] ?? null,
            'permit_expires_on' => $validated['permitExpiresOn'] ?? null,
            'allowance_rate_base' => $this->moneyMinor($this->allowance, $existing?->default_allowance_rate_base ?? 0),
            'fuel_efficiency_baseline' => $validated['fuelEfficiency'] ?? null,
            'kiosk_enabled' => $this->kioskEnabled,
            'status' => $existing?->status->value ?? DriverStatus::Active->value,
        ]);

        if ($existing === null) {
            $create($dto);
        } else {
            $update($existing, $dto);
        }
    }

    /**
     * Pay figures are a field gate (§9.4). A form that hid the input but wrote `0` on save would
     * quietly erase the salary of everyone it was hidden from, so the stored figure is carried
     * forward when the seat cannot read the field.
     */
    private function moneyMinor(string $typed, int $stored): int
    {
        if (! $this->canSeePay()) {
            return $stored;
        }

        return $typed === '' ? 0 : Money::ofMajor($typed, $this->currencyCode())->minorUnits;
    }

    private function majorInput(int $minor): string
    {
        if ($minor === 0 || ! $this->canSeePay()) {
            return '';
        }

        return Money::ofMinor($minor, $this->currencyCode())->toBigDecimal()->toString();
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }

    private function formReset(): void
    {
        $this->editingEmployeeId = null;
        $this->editingDriverId = null;

        $this->reset([
            'employeeNo', 'name', 'roleOrGrade', 'hiredOn', 'leftOn', 'nationalId', 'salary',
            'payFrequency', 'active', 'driverEmployeeId', 'licenceNo', 'licenceCode',
            'licenceExpiresOn', 'permitExpiresOn', 'allowance', 'fuelEfficiency', 'kioskEnabled',
        ]);

        $this->resetValidation();
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('People') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The staff register and the drivers on it. A driver’s licence and permit dates are what dispatch reads before it names one.') }}
        </x-ui.text>
    </header>

    {{-- Links, not a `role="tablist"` that promised a tab pattern it did not implement (no tabpanel, no
         aria-controls, no arrow keys) — the same demotion the fleet register got. --}}
    <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('People registers') }}">
        @foreach (['employees' => __('Employees'), 'drivers' => __('Drivers')] as $key => $label)
            <a
                href="{{ route('people.index', array_filter(['tab' => $key, 'search' => $search, 'status' => $statusFilter])) }}"
                @if ($tab === $key) aria-current="true" @endif
                wire:navigate
                class="rounded-fg-md border px-3 py-1.5 text-sm font-medium transition duration-(--t-fast) ease-(--ease-standard) {{ $tab === $key ? 'border-fg-line-strong bg-fg-surface text-fg-ink shadow-fg-1' : 'border-transparent text-fg-ink-secondary hover:bg-fg-surface-sunk hover:text-fg-ink' }}"
            >{{ $label }}</a>
        @endforeach
    </nav>

    @if ($this->statusTarget !== null)
        <x-ui.card :title="__('Change status — :driver', ['driver' => $this->statusTarget['name']])">
            <form class="grid gap-4" wire:submit="changeDriverStatus">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :tone="$this->statusTarget['current']->tone()">{{ $this->statusTarget['current']->label() }}</x-ui.badge>
                    <x-ui.icon name="chevron-right" size="4" class="text-fg-ink-muted" />

                    @if ($this->statusTarget['allowed'] === [])
                        <x-ui.text size="sm" color="muted">{{ __('No further moves from this state.') }}</x-ui.text>
                    @endif
                </div>

                @if ($this->statusTarget['allowed'] !== [])
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <label for="driver-status-to" class="text-xs font-medium text-fg-ink-secondary">{{ __('Move to') }}</label>

                            <select id="driver-status-to" wire:model="statusTo" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                <option value="">{{ __('Choose a state') }}</option>
                                @foreach ($this->statusTarget['allowed'] as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>

                            @error('statusTo')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-ui.input wire:model="statusReason" name="statusReason" :label="__('Reason')" :hint="__('Required for suspension and leaving.')" />
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    @if ($this->statusTarget['allowed'] !== [])
                        {{-- Outline, matching the fleet register: a status move explains itself, the employee
                             editor below is the filled action of this page (§5). --}}
                        <x-ui.button variant="outline" type="submit">{{ __('Move status') }}</x-ui.button>
                    @endif

                    <x-ui.button variant="ghost" type="button" wire:click="closeStatus">{{ __('Close') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="$tab === 'employees' ? __('Employees') : __('Drivers')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="$tab === 'employees' ? __('Name, employee number or role') : __('Driver name or employee number')" />

            <div class="grid gap-1.5">
                <label for="people-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <select id="people-status-filter" wire:model.live="statusFilter" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                    <option value="">{{ $tab === 'employees' ? __('Everyone') : __('Every state') }}</option>

                    @if ($tab === 'employees')
                        <option value="active">{{ __('On the books') }}</option>
                        <option value="left">{{ __('Left') }}</option>
                    @else
                        @foreach ($this->driverStatuses() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    @endif
                </select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        @if ($tab === 'employees')
            <x-ui.table :caption="__('Employees')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Employee') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Role or grade') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Hired') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('National ID') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Gross salary') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->employees as $employee)
                        <tr wire:key="employee-{{ $employee->id }}">
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $employee->name }}</span>
                                <div class="font-mono text-xs text-fg-ink-muted">{{ $employee->employee_no }}</div>
                            </x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $employee->role_or_grade ?: '—' }}</x-ui.table.td>
                            <x-ui.table.td nowrap>{{ $employee->hired_on?->format('d M Y') ?? '—' }}</x-ui.table.td>
                            <x-ui.table.td>
                                @if ($employee->active)
                                    <x-ui.badge tone="success" icon="check">{{ __('on the books') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="neutral">{{ __('left') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="font-mono text-xs">{{ $employee->maskedNationalId() }}</x-ui.table.td>
                            <x-ui.table.td numeric>
                                <x-ui.gated gate="seePayroll" :label="__('salary')">
                                    {{ $employee->gross_salary_base === 0
                                        ? '—'
                                        : \App\Domain\Kernel\Money\Money::ofMinor($employee->gross_salary_base, $this->currencyCode)->format($this->currencyCode) }}
                                </x-ui.gated>
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                @can('update', $employee)
                                    <x-ui.button variant="ghost" size="sm" wire:click="editEmployee({{ $employee->id }})">{{ __('Edit') }}</x-ui.button>
                                @endcan
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state
                                    icon="users"
                                    :title="$this->hasFilters() ? __('No employees match these filters') : __('No employees yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Add the people on the books; a driver is one of them with a licence on file.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->employees->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->employees->links() }}

                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total employees.', [
                            'first' => $this->employees->firstItem(),
                            'last' => $this->employees->lastItem(),
                            'total' => $this->employees->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        @else
            <x-ui.table :caption="__('Drivers')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Driver') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Licence') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Licence expires') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Permit expires') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Eligible today') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->drivers as $driver)
                        @php($today = $this->today())
                        <tr wire:key="driver-{{ $driver->id }}">
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $driver->employee?->name ?? __('Driver #:id', ['id' => $driver->id]) }}</span>
                                <div class="font-mono text-xs text-fg-ink-muted">{{ $driver->employee?->employee_no }}</div>
                            </x-ui.table.td>
                            <x-ui.table.td class="font-mono text-xs">
                                {{ $driver->maskedLicenceNo() }}
                                @if ($driver->licence_code)
                                    <div class="text-fg-ink-muted">{{ $driver->licence_code }}</div>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td nowrap>
                                @if ($driver->licence_expires_on === null)
                                    <span class="text-xs text-fg-ink-muted">{{ __('none on file') }}</span>
                                @else
                                    <span class="{{ $driver->licenceValidOn($today) ? 'text-fg-ink' : 'text-danger' }}">{{ $driver->licence_expires_on->format('d M Y') }}</span>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td nowrap>
                                @if ($driver->permit_expires_on === null)
                                    <span class="text-xs text-fg-ink-muted">{{ __('none on file') }}</span>
                                @else
                                    <span class="{{ $driver->permitValidOn($today) ? 'text-fg-ink' : 'text-danger' }}">{{ $driver->permit_expires_on->format('d M Y') }}</span>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge :tone="$driver->status->tone()">{{ $driver->status->label() }}</x-ui.badge>
                            </x-ui.table.td>
                            <x-ui.table.td>
                                @if ($driver->eligibleOn($today))
                                    <x-ui.badge tone="success" icon="check">{{ __('cleared') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="danger">{{ __('not cleared') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    @can('update', $driver)
                                        <x-ui.button variant="ghost" size="sm" wire:click="editDriver({{ $driver->id }})">{{ __('Edit') }}</x-ui.button>
                                    @endcan

                                    @can('changeStatus', $driver)
                                        <x-ui.button variant="ghost" size="sm" wire:click="openStatus({{ $driver->id }})">{{ __('Status') }}</x-ui.button>
                                    @endcan
                                </div>
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state
                                    icon="identification"
                                    :title="$this->hasFilters() ? __('No drivers match these filters') : __('No drivers yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Make an employee a driver to record their licence and permit dates.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->drivers->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->drivers->links() }}

                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total drivers.', [
                            'first' => $this->drivers->firstItem(),
                            'last' => $this->drivers->lastItem(),
                            'total' => $this->drivers->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        @endif
    </x-ui.card>

    @can('people.manage')
        <x-ui.card :title="$this->formTitle()">
            <form class="grid gap-4" wire:submit="save">
                @if ($tab === 'employees')
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.input wire:model="employeeNo" name="employeeNo" :label="__('Employee number')" required />
                        <x-ui.input wire:model="name" name="name" :label="__('Full name')" required />
                        <x-ui.input wire:model="roleOrGrade" name="roleOrGrade" :label="__('Role or grade (optional)')" />
                        <x-ui.input wire:model="hiredOn" name="hiredOn" type="date" :label="__('Hired on')" required />
                        <x-ui.input wire:model="leftOn" name="leftOn" type="date" :label="__('Left on (optional)')" />
                        <x-ui.input
                            wire:model="nationalId"
                            name="nationalId"
                            :label="__('National ID (optional)')"
                            :hint="$editingEmployeeId === null
                                ? __('Stored encrypted; only the last four digits are ever displayed.')
                                : __('On file: :mask — type only to replace it.', ['mask' => \App\Domain\People\Models\Employee::query()->find($editingEmployeeId)?->maskedNationalId() ?? '—'])"
                            autocomplete="off"
                        />

                        @if ($this->canSeePay())
                            <x-ui.input wire:model="salary" name="salary" numeric :label="__('Gross salary (optional)')" :prefix="$this->currencyCode" :hint="__('In the company base currency.')" />

                            <div class="grid gap-1.5">
                                <label for="pay-frequency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Paid') }}</label>

                                <select id="pay-frequency" wire:model="payFrequency" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                    @foreach (\App\Domain\People\Data\EmployeeDto::PAY_FREQUENCIES as $frequency)
                                        <option value="{{ $frequency }}">{{ ucfirst($frequency) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    <x-ui.checkbox name="active" wire:model="active" :label="__('On the books')" />
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @if ($editingDriverId === null)
                            <div class="grid gap-1.5">
                                <label for="driver-employee" class="text-xs font-medium text-fg-ink-secondary">{{ __('Employee') }}</label>

                                <select id="driver-employee" wire:model="driverEmployeeId" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                    <option value="">{{ __('Choose an employee') }}</option>
                                    @foreach ($this->driverCandidates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} — {{ $candidate->employee_no }}</option>
                                    @endforeach
                                </select>

                                @if ($this->driverCandidates->isEmpty())
                                    <p class="text-xs text-fg-ink-muted">{{ __('Every active employee already has a driver record. Add the person as an employee first.') }}</p>
                                @endif

                                @error('driverEmployeeId')
                                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            <div class="grid gap-1.5">
                                <span class="text-xs font-medium text-fg-ink-secondary">{{ __('Employee') }}</span>
                                <p class="text-sm text-fg-ink">{{ \App\Domain\People\Models\Driver::query()->find($editingDriverId)?->employee?->name ?? '—' }}</p>
                            </div>
                        @endif

                        <x-ui.input wire:model="licenceCode" name="licenceCode" :label="__('Licence code (optional)')" :hint="__('The class on the card, e.g. C1 or CE.')" />
                        <x-ui.input
                            wire:model="licenceNo"
                            name="licenceNo"
                            :label="__('Licence number (optional)')"
                            :hint="$editingDriverId === null
                                ? __('Stored encrypted; only the last four digits are ever displayed.')
                                : __('On file: :mask — type only to replace it.', ['mask' => \App\Domain\People\Models\Driver::query()->find($editingDriverId)?->maskedLicenceNo() ?? '—'])"
                            autocomplete="off"
                        />
                        <x-ui.input wire:model="licenceExpiresOn" name="licenceExpiresOn" type="date" :label="__('Licence expires on (optional)')" />
                        <x-ui.input wire:model="permitExpiresOn" name="permitExpiresOn" type="date" :label="__('PDP permit expires on (optional)')" :hint="__('Both dates drive the expiry reminders and the dispatch check.')" />
                        <x-ui.input wire:model="fuelEfficiency" name="fuelEfficiency" type="number" step="0.01" numeric :label="__('Fuel baseline, l/100 km (optional)')" />

                        @if ($this->canSeePay())
                            <x-ui.input wire:model="allowance" name="allowance" numeric :label="__('Default trip allowance (optional)')" :prefix="$this->currencyCode" />
                        @endif
                    </div>

                    <x-ui.checkbox name="kioskEnabled" wire:model="kioskEnabled" :label="__('Issue this driver a kiosk token')" />

                    @if ($editingDriverId !== null)
                        @can('documents.upload')
                            <x-ui.text size="sm" color="muted">
                                {{ __('The licence scan attaches to this driver as a document:') }}
                                <x-ui.link :href="route('documents.register', ['subject_type' => 'driver', 'subject_id' => (string) $editingDriverId])">{{ __('register the licence') }}</x-ui.link>
                            </x-ui.text>
                        @endcan
                    @endif
                @endif

                @error('form')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ ($editingEmployeeId ?? $editingDriverId) === null ? __('Save') : __('Update') }}</x-ui.button>

                    @if (($editingEmployeeId ?? $editingDriverId) !== null)
                        <x-ui.button variant="ghost" type="button" wire:click="clearForm">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan
</div>
