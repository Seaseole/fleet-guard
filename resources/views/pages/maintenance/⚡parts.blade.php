<?php

declare(strict_types=1);

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\CountPartStock;
use App\Domain\Maintenance\Actions\CreatePart;
use App\Domain\Maintenance\Actions\ReceivePartStock;
use App\Domain\Maintenance\Actions\UpdatePart;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Models\Part;
use App\Domain\Maintenance\PartCategory;
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
 * The parts catalogue and the shelf behind it: what the workshop buys, what it fits,
 * and what is actually in stores. Phase 1 shipped the register; slice 3e adds the valued book — a
 * receipt, an issue to a job and a physical count each move the shelf and post where the movement
 * belongs.
 *
 * A part names a truck only when it is specific to one — a body panel or an ECU. Everything else
 * reads "any truck", which is the honest default for filters and brake pads.
 *
 * Writing the catalogue is `parts.manage` (Owner, Workshop Manager, Stores Clerk), receiving
 * and adjusting stock is `parts.adjust` (Owner, Operations Manager, Stores Clerk), and counting is
 * `parts.count` (Owner, Workshop Manager, Stores Clerk). No field gate covers a part's cost: it
 * is a purchasing fact the stores clerk is expected to know, unlike a customer's agreed rate or an
 * employee's salary.
 */
