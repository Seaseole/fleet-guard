<?php

use App\Domain\Accounting\Actions\CreateLoan;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The financing book (LOAN, Phase 5's "loans with instalment splits").
 *
 * Raising a loan here is paper only — the schedule rows are frozen at creation from the terms
 * typed below (equal principal, declining interest, the division remainder on the last row so
 * the plan adds up exactly), and the ledger stays untouched until the disbursement on the
 * loan's own page. The repayment bank must be one the ledger knows: money needs a
 * place the books can see it arrive and leave.
 */
new #[Title('Loans')] class extends Component {
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    public string $purpose = LoanPurpose::Vehicle->value;

    public string $principal = '';

    public string $rate = '';

    public string $frequency = LoanFrequency::Monthly->value;

    public string $firstPaymentOn = '';

    public string $repaymentCount = '12';

    public string $supplierId = '';

    public string $truckId = '';

    public string $bankAccountId = '';

    public string $loanNotes = '';

    public function mount(): void
    {
        $this->firstPaymentOn = Carbon::now(CurrentCompany::timezone())->addMonth()->startOfMonth()->toDateString();
    }

    public function updated(string $property): void
    {
        if ($property === 'statusFilter') {
            $this->resetPage();
            unset($this->loans);
        }
    }

    public function clearForm(): void
    {
        $this->reset(['principal', 'rate', 'supplierId', 'truckId', 'bankAccountId', 'loanNotes']);
        $this->resetValidation();
    }

    public function save(CreateLoan $action): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('loans.manage'), 403);

        $validated = $this->validate([
            'purpose' => ['required', 'in:'.implode(',', LoanPurpose::values())],
            'principal' => ['required', 'string', 'max:24'],
            'rate' => ['required', 'string', 'max:8', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'frequency' => ['required', 'in:'.implode(',', LoanFrequency::values())],
            'firstPaymentOn' => ['required', 'date'],
            'repaymentCount' => ['required', 'integer', 'min:1', 'max:400'],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'truckId' => ['nullable', 'integer', 'exists:trucks,id'],
            'bankAccountId' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'loanNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $base = CurrentCompany::model()->base_currency_code;
        $minor = Money::ofMajor($validated['principal'], $base)->minorUnits;

        try {
            $loan = $action->execute(
                purpose: LoanPurpose::from($validated['purpose']),
                principalMinor: $minor,
                annualRatePercent: $validated['rate'],
                frequency: LoanFrequency::from($validated['frequency']),
                firstPaymentOn: Carbon::parse($validated['firstPaymentOn']),
                repaymentCount: (int) $validated['repaymentCount'],
                supplierId: $validated['supplierId'] === null || $validated['supplierId'] === '' ? null : (int) $validated['supplierId'],
                truckId: $validated['truckId'] === null || $validated['truckId'] === '' ? null : (int) $validated['truckId'],
                bankAccountId: $validated['bankAccountId'] === null || $validated['bankAccountId'] === '' ? null : (int) $validated['bankAccountId'],
                notes: $validated['loanNotes'] === '' ? null : $validated['loanNotes'],
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->clearForm();
        unset($this->loans);
        $this->dispatch('toast', message: __('Loan :ref raised with its schedule frozen.', ['ref' => $loan->reference]), kind: 'success');
        $this->redirectRoute('accounting.loans.show', ['loan' => $loan->id], navigate: true);
    }

    public function clearFilters(): void
    {
        $this->reset('statusFilter');
        $this->resetPage();
        unset($this->loans);
    }

    /** @return LengthAwarePaginator<int, Loan> */
    #[Computed]
    public function loans(): LengthAwarePaginator
    {
        return Loan::query()
            ->with('supplier')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByRaw("case when status in ('disbursed','servicing','arrears','restructured') then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /** @return Collection<int, Supplier> */
    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Truck> */
    #[Computed]
    public function trucks(): Collection
    {
        return Truck::query()->orderBy('registration_no')->get(['id', 'registration_no']);
    }

    /** @return Collection<int, BankAccount> */
    #[Computed]
    public function banks(): Collection
    {
        return BankAccount::query()->where('active', true)->whereNotNull('gl_account_id')->orderBy('name')->get();
    }

    public function money(?int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        if ($minorUnits === null) {
            return '—';
        }

        return Money::ofMinor($minorUnits, $base)->format($base);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Loans') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The financing book: facilities, their frozen amortisation plans, and the payments that read those rows rather than recompute them. Paper first — nothing posts until disbursement.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Raise a facility')">
        @can('loans.manage')
            <form class="mt-3 grid gap-4 sm:grid-cols-[repeat(3,minmax(0,1fr))]" wire:submit="save">
                <div class="grid gap-1.5">
                    <label for="purpose" class="text-xs font-medium text-fg-ink-secondary">{{ __('Purpose') }}</label>
                    <x-ui.select name="purpose" id="purpose" wire:model="purpose">
                        @foreach (LoanPurpose::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input wire:model="principal" name="principal" :label="__('Principal')" :hint="__('Major units, e.g. 350000')" required />
                <x-ui.input wire:model="rate" name="rate" :label="__('Interest rate (% p.a.)')" :hint="__('Stored annual rate; the plan freezes each period.')" required />

                <div class="grid gap-1.5">
                    <label for="frequency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Frequency') }}</label>
                    <x-ui.select name="frequency" id="frequency" wire:model="frequency">
                        @foreach (LoanFrequency::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input wire:model="firstPaymentOn" name="firstPaymentOn" type="date" :label="__('First payment due')" required />
                <x-ui.input wire:model="repaymentCount" name="repaymentCount" type="number" min="1" max="400" :label="__('Number of repayments')" required />

                <div class="grid gap-1.5">
                    <label for="supplierId" class="text-xs font-medium text-fg-ink-secondary">{{ __('Lender (optional)') }}</label>
                    <x-ui.select name="supplierId" id="supplierId" wire:model="supplierId">
                        <option value="">{{ __('Not a registered supplier') }}</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="truckId" class="text-xs font-medium text-fg-ink-secondary">{{ __('Finances which truck (optional)') }}</label>
                    <x-ui.select name="truckId" id="truckId" wire:model="truckId">
                        <option value="">{{ __('Not tied to one truck') }}</option>
                        @foreach ($this->trucks as $truck)
                            <option value="{{ $truck->id }}">{{ $truck->registration_no }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="bankAccountId" class="text-xs font-medium text-fg-ink-secondary">{{ __('Repayment account (optional)') }}</label>
                    <x-ui.select name="bankAccountId" id="bankAccountId" wire:model="bankAccountId">
                        <option value="">{{ __('Choose at disbursement') }}</option>
                        @foreach ($this->banks as $bank)
                            <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input wire:model="loanNotes" name="loanNotes" :label="__('Notes (optional)')" class="sm:col-span-2" />

                <div class="flex items-end">
                    <x-ui.button variant="primary" type="submit">{{ __('Raise loan') }}</x-ui.button>
                </div>
            </form>
        @else
            <x-ui.text size="sm" color="muted">{{ __('Raising facilities needs the loans.manage ability; the register below is read-only for this seat.') }}</x-ui.text>
        @endcan
    </x-ui.card>

    <x-ui.card :title="__('The book')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div class="grid gap-1.5">
                <label for="status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>
                <x-ui.select name="statusFilter" id="status-filter" wire:model.live="statusFilter">
                    <option value="">{{ __('All loans') }}</option>
                    @foreach (LoanLifecycle::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="$statusFilter === ''">{{ __('Clear') }}</x-ui.button>
        </div>

        <x-ui.table :caption="__('Financing facilities')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Reference') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Lender') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Purpose') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Principal') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Rate') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Next plan row') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->loans as $loan)
                    <tr wire:key="loan-{{ $loan->id }}">
                        <x-ui.table.td identifier>
                            <a href="{{ route('accounting.loans.show', $loan, true) }}" wire:navigate class="text-fg-link hover:underline">{{ $loan->reference }}</a>
                        </x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $loan->supplier?->name ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $loan->purpose->label() }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($loan->base_principal) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ rtrim(rtrim($loan->interest_rate, '0'), '.') }}%</x-ui.table.td>
                        <x-ui.table.td>{{ $loan->first_payment_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="in_array($loan->status, [LoanLifecycle::Disbursed, LoanLifecycle::Servicing, LoanLifecycle::Arrears, LoanLifecycle::Restructured], true) ? 'warning' : ($loan->status === LoanLifecycle::Closed ? 'success' : ($loan->status === LoanLifecycle::Defaulted || $loan->status === LoanLifecycle::WrittenOff ? 'danger' : 'neutral'))">
                                {{ mb_strtolower($loan->status->label()) }}
                            </x-ui.badge>
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state
                                icon="banknotes"
                                :title="__('No facilities yet')"
                                :description="__('Raise the truck finance paper above — the ledger sees a loan only when it is disbursed.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->loans->hasPages())
            <div class="mt-4">{{ $this->loans->links() }}</div>
        @endif
    </x-ui.card>
</div>
