<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\Actions\UpdateSupplier;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\SupplierCategory;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The supplier register (§3.2, §4.2): who bills the fleet.
 *
 * Its own screen rather than a tab on the accounting journal because `suppliers.view` is held by
 * the Workshop Manager and the Stores Clerk, who do not hold `accounting.view` (§9.3). The category
 * is §4.2's bill vocabulary, so Phase 5 can default a bill's expense account from the supplier
 * instead of asking the clerk every time.
 *
 * Nothing here is gated: §9.4 names five gates and supplier terms are not one of them. Writing is
 * `suppliers.manage` — the Owner and the Accountant.
 */
new #[Title('Suppliers')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    #[Url('category')]
    public string $categoryFilter = '';

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $category = 'other';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $vatNumber = '';

    public string $paymentTerms = PaymentTerms::DEFAULT;

    public bool $active = true;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'categoryFilter'], true)) {
            $this->resetPage();
            unset($this->suppliers);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'categoryFilter']);
        $this->resetPage();
        unset($this->suppliers);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '' || $this->categoryFilter !== '';
    }

    public function edit(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $supplier), 403);

        $this->clearForm();

        $this->editingId = $supplier->id;
        $this->code = (string) $supplier->code;
        $this->name = $supplier->name;
        $this->category = $supplier->category->value;
        $this->contactName = (string) $supplier->contact_name;
        $this->contactEmail = (string) $supplier->contact_email;
        $this->contactPhone = (string) $supplier->contact_phone;
        $this->vatNumber = (string) $supplier->vat_number;
        $this->paymentTerms = $supplier->payment_terms;
        $this->active = $supplier->active;
    }

    public function clearForm(): void
    {
        $this->editingId = null;

        $this->reset([
            'code', 'name', 'category', 'contactName', 'contactEmail', 'contactPhone',
            'vatNumber', 'paymentTerms', 'active',
        ]);

        $this->category = SupplierCategory::Other->value;
        $this->paymentTerms = PaymentTerms::DEFAULT;

        $this->resetValidation();
    }

    public function save(CreateSupplier $create, UpdateSupplier $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingId;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('suppliers', 'name')->where('company_id', $companyId)->ignore($ignoring)],
            'code' => ['nullable', 'string', 'max:24', Rule::unique('suppliers', 'code')->where('company_id', $companyId)->ignore($ignoring)],
            'category' => ['required', 'string', Rule::in(SupplierCategory::values())],
            'contactName' => ['nullable', 'string', 'max:80'],
            'contactEmail' => ['nullable', 'email', 'max:120'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'vatNumber' => ['nullable', 'string', 'max:32'],
            'paymentTerms' => ['required', 'string', Rule::in($this->termsOptions())],
        ]);

        $existing = $this->editingId === null ? null : Supplier::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Supplier::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        try {
            $dto = SupplierDto::fromArray([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'category' => $validated['category'],
                'contact_name' => $validated['contactName'] ?? null,
                'contact_email' => $validated['contactEmail'] ?? null,
                'contact_phone' => $validated['contactPhone'] ?? null,
                'vat_number' => $validated['vatNumber'] ?? null,
                'payment_terms' => $validated['paymentTerms'],
                'active' => $this->active,
            ]);

            if ($existing === null) {
                $create($dto);
            } else {
                $update($existing, $dto);
            }
        } catch (InvalidArgumentException $refused) {
            $this->addError('form', $refused->getMessage());

            return;
        }

        $this->clearForm();
        unset($this->suppliers);

        $this->dispatch('toast', message: __('Saved.'), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, Supplier> */
    #[Computed]
    public function suppliers(): LengthAwarePaginator
    {
        return Supplier::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_email', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return list<SupplierCategory> */
    public function categories(): array
    {
        return SupplierCategory::cases();
    }

    /**
     * @return list<string>
     */
    public function termsOptions(): array
    {
        $options = ['cash', 'net_7', 'net_14', 'net_30', 'net_60'];

        if (! in_array($this->paymentTerms, $options, true)) {
            $options[] = $this->paymentTerms;
        }

        return $options;
    }

    public function termsLabel(string $terms): string
    {
        return PaymentTerms::label($terms);
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Suppliers') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Fuel stations, parts shops, garages and insurers the fleet buys from. The category is what most bills from a supplier will be, so the expense lands on the right account by default.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Supplier register')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Name, code, contact or email')" />

            <div class="grid gap-1.5">
                <label for="suppliers-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <x-ui.select name="statusFilter" id="suppliers-status-filter" wire:model.live="statusFilter">
                    <option value="">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Retired') }}</option>
                </x-ui.select>
            </div>

            <div class="grid gap-1.5">
                <label for="suppliers-category-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Category') }}</label>

                <x-ui.select name="categoryFilter" id="suppliers-category-filter" wire:model.live="categoryFilter">
                    <option value="">{{ __('Every category') }}</option>

                    @foreach ($this->categories() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Suppliers')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Supplier') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Category') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Contact') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Payment terms') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->suppliers as $supplier)
                    <tr wire:key="supplier-{{ $supplier->id }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $supplier->name }}</span>

                            @if ($supplier->code)
                                <div class="fg-identifier text-xs text-fg-ink-muted">{{ $supplier->code }}</div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$supplier->category->tone()">{{ $supplier->category->label() }}</x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($supplier->contact_name || $supplier->contact_email || $supplier->contact_phone)
                                <div class="text-fg-ink">{{ $supplier->contact_name ?: '—' }}</div>

                                @if ($supplier->contact_email)
                                    <div class="text-xs text-fg-ink-secondary">{{ $supplier->contact_email }}</div>
                                @endif

                                @if ($supplier->contact_phone)
                                    <div class="fg-identifier text-xs text-fg-ink-muted">{{ $supplier->contact_phone }}</div>
                                @endif
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('none on file') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $supplier->paymentTermsLabel() }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($supplier->active)
                                <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('retired') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric>
                            @can('update', $supplier)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $supplier->id }})">{{ __('Edit') }}</x-ui.button>
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state
                                icon="scale"
                                :title="$this->hasFilters() ? __('No suppliers match these filters') : __('No suppliers yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search, status or category filter to see the rest of the register.')
                                    : __('Add the suppliers the fleet buys from; expenses and bills name one.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->suppliers->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->suppliers->links() }}

                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total suppliers.', [
                        'first' => $this->suppliers->firstItem(),
                        'last' => $this->suppliers->lastItem(),
                        'total' => $this->suppliers->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>

    @can('suppliers.manage')
        <x-ui.card :title="$editingId === null ? __('Add a supplier') : __('Edit supplier')">
            <form class="grid gap-4" wire:submit="save">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.input wire:model="name" name="name" :label="__('Name')" required />
                    <x-ui.input wire:model="code" name="code" :label="__('Code (optional)')" :hint="__('A stable reference for bills and imports.')" />

                    <div class="grid gap-1.5">
                        <label for="supplier-category" class="text-xs font-medium text-fg-ink-secondary">{{ __('Category') }}</label>

                        <x-ui.select name="category" id="supplier-category" wire:model="category">
                            @foreach ($this->categories() as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>

                    </div>

                    <x-ui.input wire:model="contactName" name="contactName" :label="__('Contact person (optional)')" />
                    <x-ui.input wire:model="contactEmail" name="contactEmail" type="email" :label="__('Contact email (optional)')" />
                    <x-ui.input wire:model="contactPhone" name="contactPhone" :label="__('Contact phone (optional)')" />
                    <x-ui.input wire:model="vatNumber" name="vatNumber" :label="__('VAT number (optional)')" />

                    <div class="grid gap-1.5">
                        <label for="supplier-terms" class="text-xs font-medium text-fg-ink-secondary">{{ __('Payment terms') }}</label>

                        <x-ui.select name="paymentTerms" id="supplier-terms" wire:model="paymentTerms">
                            @foreach ($this->termsOptions() as $term)
                                <option value="{{ $term }}">{{ $this->termsLabel($term) }}</option>
                            @endforeach
                        </x-ui.select>

                    </div>
                </div>

                <x-ui.checkbox name="active" wire:model="active" :label="__('Active — still supplying the fleet')" />

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