new #[Title('Parts')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    #[Url('category')]
    public string $categoryFilter = '';

    public ?int $editingId = null;

    public string $sku = '';

    public string $name = '';

    public string $category = PartCategory::Other->value;

    public string $unitCost = '';

    public string $currency = '';

    public string $reorderLevel = '';

    public string $truckId = '';

    public bool $active = true;

    /** The part the stock dialogs are about — null when neither is open. */
    public ?int $stockPartId = null;

    public string $stockQuantity = '';

    public string $stockUnitCost = '';

    public string $stockCounted = '';

    public string $stockDate = '';

    public string $stockNote = '';

    public function mount(): void
    {
        $this->currency = $this->currencyCode();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'categoryFilter'], true)) {
            $this->resetPage();
            unset($this->parts);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'categoryFilter']);
        $this->resetPage();
        unset($this->parts);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '' || $this->categoryFilter !== '';
    }

    public function openCreate(): void
    {
        $this->clearForm();

        $this->dispatch('open-part-form');
    }

    public function edit(int $id): void
    {
        $part = Part::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $part), 403);

        $this->clearForm();

        $this->editingId = $part->id;
        $this->sku = $part->sku;
        $this->name = $part->name;
        $this->category = $part->category->value;
        $this->unitCost = $part->unit_cost_minor === 0 ? '' : $part->unitCost()->toBigDecimal()->toString();
        $this->currency = $part->currency_code;
        $this->reorderLevel = $part->reorder_level === null ? '' : (string) $part->reorder_level;
        $this->truckId = $part->truck_id === null ? '' : (string) $part->truck_id;
        $this->active = $part->active;

        $this->dispatch('open-part-form');
    }

    public function clearForm(): void
    {
        $this->editingId = null;

        $this->reset(['sku', 'name', 'category', 'unitCost', 'currency', 'reorderLevel', 'truckId', 'active']);

        $this->category = PartCategory::Other->value;
        $this->currency = $this->currencyCode();

        $this->resetValidation();
    }

    public function save(CreatePart $create, UpdatePart $update): void
    {
        $companyId = CurrentCompany::id();

        $validated = $this->validate([
            'sku' => ['required', 'string', 'max:32', Rule::unique('parts', 'sku')->where('company_id', $companyId)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in(PartCategory::values())],
            'unitCost' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys($this->currencies()))],
            'reorderLevel' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'truckId' => ['nullable', 'integer', Rule::exists('trucks', 'id')->where('company_id', $companyId)],
        ]);

        $existing = $this->editingId === null ? null : Part::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Part::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        try {
            $dto = PartDto::fromArray([
                'sku' => $validated['sku'],
                'name' => $validated['name'],
                'category' => $validated['category'],
                'unit_cost_minor' => ($validated['unitCost'] ?? '') === ''
                    ? 0
                    : Money::ofMajor($validated['unitCost'], $validated['currency'])->minorUnits,
                'currency_code' => $validated['currency'],
                'reorder_level' => $validated['reorderLevel'] ?? null,
                'truck_id' => $validated['truckId'] ?? null,
                'active' => $this->active,
            ]);

            if ($existing === null) {
                $create($dto);
            } else {
                $update($existing, $dto);
            }
        } catch (DomainException|InvalidArgumentException $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->clearForm();
        $this->dispatch('close-part-form');
        unset($this->parts);

        $this->dispatch('toast', message: __('Saved.'), kind: 'success');
    }

    /* ------------------------------------------------------------ stock moves */

    public function canAdjust(): bool
    {
        return Gate::allows('parts.adjust');
    }

    public function canCount(): bool
    {
        return Gate::allows('parts.count');
    }

    #[Computed]
    public function stockPart(): ?Part
    {
        return $this->stockPartId === null ? null : Part::query()->find($this->stockPartId);
    }

    public function openReceive(int $id): void
    {
        abort_unless($this->canAdjust(), 403);

        $part = Part::query()->findOrFail($id);
        $this->prepareStock($part);
        $this->stockUnitCost = $part->unit_cost_minor === 0 ? '' : $part->unitCost()->toBigDecimal()->toString();

        $this->dispatch('open-part-receive');
    }

    public function openCount(int $id): void
    {
        abort_unless($this->canCount(), 403);

        $part = Part::query()->findOrFail($id);
        $this->prepareStock($part);
        $this->stockCounted = $part->stockLabel();

        $this->dispatch('open-part-count');
    }

    public function receive(ReceivePartStock $receive): void
    {
        abort_unless($this->canAdjust(), 403);

        $this->validate([
            'stockQuantity' => ['required', 'string'],
            'stockUnitCost' => ['required', 'string'],
            'stockDate' => ['required', 'date'],
            'stockNote' => ['nullable', 'string', 'max:160'],
        ]);

        $part = $this->stockPart;

        if ($part === null) {
            $this->addError('stock', __('That part is no longer in the catalogue.'));

            return;
        }

        try {
            $receive(
                $part,
                $this->scaledHundredths($this->stockQuantity, __('Quantity')),
                Money::ofMajor($this->stockUnitCost, $this->currencyCode()),
                Carbon::parse($this->stockDate),
                $this->stockNote,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('stock', $refused->getMessage());

            return;
        }

        $this->finishStock(__('Received — the shelf and the stock account moved together.'), 'part-receive');
    }

    public function countStock(CountPartStock $count): void
    {
        abort_unless($this->canCount(), 403);

        $this->validate([
            'stockCounted' => ['required', 'string'],
            'stockDate' => ['required', 'date'],
            'stockNote' => ['nullable', 'string', 'max:160'],
        ]);

        $part = $this->stockPart;

        if ($part === null) {
            $this->addError('stock', __('That part is no longer in the catalogue.'));

            return;
        }

        try {
            $count(
                $part,
                $this->scaledHundredths($this->stockCounted, __('Counted quantity')),
                Carbon::parse($this->stockDate),
                $this->stockNote,
                (int) auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $refused) {
            $this->addError('stock', $refused->getMessage());

            return;
        }

        $this->finishStock(__('Counted — any difference is posted to the stock variance account.'), 'part-count');
    }

    private function prepareStock(Part $part): void
    {
        $this->resetValidation();
        $this->stockPartId = $part->id;
        $this->stockQuantity = '';
        $this->stockUnitCost = '';
        $this->stockCounted = '';
        $this->stockNote = '';
        $this->stockDate = Carbon::now(CurrentCompany::timezone())->toDateString();
        unset($this->stockPart);
    }

    private function finishStock(string $message, string $dialog): void
    {
        $this->stockPartId = null;
        $this->reset(['stockQuantity', 'stockUnitCost', 'stockCounted', 'stockNote']);
        unset($this->parts, $this->stockPart);

        $this->dispatch('close-'.$dialog);
        $this->dispatch('toast', message: $message, kind: 'success');
    }

    /** "45.32" → 4532, without ever building a float. */
    private function scaledHundredths(string $input, string $label): int
    {
        $text = trim(str_replace([' ', ','], ['', '.'], $input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(__(':field must be a number with at most two decimals.', ['field' => $label]));
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /** @return LengthAwarePaginator<int, Part> */
    #[Computed]
    public function parts(): LengthAwarePaginator
    {
        return Part::query()
            ->with('truck')
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()
            ->orderBy('fleet_no')
            ->get(['id', 'fleet_no', 'registration_no']);
    }

    /** @return list<PartCategory> */
    public function categories(): array
    {
        return PartCategory::cases();
    }

    /** @return array<string, Currency> */
    public function currencies(): array
    {
        return Currency::active();
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
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Parts') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The workshop’s catalogue and the shelf behind it: what it buys, what it fits, what it costs and what is actually in stores. A work order takes its parts from here, and every receipt, issue and count moves the stock account with it.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Parts catalogue')">
        @can('parts.manage')
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <x-ui.text size="sm" color="muted">
                    {{ __('A work order takes its parts from here, so the catalogue is what the workshop can actually bill against.') }}
                </x-ui.text>

                <x-ui.button size="sm" variant="outline" wire:click="openCreate">{{ __('Add a part') }}</x-ui.button>
            </div>
        @endcan

        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('SKU or name')" />

            <div class="grid gap-1.5">
                <label for="parts-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <x-ui.select name="statusFilter" id="parts-status-filter" wire:model.live="statusFilter">
                    <option value="">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Retired') }}</option>
                </x-ui.select>
            </div>

            <div class="grid gap-1.5">
                <label for="parts-category-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Category') }}</label>

                <x-ui.select name="categoryFilter" id="parts-category-filter" wire:model.live="categoryFilter">
                    <option value="">{{ __('Every shelf') }}</option>

                    @foreach ($this->categories() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Parts')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Part') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Category') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Fits') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('On hand') }}</x-ui.table.th>
                    <x-ui.table.th numeric hideBelow="lg">{{ __('Unit cost') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Stock value') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->parts as $part)
                    <tr wire:key="part-{{ $part->id }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $part->name }}</span>
                            <div class="fg-identifier text-xs text-fg-ink-muted">{{ $part->sku }}</div>
                        </x-ui.table.td>
                        <x-ui.table.td hideBelow="lg">
                            <x-ui.badge>{{ $part->category->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($part->truck)
                                <span class="text-fg-ink">{{ $part->truck->label() }}</span>
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('any truck') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            <span class="tabular-nums">{{ $part->stockLabel() }}</span>

                            @if ($part->isLowStock())
                                <x-ui.badge tone="warning">{{ __('low') }}</x-ui.badge>
                            @endif

                            @if ($part->reorder_level !== null)
                                <span class="block text-xs text-fg-ink-muted">{{ __('reorder at :level', ['level' => $part->reorder_level]) }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap hideBelow="lg">
                            {{ $part->unit_cost_minor === 0 ? '—' : $part->unitCost()->format($this->currencyCode()) }}
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            {{ $part->stock_value_base === 0 ? '—' : $part->valueMoney()->format($this->currencyCode()) }}
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($part->active)
                                <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('retired') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            @can('update', $part)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $part->id }})">{{ __('Edit') }}</x-ui.button>
                            @endcan

                            @if ($this->canAdjust())
                                <x-ui.button variant="ghost" size="sm" wire:click="openReceive({{ $part->id }})">{{ __('Receive') }}</x-ui.button>
                            @endif

                            @if ($this->canCount())
                                <x-ui.button variant="ghost" size="sm" wire:click="openCount({{ $part->id }})">{{ __('Count') }}</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state
                                icon="cube"
                                :title="$this->hasFilters() ? __('No parts match these filters') : __('No parts yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search, status or category filter to see the rest of the catalogue.')
                                    : __('Add what the workshop keeps on the shelf; a work order picks a repair’s parts from here.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->parts->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->parts->links() }}

                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total parts.', [
                        'first' => $this->parts->firstItem(),
                        'last' => $this->parts->lastItem(),
                        'total' => $this->parts->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>

    {{-- The catalogue form opens over the register, beside the two stock dialogs it shares a row
         with. Closing without saving throws the draft away. --}}
    @can('parts.manage')
        <x-ui.dialog name="part-form" maxWidth="2xl" :title="$editingId === null ? __('Add a part') : __('Edit part')" x-on:dialog-closed="$wire.call('clearForm')">
            <form class="grid gap-4 p-5" wire:submit="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="sku" name="sku" :label="__('SKU')" :hint="__('The stores reference, e.g. FLT-OIL-15W40.')" required />
                    <x-ui.input wire:model="name" name="name" :label="__('Name')" required />

                    <div class="grid gap-1.5">
                        <label for="part-category" class="text-xs font-medium text-fg-ink-secondary">{{ __('Category') }}</label>

                        <x-ui.select name="category" id="part-category" wire:model="category">
                            @foreach ($this->categories() as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>

                    </div>

                    <x-ui.input wire:model="unitCost" name="unitCost" numeric :label="__('Unit cost (optional)')" :hint="__('What one unit is bought for, in the currency chosen.')" />

                    <div class="grid gap-1.5">
                        <label for="part-currency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Currency') }}</label>

                        <x-ui.select name="currency" id="part-currency" wire:model="currency">
                            @foreach ($this->currencies() as $option)
                                <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>

                    </div>

                    <x-ui.input wire:model="reorderLevel" name="reorderLevel" numeric :label="__('Reorder at (optional)')" :hint="__('Units on hand at which the store should reorder.')" />

                    <div class="grid gap-1.5">
                        <label for="part-truck" class="text-xs font-medium text-fg-ink-secondary">{{ __('Fits one truck (optional)') }}</label>

                        <x-ui.select name="truckId" id="part-truck" wire:model="truckId">
                            <option value="">{{ __('Any truck') }}</option>

                            @foreach ($this->trucks as $truck)
                                <option value="{{ $truck->id }}">{{ $truck->label() }}</option>
                            @endforeach
                        </x-ui.select>

                    </div>
                </div>

                <x-ui.checkbox name="active" wire:model="active" :label="__('Active — still stocked or orderable')" />

                @error('form')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ $editingId === null ? __('Save') : __('Update') }}</x-ui.button>

                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-part-form')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan

    @if ($this->canAdjust())
        <x-ui.dialog name="part-receive" :title="__('Receive stock')">
            <form wire:submit="receive" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('A receipt puts parts on the shelf and posts the same value to the stock account, with the supplier as the creditor.') }}
                </x-ui.text>

                @if ($this->stockPart !== null)
                    <x-ui.text size="sm">
                        {{ $this->stockPart->label() }} — {{ __('on hand :qty, at an average of :average', [
                            'qty' => $this->stockPart->stockLabel(),
                            'average' => $this->stockPart->averageMoney()->format($this->currencyCode()),
                        ]) }}
                    </x-ui.text>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="stockQuantity" wire:model="stockQuantity" numeric :label="__('Quantity')" required />
                    <x-ui.input name="stockUnitCost" wire:model="stockUnitCost" numeric :label="__('Unit cost (:currency)', ['currency' => $this->currencyCode()])" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="stockDate" wire:model="stockDate" type="date" :label="__('Received on')" required />
                    <x-ui.input name="stockNote" wire:model="stockNote" :label="__('Note (optional)')" :hint="__('The invoice number, or who delivered it.')" />
                </div>

                @error('stock')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Receive it') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-part-receive')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif

    @if ($this->canCount())
        <x-ui.dialog name="part-count" :title="__('Count the shelf')">
            <form wire:submit="countStock" class="grid gap-4 p-5">
                <x-ui.text size="sm" color="muted">
                    {{ __('The count is compared with the book; the difference is valued at the shelf’s average and posted to the stock variance account. A count that agrees is still recorded.') }}
                </x-ui.text>

                @if ($this->stockPart !== null)
                    <x-ui.text size="sm">
                        {{ $this->stockPart->label() }} — {{ __('the book says :qty', ['qty' => $this->stockPart->stockLabel()]) }}
                    </x-ui.text>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input name="stockCounted" wire:model="stockCounted" numeric :label="__('Counted quantity')" required />
                    <x-ui.input name="stockDate" wire:model="stockDate" type="date" :label="__('Counted on')" required />
                </div>

                <x-ui.input name="stockNote" wire:model="stockNote" :label="__('Note (optional)')" :hint="__('What the shelf looked like, or who counted it.')" />

                @error('stock')
                    <x-ui.alert tone="danger" :title="__('Refused')">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Record the count') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-part-count')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
