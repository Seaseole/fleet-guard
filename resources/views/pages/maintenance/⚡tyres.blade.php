<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\CreateTyre;
use App\Domain\Maintenance\Actions\FitTyre;
use App\Domain\Maintenance\Actions\RemoveTyre;
use App\Domain\Maintenance\Data\TyreDto;
use App\Domain\Maintenance\Models\Tyre;
use App\Domain\Maintenance\Models\TyrePosition;
use App\Domain\Maintenance\TyreSlot;
use App\Domain\Maintenance\TyreStatus;
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
 * Tyres: the casings the company owns, where each one is bolted on right now, and what a
 * stint cost per kilometre once it came off.
 *
 * The register and the fitments are two readings of the same fact. A tyre is created once, with its
 * serial and its price; fitting opens a position row, removing closes it and writes the cost per
 * kilometre the stint actually cost. Nothing is edited after the fact — a casing that moved is a new
 * position, which is why the history can be read a year later.
 *
 * Reading is `maintenance.view` like the rest of the workshop; writing is `tyres.manage` (Owner,
 * Operations Manager, Workshop Manager, Stores Clerk), because a fitter's entry is a workshop entry and
 * a dispatcher has no business putting a tyre on a truck.
 */
new #[Title('Tyres')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    /* The new-tyre dialog. */
    public string $newSerial = '';

    public string $newBrand = '';

    public string $newSize = '';

    public string $newCost = '';

    public string $newTread = '';

    /* The fit dialog. */
    public ?int $fitTyreId = null;

    public string $fitTruckId = '';

    public string $fitSlot = '';

    public string $fitAt = '';

    public string $fitOdometer = '';

    /* The remove dialog. */
    public ?int $removeTyreId = null;

    public string $removeAt = '';

    public string $removeOdometer = '';

    public string $removeStatus = TyreStatus::OnSpares->value;

    public function mount(): void
    {
        $this->fitAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
        $this->removeAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->tyres);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->tyres);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function canManage(): bool
    {
        return Gate::allows('tyres.manage');
    }

    /* ----------------------------------------------------------- new tyre */

    public function openNew(): void
    {
        abort_unless($this->canManage(), 403);

        $this->reset(['newSerial', 'newBrand', 'newSize', 'newCost', 'newTread']);
        $this->resetValidation();

        $this->dispatch('open-tyre-new');
    }

    public function create(CreateTyre $create): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'newSerial' => ['required', 'string', 'max:32'],
            'newBrand' => ['nullable', 'string', 'max:40'],
            'newSize' => ['required', 'string', 'max:20'],
            'newCost' => ['nullable', 'numeric', 'min:0'],
            'newTread' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        try {
            $tyre = $create(new TyreDto(
                serial: $this->newSerial,
                size: $this->newSize,
                brand: $this->newBrand === '' ? null : $this->newBrand,
                purchaseCost: trim($this->newCost) === ''
                    ? null
                    : Money::ofMajor($this->newCost, $this->currencyCode()),
                treadDepthX100: trim($this->newTread) === '' ? null : (int) $this->newTread,
            ));
        } catch (DomainException|InvalidArgumentException $refused) {
            $this->addError('newTyre', $refused->getMessage());

            return;
        }

        $this->reset(['newSerial', 'newBrand', 'newSize', 'newCost', 'newTread']);
        unset($this->tyres);

        $this->dispatch('close-tyre-new');
        $this->dispatch('toast', message: __(':tyre is on the register, on spares.', ['tyre' => $tyre->label()]), kind: 'success');
    }

    /* ------------------------------------------------------------- fitting */

    public function openFit(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $tyre = Tyre::query()->findOrFail($id);

        $this->resetValidation();
        $this->fitTyreId = $tyre->id;
        $this->fitTruckId = '';
        $this->fitSlot = '';
        $this->fitOdometer = '';
        $this->fitAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');

        $this->dispatch('open-tyre-fit');
    }

    /** Pre-fill the odometer with what the truck's card already says, so the common case is one field. */
    public function updatedFitTruckId(string $value): void
    {
        $truck = $value === '' ? null : Truck::query()->find((int) $value);

        $this->fitOdometer = $truck === null ? '' : (string) $truck->odometer_km;
    }

    public function fit(FitTyre $fit): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'fitTruckId' => ['required', 'integer', Rule::exists('trucks', 'id')->where('company_id', CurrentCompany::id())],
            'fitSlot' => ['required', 'string', Rule::in(array_keys(TyreSlot::options()))],
            'fitAt' => ['required', 'date'],
            'fitOdometer' => ['required', 'integer', 'min:1', 'max:9999999'],
        ]);

        $tyre = $this->fitTyreId === null ? null : Tyre::query()->find($this->fitTyreId);
        $truck = Truck::query()->findOrFail((int) $this->fitTruckId);

        if ($tyre === null) {
            $this->addError('fit', __('That tyre is no longer on the register.'));

            return;
        }

        try {
            $position = $fit(
                $tyre,
                $truck,
                TyreSlot::from($this->fitSlot),
                Carbon::parse($this->fitAt, CurrentCompany::timezone()),
                (int) $this->fitOdometer,
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('fit', $refused->getMessage());

            return;
        }

        $this->fitTyreId = null;
        unset($this->tyres, $this->fitted, $this->fitTyre);

        $this->dispatch('close-tyre-fit');
        $this->dispatch('toast', message: __(':tyre fitted to :slot.', [
            'tyre' => $tyre->label(),
            'slot' => $position->position->label(),
        ]), kind: 'success');
    }

    /* ------------------------------------------------------------- removal */

    public function openRemove(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $tyre = Tyre::query()->findOrFail($id);
        $position = $tyre->openPosition;

        if ($position === null) {
            $this->addError('remove', __('That tyre is not fitted to anything.'));

            return;
        }

        $this->resetValidation();
        $this->removeTyreId = $tyre->id;
        $this->removeAt = now(CurrentCompany::timezone())->format('Y-m-d\TH:i');
        $this->removeOdometer = (string) max($position->truck?->odometer_km ?? 0, $position->fitted_odometer_km);
        $this->removeStatus = TyreStatus::OnSpares->value;

        $this->dispatch('open-tyre-remove');
    }

    public function remove(RemoveTyre $remove): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'removeAt' => ['required', 'date'],
            'removeOdometer' => ['required', 'integer', 'min:1', 'max:9999999'],
            'removeStatus' => ['required', Rule::in([
                TyreStatus::OnSpares->value,
                TyreStatus::RetreadDue->value,
                TyreStatus::Scrap->value,
            ])],
        ]);

        $tyre = $this->removeTyreId === null ? null : Tyre::query()->find($this->removeTyreId);

        if ($tyre === null) {
            $this->addError('remove', __('That tyre is no longer on the register.'));

            return;
        }

        try {
            $position = $remove(
                $tyre,
                Carbon::parse($this->removeAt, CurrentCompany::timezone()),
                (int) $this->removeOdometer,
                TyreStatus::from($this->removeStatus),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('remove', $refused->getMessage());

            return;
        }

        $this->removeTyreId = null;
        unset($this->tyres, $this->fitted, $this->removeTyre);

        $this->dispatch('close-tyre-remove');
        $this->dispatch('toast', message: $position->cost_per_km_x10000 === null
            ? __(':tyre removed.', ['tyre' => $tyre->label()])
            : __(':tyre removed — :km km at :rate per km.', [
                'tyre' => $tyre->label(),
                'km' => number_format((int) $position->kilometresRun()),
                'rate' => $position->costPerKmLabel(),
            ]), kind: 'success');
    }

    /* --------------------------------------------------------------- reads */

    /** @return LengthAwarePaginator<int, Tyre> */
    #[Computed]
    public function tyres(): LengthAwarePaginator
    {
        return Tyre::query()
            ->with(['openPosition.truck'])
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('serial', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('size', 'like', $like));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("case when status = 'in_service' then 0 when status = 'on_spares' then 1 else 2 end")
            ->orderBy('serial')
            ->paginate(25);
    }

    /** @return Collection<int, TyrePosition> */
    #[Computed]
    public function fitted(): Collection
    {
        return TyrePosition::query()
            ->with(['tyre', 'truck'])
            ->whereNull('removed_at')
            ->orderBy('truck_id')
            ->orderBy('position')
            ->get();
    }

    #[Computed]
    public function fitTyre(): ?Tyre
    {
        return $this->fitTyreId === null ? null : Tyre::query()->find($this->fitTyreId);
    }

    #[Computed]
    public function removeTyre(): ?Tyre
    {
        return $this->removeTyreId === null ? null : Tyre::query()->with('openPosition.truck')->find($this->removeTyreId);
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()
            ->where('active', true)
            ->where('status', '!=', FleetStatus::Sold->value)
            ->orderBy('fleet_no')
            ->get(['id', 'fleet_no', 'registration_no', 'odometer_km']);
    }

    /** @return array<string, string> */
    public function slots(): array
    {
        return TyreSlot::options();
    }

    /** @return list<TyreStatus> */
    public function statuses(): array
    {
        return TyreStatus::cases();
    }

    /** The statuses a tyre may come off as — never `in_service`, which a fitment writes. */
    public function removalStatuses(): array
    {
        return array_values(array_filter(
            TyreStatus::cases(),
            static fn (TyreStatus $status): bool => $status !== TyreStatus::InService,
        ));
    }

    public function currencyCode(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Tyres') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Every casing the company owns, the slot it is bolted to today, and what each stint cost per kilometre once it came off. A tyre is registered once and fitted as often as it lasts.') }}
            </x-ui.text>
        </div>

        @if ($this->canManage())
            <x-ui.button wire:click="openNew">{{ __('New tyre') }}</x-ui.button>
        @endif
    </header>

    <x-ui.card :title="__('Fitted now')" :description="__('An open fitment is one tyre on one slot. Removing it closes the row and leaves the history behind.')">
        @if ($this->fitted->isEmpty())
            <x-ui.empty-state
                icon="arrow-path"
                :title="__('No tyre is fitted')"
                :description="__('Register the casings, then fit them to their slots — the register will show which wheel each one is on.')"
            />
        @else
            <x-ui.table :caption="__('Open fitments, by truck and slot')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Truck') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Slot') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Tyre') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Fitted') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Odometer') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->fitted as $position)
                        <tr wire:key="fitment-{{ $position->id }}">
                            <x-ui.table.td>
                                @if ($position->truck)
                                    <a href="{{ route('fleet.show', ['truck' => $position->truck->id]) }}" wire:navigate class="text-fg-ink underline-offset-4 hover:text-accent-ink hover:underline">{{ $position->truck->label() }}</a>
                                @else
                                    <span class="text-xs text-fg-ink-muted">{{ __('Truck #:id', ['id' => $position->truck_id]) }}</span>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>{{ $position->position->label() }}</x-ui.table.td>
                            <x-ui.table.td>
                                <span class="font-medium text-fg-ink">{{ $position->tyre?->serial ?? '—' }}</span>
                                <span class="block text-xs text-fg-ink-muted">{{ $position->tyre?->size ?? '' }}</span>
                            </x-ui.table.td>
                            <x-ui.table.td nowrap>{{ $position->fitted_at->format('d M Y') }}</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>{{ number_format($position->fitted_odometer_km) }} km</x-ui.table.td>
                            <x-ui.table.td numeric nowrap>
                                @if ($this->canManage() && $position->tyre !== null)
                                    <x-ui.button variant="ghost" size="sm" wire:click="openRemove({{ $position->tyre->id }})">{{ __('Remove') }}</x-ui.button>
                                @endif
                            </x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Tyre register')" class="min-w-0">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Serial, brand or size')" />

            <div class="grid gap-1.5">
                <label for="tyre-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <x-ui.select name="statusFilter" id="tyre-status-filter" wire:model.live="statusFilter">
                    <option value="">{{ __('Every tyre') }}</option>

                    @foreach ($this->statuses() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('The tyre register, in service first')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Tyre') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Size') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('New tread') }}</x-ui.table.th>
                    <x-ui.table.th numeric hideBelow="lg">{{ __('Cost') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Where') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->tyres as $tyre)
                    <tr wire:key="tyre-{{ $tyre->id }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $tyre->serial }}</span>
                            <span class="block text-xs text-fg-ink-muted">{{ $tyre->brand ?: __('no brand recorded') }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td nowrap hideBelow="lg">{{ $tyre->size }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap>{{ $tyre->treadLabel() }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap hideBelow="lg">
                            {{ $tyre->purchase_cost_base === 0 ? '—' : $tyre->purchaseCost()->format($this->currencyCode()) }}
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$tyre->status->tone()">{{ $tyre->status->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($tyre->openPosition !== null)
                                <span class="text-fg-ink">{{ $tyre->openPosition->truck?->label() ?? __('Truck #:id', ['id' => $tyre->openPosition->truck_id]) }}</span>
                                <span class="block text-xs text-fg-ink-muted">{{ $tyre->openPosition->position->label() }}</span>
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('—') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @if ($this->canManage() && $tyre->status->isFitForService())
                                <x-ui.button variant="ghost" size="sm" wire:click="openFit({{ $tyre->id }})">{{ __('Fit') }}</x-ui.button>
                            @endif

                            @if ($this->canManage() && $tyre->status === App\Domain\Maintenance\TyreStatus::InService)
                                <x-ui.button variant="ghost" size="sm" wire:click="openRemove({{ $tyre->id }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state
                                icon="arrow-path"
                                :title="$this->hasFilters() ? __('No tyres match these filters') : __('No tyres yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search or status filter to see the rest of the register.')
                                    : __('Register a casing with its serial and price, then fit it to a slot. The register will follow it from truck to truck.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->tyres->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->tyres->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($this->canManage())
        <x-ui.dialog name="tyre-new" :title="__('New tyre')">
            <form wire:submit="create" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('A tyre is registered before it is fitted: the serial is what every later fitment, removal and cost-per-kilometre is written against.') }}
                </x-ui.text>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="newSerial" wire:model="newSerial" :label="__('Serial')" :hint="__('The number stamped on the casing.')" required />
                    <x-ui.input name="newBrand" wire:model="newBrand" :label="__('Brand (optional)')" />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input name="newSize" wire:model="newSize" :label="__('Size')" :hint="__('e.g. 315/80R22.5')" required />
                    <x-ui.input name="newCost" wire:model="newCost" numeric :label="__('Cost (:currency, optional)', ['currency' => $this->currencyCode()])" />
                    <x-ui.input name="newTread" wire:model="newTread" numeric :label="__('New tread (0.01 mm, optional)')" :hint="__('15 mm is 1500.')" />
                </div>

                @error('newTyre')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Register it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-tyre-new')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="tyre-fit" :title="__('Fit a tyre')">
            <form wire:submit="fit" class="grid gap-4 p-5">
                @if ($this->fitTyre !== null)
                    <x-ui.text size="sm">
                        {{ $this->fitTyre->label() }} — {{ __('on the spares rack.') }}
                    </x-ui.text>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="fit-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Truck') }}</label>

                        <x-ui.select name="fitTruckId" id="fit-truck" wire:model.live="fitTruckId">
                            <option value="">{{ __('Choose a truck…') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="fit-slot" class="text-xs font-medium text-fg-ink-secondary">{{ __('Slot') }}</label>

                        <x-ui.select name="fitSlot" id="fit-slot" wire:model="fitSlot">
                            <option value="">{{ __('Choose a slot…') }}</option>

                            @foreach ($this->slots() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="fitAt" wire:model="fitAt" type="datetime-local" :label="__('Fitted at')" required />
                    <x-ui.input name="fitOdometer" wire:model="fitOdometer" numeric :label="__('Odometer, km')" required />
                </div>

                @error('fit')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Fit it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-tyre-fit')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="tyre-remove" :title="__('Remove a tyre')">
            <form wire:submit="remove" class="grid gap-4 p-5">
                @if ($this->removeTyre !== null && $this->removeTyre->openPosition !== null)
                    <x-ui.text size="sm">
                        {{ $this->removeTyre->label() }} — {{ __('fitted to :truck, :slot, at :km km.', [
                            'truck' => $this->removeTyre->openPosition->truck?->label() ?? __('a truck'),
                            'slot' => $this->removeTyre->openPosition->position->label(),
                            'km' => number_format($this->removeTyre->openPosition->fitted_odometer_km),
                        ]) }}
                    </x-ui.text>
                @endif

                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input name="removeAt" wire:model="removeAt" type="datetime-local" :label="__('Removed at')" required />
                    <x-ui.input name="removeOdometer" wire:model="removeOdometer" numeric :label="__('Odometer, km')" required />

                    <div class="grid gap-1.5">
                        <label for="remove-status" class="text-xs font-medium text-fg-ink-secondary">{{ __('What became of it') }}</label>

                        <x-ui.select name="removeStatus" id="remove-status" wire:model="removeStatus">
                            @foreach ($this->removalStatuses() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <x-ui.text size="sm" color="muted">
                    {{ __('The distance run and the cost per kilometre are written now, from the two odometer readings and the tyre’s recorded price.') }}
                </x-ui.text>

                @error('remove')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Remove it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-tyre-remove')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
