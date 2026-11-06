<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Data\PaymentDto;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The customer register: the parties an invoice is addressed to.
 *
 * Two fields are gated here and one is not. Payment terms are the customer's *credit* terms,
 * which the design puts behind `seeCustomerTerms` along with agreed rates — a Dispatcher may learn who
 * the customer is without learning whether the company waits 30 days to be paid. The contact block
 * and VAT number are ordinary business facts and stay readable to anyone who may see the register.
 *
 * Writing is `customers.manage` (Owner and Accountant). The form preserves a stored term when
 * the seat cannot read terms, because a hidden input that saved blank would silently move every
 * customer to "cash".
 */
new #[Title('Customers')] class extends Component
{
    use ConfirmsRecentPassword;
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

    public ?string $depositCustomerId = null;

    public string $depositAmount = '';

    public string $depositCurrency = '';

    public string $depositMethod = 'bank';

    public string $depositOn = '';

    public string $depositRate = '';

    public string $depositBase = '';

    public string $depositReference = '';

    public ?string $depositError = null;

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

    public function openCreate(): void
    {
        $this->clearForm();

        $this->dispatch('open-customer-form');
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

        $this->dispatch('open-customer-form');
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
        $this->dispatch('close-customer-form');
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

    /**
     * Money the customer sent with no invoice named — phase 4's deposit, and the reason `2150
     * Unapplied customer cash` exists. Recorded here rather than from an invoice screen,
     * because an overpayment and a prepayment have no document to be born on yet.
     */
    public function openDeposit(int $customerId): void
    {
        abort_unless(Gate::allows('payments.allocate'), 403);

        $this->clearDeposit();

        $this->depositCustomerId = (string) $customerId;
        $this->depositOn = Carbon::now()->toDateString();

        $this->dispatch('open-deposit-form');
    }

    public function clearDeposit(): void
    {
        $this->reset(['depositCustomerId', 'depositAmount', 'depositRate', 'depositBase', 'depositReference', 'depositError']);

        $this->depositMethod = 'bank';
        $this->depositCurrency = CurrentCompany::model()->base_currency_code;

        $this->resetValidation();
    }

    public function takeDeposit(RecordPayment $record): void
    {
        abort_unless(Gate::allows('payments.allocate'), 403);

        // Moving money is a ⑂ re-auth ability, and a deposit is money moving even when no
        // invoice has claimed it yet — the same door a receipt on an invoice stands behind.
        if (! $this->confirmRecentPassword()) {
            return;
        }

        $this->depositError = null;

        $validated = $this->validate([
            'depositCustomerId' => ['required', 'integer'],
            'depositAmount' => ['required', 'string', 'max:24'],
            'depositCurrency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'depositMethod' => ['required', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'depositOn' => ['required', 'date'],
            'depositRate' => ['nullable', 'string', 'max:20'],
            'depositBase' => ['nullable', 'string', 'max:24'],
            'depositReference' => ['nullable', 'string', 'max:80'],
        ], [
            'depositAmount.required' => __('How much arrived? The bank statement says, and the ledger has to be told.'),
        ]);

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $isForeign = $validated['depositCurrency'] !== $baseCurrency;
        $minor = Money::ofMajor($validated['depositAmount'], $validated['depositCurrency'])->minorUnits;

        try {
            $payment = $record(new PaymentDto(
                customerId: (int) $validated['depositCustomerId'],
                amountMinor: $minor,
                method: PaymentMethod::from($validated['depositMethod']),
                receivedOn: Carbon::parse($validated['depositOn']),
                reference: $validated['depositReference'] === '' ? null : $validated['depositReference'],
                // A deposit in a corridor currency needs the same two figures a receipt on an invoice
                // needs; the pile stays in its own currency until an invoice of that currency spends
                // it, and only the base figure reaches the ledger.
                currencyCode: $validated['depositCurrency'],
                fxRate: $isForeign && $validated['depositRate'] !== '' ? $validated['depositRate'] : null,
                baseAmountMinor: $isForeign && $validated['depositBase'] !== ''
                    ? Money::ofMajor($validated['depositBase'], $baseCurrency)->minorUnits
                    : null,
                fxRateSource: $validated['depositRate'] !== '' ? FxRateSource::BankStatement->value : FxRateSource::Manual->value,
            ), auth()->id());
        } catch (\Throwable $refused) {
            $this->depositError = $refused->getMessage();

            return;
        }

        $this->clearDeposit();
        $this->dispatch('close-deposit-form');
        unset($this->customers, $this->onAccountByCustomer);

        $this->dispatch('toast', message: __('Receipt :number banked — :unapplied sits on the account until an invoice claims it.', [
            'number' => $payment->number,
            'unapplied' => Money::ofMinor((int) $payment->unapplied_base, $baseCurrency)->format($baseCurrency),
        ]), kind: 'success');
    }

    /**
     * What each customer has sitting unapplied on their account, in **base** minor units — the figure
     * `2150` carries for them. One grouped read for the page, keyed by customer, so a register
     * of fifty customers is still two queries; a customer absent from the map simply holds nothing.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function onAccountByCustomer(): array
    {
        $rows = Payment::query()
            ->where('status', \App\Domain\Invoicing\PaymentStatus::Recorded->value)
            ->where('unapplied_base', '>', 0)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(unapplied_base) as held')
            ->get();

        $held = [];

        foreach ($rows as $row) {
            $held[(int) $row->customer_id] = (int) $row->held;
        }

        return $held;
    }

    /** @return \Illuminate\Support\Collection<int, Currency> */
    #[Computed]
    public function currencies(): \Illuminate\Support\Collection
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    public function baseMoney(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    public function isDepositForeign(): bool
    {
        return $this->depositCurrency !== CurrentCompany::model()->base_currency_code;
    }

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Customers') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The parties the fleet hauls for. A customer is who a job is quoted for and who an invoice is addressed to; the prices agreed with them live on the route cards.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Customer register')">
        @can('customers.manage')
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <x-ui.text size="sm" color="muted">
                    {{ __('Every job and invoice names one of these. The terms are a price question, so only seats that may read them see or set them.') }}
                </x-ui.text>

                <x-ui.button size="sm" variant="outline" wire:click="openCreate">{{ __('Add a customer') }}</x-ui.button>
            </div>
        @endcan

        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Name, code, contact or email')" />

            <div class="grid gap-1.5">
                <label for="customers-status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>

                <x-ui.select name="statusFilter" id="customers-status-filter" wire:model.live="statusFilter">
                    <option value="">{{ __('Everyone') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Retired') }}</option>
                </x-ui.select>
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
                    <x-ui.table.th numeric hideBelow="md">{{ __('On account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $customer->name }}</span>

                            @if ($customer->code)
                                <div class="fg-identifier text-xs text-fg-ink-muted">{{ $customer->code }}</div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($customer->contact_name || $customer->contact_email || $customer->contact_phone)
                                <div class="text-fg-ink">{{ $customer->contact_name ?: '—' }}</div>

                                @if ($customer->contact_email)
                                    <div class="text-xs text-fg-ink-secondary">{{ $customer->contact_email }}</div>
                                @endif

                                @if ($customer->contact_phone)
                                    <div class="fg-identifier text-xs text-fg-ink-muted">{{ $customer->contact_phone }}</div>
                                @endif
                            @else
                                <span class="text-xs text-fg-ink-muted">{{ __('none on file') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td identifier>{{ $customer->vat_number ?: '—' }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.gated :can="auth()->user()->can('seeCustomerTerms')" :label="__('payment terms')">
                                {{ $customer->paymentTermsLabel() }}
                            </x-ui.gated>
                        </x-ui.table.td>
                        <x-ui.table.td numeric hideBelow="md">
                            @php($held = $this->onAccountByCustomer[$customer->id] ?? 0)

                            @if ($held > 0)
                                <span class="fg-numeric text-fg-ink">{{ $this->baseMoney($held) }}</span>
                                <div class="text-xs text-fg-ink-muted">{{ __('unapplied') }}</div>
                            @else
                                <span class="text-xs text-fg-ink-muted">—</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($customer->active)
                                <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">{{ __('retired') }}</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric>
                            @can('update', $customer)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $customer->id }})">{{ __('Edit') }}</x-ui.button>
                            @endcan

                            {{-- A deposit is banked money with no invoice yet, so its door is the party's
                                 own row rather than a document that does not exist. --}}
                            @can('payments.allocate')
                                <x-ui.button variant="ghost" size="sm" wire:click="openDeposit({{ $customer->id }})">{{ __('Take a deposit') }}</x-ui.button>
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

    {{-- The customer form opens over the register: the page stays the register and the dialog
         carries the draft, whichever row or button opened it. --}}
    @can('customers.manage')
        <x-ui.dialog name="customer-form" maxWidth="2xl" :title="$editingId === null ? __('Add a customer') : __('Edit customer')" x-on:dialog-closed="$wire.call('clearForm')">
            <form class="grid gap-4 p-5" wire:submit="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="name" name="name" :label="__('Name')" required />
                    <x-ui.input wire:model="code" name="code" :label="__('Code (optional)')" :hint="__('A stable reference for statements and imports.')" />
                    <x-ui.input wire:model="vatNumber" name="vatNumber" :label="__('VAT number (optional)')" />

                    <x-ui.input wire:model="contactName" name="contactName" :label="__('Contact person (optional)')" />
                    <x-ui.input wire:model="contactEmail" name="contactEmail" type="email" :label="__('Contact email (optional)')" />
                    <x-ui.input wire:model="contactPhone" name="contactPhone" :label="__('Contact phone (optional)')" />

                    @if ($this->canSeeTerms())
                        <div class="grid gap-1.5">
                            <label for="customer-terms" class="text-xs font-medium text-fg-ink-secondary">{{ __('Payment terms') }}</label>

                            <x-ui.select name="paymentTerms" id="customer-terms" wire:model="paymentTerms">
                                @foreach ($this->termsOptions() as $term)
                                    <option value="{{ $term }}">{{ $this->termsLabel($term) }}</option>
                                @endforeach
                            </x-ui.select>

                        </div>
                    @endif
                </div>

                <x-ui.checkbox name="active" wire:model="active" :label="__('Active — still trading with the fleet')" />

                @error('form')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ $editingId === null ? __('Save') : __('Update') }}</x-ui.button>

                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-customer-form')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan

    {{-- Phase 4's deposit door: the money is banked on the day it arrives, and which invoice it was
         for is answered later by AllocatePayment from the invoice's own screen (the two seams). --}}
    @can('payments.allocate')
        <x-ui.dialog name="deposit-form" maxWidth="2xl" :title="__('Take a deposit')">
            <form class="grid gap-4 p-5" wire:submit="takeDeposit">
                <x-ui.text size="sm" color="muted">
                    {{ __('Money that arrives with no invoice named sits on the account as unapplied cash — it is real money in the bank, and the ledger says so the same day.') }}
                </x-ui.text>

                @if ($depositError)
                    <x-ui.alert tone="danger" :title="__('That receipt was not banked')">{{ $depositError }}</x-ui.alert>
                @endif

                <x-ui.input name="depositAmount" :label="__('Amount received')" :hint="__('Major units, in the currency that arrived')" wire:model="depositAmount" required numeric />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="deposit-currency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Currency') }}</label>

                        <x-ui.select name="depositCurrency" id="deposit-currency" wire:model.live="depositCurrency">
                            @foreach ($this->currencies as $currency)
                                <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="deposit-method" class="text-xs font-medium text-fg-ink-secondary">{{ __('Method') }}</label>

                        <x-ui.select name="depositMethod" id="deposit-method" wire:model="depositMethod">
                            @foreach (PaymentMethod::options() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                @if ($this->isDepositForeign())
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input
                            name="depositRate"
                            :label="__('Rate the bank struck — 1 :code in :base', ['code' => $depositCurrency, 'base' => CurrentCompany::model()->base_currency_code])"
                            :hint="__('What the conversion actually was.')"
                            wire:model="depositRate"
                            numeric
                        />

                        <x-ui.input
                            name="depositBase"
                            :label="__('…or the :base that arrived', ['base' => CurrentCompany::model()->base_currency_code])"
                            :hint="__('Type this instead when only the base figure is on the statement (ADR-0004 #6).')"
                            wire:model="depositBase"
                            numeric
                        />
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input type="date" name="depositOn" :label="__('Received on')" wire:model="depositOn" required />
                    <x-ui.input name="depositReference" :label="__('Reference')" :hint="__('Bank reference or slip number')" wire:model="depositReference" />
                </div>

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Bank the receipt') }}</x-ui.button>

                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-deposit-form')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan
</div>
