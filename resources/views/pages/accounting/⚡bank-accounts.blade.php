<?php

use App\Domain\Accounting\Actions\CreateBankAccount;
use App\Domain\Accounting\Actions\UpdateBankAccount;
use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Data\BankAccountTypes;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Money\Currency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Bank and cash accounts (§3.2 Accounting, §4.2 BANK_ACCOUNT, §9.5 encrypted columns).
 *
 * This screen exists in Phase 0 because the definition of done needs a field somebody can be
 * refused: the list is open to anyone with `bank.view`, the full number only renders through the
 * `seeBankDetail` gate, and every other role sees §9.5's masked column. The masking is a stored
 * column, not a render-time trick, which is why the list still sorts and searches.
 *
 * The account number input is emptied after every save. A form that kept it filled would post the
 * plaintext back on the next edit, and §9.5's promise is that the plaintext lives in one place.
 */
new #[Title('Bank and cash accounts')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('status')]
    public string $statusFilter = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $institution = '';

    public string $branch = '';

    public string $currencyCode = 'BWP';

    public string $type = 'bank';

    public string $accountNumber = '';

    public string $openedOn = '';

    public bool $active = true;

    public string $notes = '';

    /** @var array<int, bool> ids whose full number has been revealed this session */
    public array $revealed = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) {
            $this->resetPage();
            unset($this->accounts);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
        unset($this->accounts);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function mount(): void
    {
        $this->currencyCode = \App\Domain\Kernel\Tenancy\CurrentCompany::model()->base_currency_code;
    }

    public function edit(int $accountId): void
    {
        abort_unless(Gate::allows('bank.manage'), 403);

        /** @var BankAccount $account */
        $account = BankAccount::query()->findOrFail($accountId);

        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->institution = $account->institution;
        $this->branch = (string) $account->branch;
        $this->currencyCode = $account->currency_code;
        $this->type = $account->type;
        $this->openedOn = (string) $account->opened_on?->format('Y-m-d');
        $this->active = $account->active;
        $this->notes = (string) $account->notes;

        // Never populated from the stored ciphertext: §9.5's masked form is what a list shows, and a
        // form field that round-trips `••••4521` would store the mask as the number.
        $this->accountNumber = '';
    }

    public function clearForm(): void
    {
        $this->reset(['editingId', 'name', 'institution', 'branch', 'accountNumber', 'openedOn', 'notes', 'active', 'type']);
    }

    public function save(CreateBankAccount $create, UpdateBankAccount $update): void
    {
        abort_unless(Gate::allows('bank.manage'), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'institution' => ['required', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:120'],
            'currencyCode' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'type' => ['required', 'string', 'in:'.implode(',', BankAccountTypes::values())],
            'accountNumber' => ['nullable', 'string', 'min:4', 'max:40', 'regex:/^[0-9A-Za-z\\- ]+$/'],
            'openedOn' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dto = BankAccountDto::fromArray([
            'name' => $validated['name'],
            'institution' => $validated['institution'],
            'branch' => $validated['branch'] ?? null,
            'currency_code' => $validated['currencyCode'],
            'type' => $validated['type'],
            'account_number' => $validated['accountNumber'] ?? null,
            'opened_on' => $validated['openedOn'] ?? null,
            'active' => $this->active,
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($this->editingId === null) {
            $create($dto);
        } else {
            /** @var BankAccount $account */
            $account = BankAccount::query()->findOrFail($this->editingId);

            abort_unless(Gate::allows('update', $account), 403);

            $update($account, $dto);
        }

        $this->clearForm();
        unset($this->accounts);

        $this->dispatch('toast', message: __('Account saved.'), kind: 'success');
    }

    public function reveal(int $accountId): void
    {
        // The gate is checked here and again inside the model. Two checks for one field looks
        // redundant until the reason is remembered: this one refuses the action, the one in
        // HasMaskedSecret refuses the value, and the second is what protects a report that forgot
        // to ask (§9.4: "an export is an authorised read, not a side door").
        abort_unless(Gate::allows(FieldGate::SeeBankDetail->value), 403);

        $this->revealed[$accountId] = true;
    }

    /** @return LengthAwarePaginator<int, BankAccount> */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return BankAccount::query()
            ->when($this->search !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where(fn ($or) => $or->where('name', 'like', $like)
                    ->orWhere('institution', 'like', $like)
                    ->orWhere('branch', 'like', $like));
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->orderByRaw('case when active then 0 else 1 end')
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return Collection<int, Currency> */
    #[Computed]
    public function currencies(): Collection
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    public function showAccountNumber(): bool
    {
        return BankAccountTypes::from($this->type)->takesAccountNumber();
    }
}; ?>

    <div class="grid gap-6">
        <header class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Bank and cash accounts') }}</x-ui.heading>
            <x-ui.text>
                {{ __('Account numbers are stored encrypted and shown masked (§9.5). The full number is a field gate, not a column.') }}
            </x-ui.text>
        </header>

        <x-ui.card :title="__('Accounts')">
            <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
                <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Name, institution or branch')" />

                <div class="grid gap-1.5">
                    <label for="status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>
                    <select id="status-filter" wire:model.live="statusFilter" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                        <option value="">{{ __('All accounts') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Closed') }}</option>
                    </select>
                </div>

                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>

            <x-ui.table :caption="__('Bank and cash accounts')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Institution') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Number') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Currency') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Type') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __(' ') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->accounts as $account)
                        <tr wire:key="account-{{ $account->id }}">
                            <x-ui.table.td class="font-medium text-fg-ink">{{ $account->name }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">
                                {{ $account->institution }}
                                @if ($account->branch)
                                    <span class="text-fg-ink-muted">· {{ $account->branch }}</span>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="font-mono text-xs">
                                @if ($account->number_masked === null)
                                    <x-ui.text size="xs" color="muted">{{ __('not recorded') }}</x-ui.text>
                                @elseif (isset($revealed[$account->id]))
                                    <x-ui.gated gate="seeBankDetail" :masked="$account->maskedNumber()" :label="__('the full bank account number')">
                                        {{ $account->revealedNumber() }}
                                    </x-ui.gated>
                                @else
                                    <span class="inline-flex items-center gap-2">
                                        {{ $account->maskedNumber() }}

                                        @can('seeBankDetail')
                                            <x-ui.button variant="ghost" size="sm" wire:click="reveal({{ $account->id }})">
                                                {{ __('reveal') }}
                                            </x-ui.button>
                                        @endcan
                                    </span>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td>{{ $account->currency_code }}</x-ui.table.td>
                            <x-ui.table.td>{{ \App\Domain\Accounting\Data\BankAccountTypes::from($account->type)->name }}</x-ui.table.td>
                            <x-ui.table.td>
                                @if ($account->active)
                                    <x-ui.badge tone="success" icon="check">{{ __('active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="neutral">{{ __('closed') }}</x-ui.badge>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                @can('bank.manage')
                                    <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $account->id }})">{{ __('Edit') }}</x-ui.button>
                                @endcan
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state
                                    icon="banknotes"
                                    :title="$this->hasFilters() ? __('No accounts match these filters') : __('No accounts yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the search or status filter to see the rest of the register.')
                                        : __('Open the bank, petty cash and wallet accounts the ledger will move money through.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($this->accounts->isNotEmpty())
                <div class="mt-4 grid gap-3">
                    {{ $this->accounts->links() }}
                    <x-ui.text size="sm" color="muted">
                        {{ __('Showing :first–:last of :total accounts.', [
                            'first' => $this->accounts->firstItem(),
                            'last' => $this->accounts->lastItem(),
                            'total' => $this->accounts->total(),
                        ]) }}
                    </x-ui.text>
                </div>
            @endif
        </x-ui.card>

        @can('bank.manage')
            <x-ui.card
                :title="$editingId === null ? __('Open an account') : __('Edit account')"
                :description="__('The number is encrypted with the column key; the masked form is what every list and export shows.')"
            >
                <form class="grid gap-4" wire:submit="save">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input wire:model="name" name="name" :label="__('Account name')" required />
                        <x-ui.input wire:model="institution" name="institution" :label="__('Institution')" required />
                        <x-ui.input wire:model="branch" name="branch" :label="__('Branch (optional)')" />

                        <div class="grid gap-1.5">
                            <label for="currencyCode" class="text-xs font-medium text-fg-ink-secondary">{{ __('Currency') }}</label>

                            <select id="currencyCode" wire:model="currencyCode" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach ($this->currencies as $currency)
                                    <option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid gap-1.5">
                            <label for="type" class="text-xs font-medium text-fg-ink-secondary">{{ __('Type') }}</label>

                            <select id="type" wire:model.live="type" class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink">
                                @foreach (\App\Domain\Accounting\Data\BankAccountTypes::cases() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input wire:model="openedOn" name="openedOn" type="date" :label="__('Opened (optional)')" />
                    </div>

                    @if ($this->showAccountNumber())
                        <x-ui.input
                            wire:model="accountNumber"
                            name="accountNumber"
                            :label="$editingId === null ? __('Account number') : __('New account number (leave empty to keep)')"
                            :hint="__('Stored encrypted; lists show only the last four digits.')"
                            autocomplete="off"
                        />
                    @endif

                    <x-ui.input wire:model="notes" name="notes" :label="__('Notes (optional)')" />

                    <label class="inline-flex items-center gap-2 text-sm text-fg-ink-secondary">
                        <input type="checkbox" wire:model="active" class="rounded-fg-sm border-fg-line-strong" />
                        {{ __('Active') }}
                    </label>

                    <div class="flex items-center gap-3">
                        <x-ui.button variant="primary" type="submit">{{ $editingId === null ? __('Save account') : __('Update account') }}</x-ui.button>

                        @if ($editingId !== null)
                            <x-ui.button variant="ghost" type="button" wire:click="clearForm">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        @endcan
    </div>
