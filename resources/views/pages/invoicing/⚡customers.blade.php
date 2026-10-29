<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\UpdateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Identity\FieldGate;
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
 * The customer register (§3.2, §4.1): the parties an invoice is addressed to.
 *
 * Two §9.4 fields are gated here and one is not. Payment terms are the customer's *credit* terms,
 * which §9.4 puts behind `seeCustomerTerms` along with agreed rates — a Dispatcher may learn who
 * the customer is without learning whether the company waits 30 days to be paid. The contact block
 * and VAT number are ordinary business facts and stay readable to anyone who may see the register.
 *
 * Writing is `customers.manage` (Owner and Accountant, §9.3). The form preserves a stored term when
 * the seat cannot read terms, because a hidden input that saved blank would silently move every
 * customer to "cash".
 */
new #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $vatNumber = '';

    public string $paymentTerms = '';

    public bool $active = true;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->customers);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->customers);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function edit(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);

        abort_unless(Gate::allows('update', $customer), 403);

        $this->clearForm();

        $this->editingId = $customer->id;
        $this->code = (string) $customer->code;
        $this->name = $customer->name;
        $this->contactName = (string) $customer->contact_name;
        $this->contactEmail = (string) $customer->contact_email;
        $this->contactPhone = (string) $customer->contact_phone;
        $this->vatNumber = (string) $customer->vat_number;
        $this->active = $customer->active;

        // Only when the seat may read terms: the property is part of the Livewire snapshot, so
        // holding a term this seat cannot see would be the leak the gate exists to prevent.
        $this->paymentTerms = $this->canSeeTerms() ? $customer->payment_terms : '';
    }

    public function clearForm(): void
    {
        $this->editingId = null;

        $this->reset(['code', 'name', 'contactName', 'contactEmail', 'contactPhone', 'vatNumber', 'paymentTerms', 'active']);

        $this->resetValidation();
    }

    public function save(CreateCustomer $create, UpdateCustomer $update): void
    {
        $companyId = CurrentCompany::id();
        $ignoring = $this->editingId;
        $seesTerms = $this->canSeeTerms();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('customers', 'name')->where('company_id', $companyId)->ignore($ignoring)],
            'code' => ['nullable', 'string', 'max:24', Rule::unique('customers', 'code')->where('company_id', $companyId)->ignore($ignoring)],
            'contactName' => ['nullable', 'string', 'max:80'],
            'contactEmail' => ['nullable', 'email', 'max:120'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'vatNumber' => ['nullable', 'string', 'max:32'],
            'paymentTerms' => $seesTerms ? ['required', 'string', Rule::in($this->termsOptions())] : ['nullable'],
        ]);

        $existing = $this->editingId === null ? null : Customer::query()->findOrFail($this->editingId);

        if ($existing === null) {
            abort_unless(Gate::allows('create', Customer::class), 403);
        } else {
            abort_unless(Gate::allows('update', $existing), 403);
        }

        try {
            $dto = CustomerDto::fromArray([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'contact_name' => $validated['contactName'] ?? null,
                'contact_email' => $validated['contactEmail'] ?? null,
                'contact_phone' => $validated['contactPhone'] ?? null,
                'vat_number' => $validated['vatNumber'] ?? null,
                // A seat that cannot read terms cannot post a new one either; the stored value is
                // carried forward so an edit of the phone number leaves the credit terms alone.
                'payment_terms' => $seesTerms
                    ? $validated['paymentTerms']
                    : ($existing?->payment_terms ?? PaymentTerms::DEFAULT),
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
        unset($this->customers);

        $this->dispatch('toast', message: __('Saved.'), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, Customer> */
    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        return Customer::query()
            ->when($this->search !== '', function ($query): void {
                $like = $this->like();

                $query->where(fn ($or) => $or->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_email', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * The terms the form offers. The current value is appended when it is not in the list, so
     * editing a customer on `net_45` cannot silently move them to whatever option renders first.
     *
     * @return list<string>
     */
    public function termsOptions(): array
    {
        $options = ['cash', 'net_7', 'net_14', 'net_30', 'net_60'];

        if ($this->paymentTerms !== '' && ! in_array($this->paymentTerms, $options, true)) {
            $options[] = $this->paymentTerms;
        }

        return $options;
    }

    public function termsLabel(string $terms): string
    {
        return PaymentTerms::label($terms);
    }

    public function canSeeTerms(): bool
    {
        return Gate::allows(FieldGate::SeeCustomerTerms->value);
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Customers') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The parties the fleet hauls for. A customer is who a job is quoted for and who an invoice is addressed to; the prices agreed with them live on the route cards.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Customer register')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Name, code, contact or email')" />

            <div class="grid gap-1.5">
                <label for="customers-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <select id="customers-status-filter" wire:model.live="statusFilter" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                    <option value="">{{ __('Everyone') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Retired') }}</option>
                </select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Customers')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Customer') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Contact') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('VAT number') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Payment terms') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $customer->name }}</span>

                            @if ($customer->code)
                                <div class="font-mono text-xs text-fg-ink-muted">{{ $customer->code }}</div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($customer->contact_name || $customer->contact_email || $customer->contact_phone)
                                <div class="text-fg-ink">{{ $customer->contact_name ?: '—' }}</div>

                                @if ($customer->contact_email)
                                    <div class="text-xs text-fg-ink-secondary">{{ $customer->contact_email }}</div>
                                @endif

                                @if ($customer->contact_phone)
                                    <div class="font-mono text-xs text-fg-ink-muted">{{ $customer->contact_phone }}</div>
                                @endif
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('none on file') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td class="font-mono text-xs">{{ $customer->vat_number ?: '—' }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.gated gate="seeCustomerTerms" :label="__('payment terms')">
                                {{ $customer->paymentTermsLabel() }}
                            </x-ui.gated>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($customer->active)
                                <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('retired') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td class="text-right">
                            @can('update', $customer)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $customer->id }})">{{ __('Edit') }}</x-ui.button>
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state
                                icon="building-storefront"
                                :title="$this->hasFilters() ? __('No customers match these filters') : __('No customers yet')"
                                :description="$this->hasFilters()
                                    ? __('Clear the search or status filter to see the rest of the register.')
                                    : __('Add the parties the fleet hauls for; every job and invoice names one.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->customers->isNotEmpty())
            <div class="mt-4 grid gap-3">
                {{ $this->customers->links() }}

                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total customers.', [
                        'first' => $this->customers->firstItem(),
                        'last' => $this->customers->lastItem(),
                        'total' => $this->customers->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>

    @can('customers.manage')
        <x-ui.card :title="$editingId === null ? __('Add a customer') : __('Edit customer')">
            <form class="grid gap-4" wire:submit="save">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.input wire:model="name" name="name" :label="__('Name')" required />
                    <x-ui.input wire:model="code" name="code" :label="__('Code (optional)')" :hint="__('A stable reference for statements and imports.')" />
                    <x-ui.input wire:model="vatNumber" name="vatNumber" :label="__('VAT number (optional)')" />

                    <x-ui.input wire:model="contactName" name="contactName" :label="__('Contact person (optional)')" />
                    <x-ui.input wire:model="contactEmail" name="contactEmail" type="email" :label="__('Contact email (optional)')" />
                    <x-ui.input wire:model="contactPhone" name="contactPhone" :label="__('Contact phone (optional)')" />

                    @if ($this->canSeeTerms())
                        <div class="grid gap-1.5">
                            <label for="customer-terms" class="text-xs font-medium text-fg-ink-secondary">{{ __('Payment terms') }}</label>

                            <select id="customer-terms" wire:model="paymentTerms" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach ($this->termsOptions() as $term)
                                    <option value="{{ $term }}">{{ $this->termsLabel($term) }}</option>
                                @endforeach
                            </select>

                            @error('paymentTerms')
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>

                <x-ui.checkbox name="active" wire:model="active" :label="__('Active — still trading with the fleet')" />

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
