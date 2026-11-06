<?php

use App\Domain\Accounting\Actions\StartReconciliation;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\ReconciliationStatus;
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
 * The reconciliation register (Phase 5, slice 5d): every run ever opened for a bank or
 * cash account, newest first, with its statement date and the figures its signer saw. The
 * variance itself is computed on the run's own page — here the list stays honest to what is
 * stored: an open row shows the book snapshot taken at opening and says so; an approved row
 * shows the frozen signature figures.
 *
 * A run can only be opened against a register row linked to the ledger, which is why
 * the account picker below lists linked active rows only: an unlinked account has no book
 * balance, and reconciling "nothing" against a statement would be arithmetic theatre.
 */
new #[Title('Reconciliations')] class extends Component {
    use WithPagination;

    #[Url]
    public string $statusFilter = '';


    public string $bankAccountId = '';

    public string $statementOn = '';

    public string $statementAmount = '';

    public string $startNote = '';

    public function updated(string $property): void
    {
        if ($property === 'statusFilter') {
            $this->resetPage();
            unset($this->runs);
        }
    }

    public function mount(): void
    {
        $this->statementOn = Carbon::now(CurrentCompany::timezone())->toDateString();
    }

    public function openCreate(): void
    {
        $this->reset(["bankAccountId", "statementAmount", "startNote"]);
        $this->statementOn = Carbon::now(CurrentCompany::timezone())->toDateString();
        $this->dispatch('open-start-reconciliation');
    }

    public function closeCreate(): void
    {
        $this->reset(["bankAccountId", "statementAmount", "startNote"]);
        $this->resetValidation();
    }

    public function start(StartReconciliation $action): void
    {
        $this->authorize('start', Reconciliation::class);

        $validated = $this->validate([
            'bankAccountId' => ['required', 'integer', 'exists:bank_accounts,id'],
            'statementOn' => ['required', 'date'],
            'statementAmount' => ['required', 'string', 'max:24'],
            'startNote' => ['nullable', 'string', 'max:500'],
        ]);

        $base = CurrentCompany::model()->base_currency_code;
        $minor = Money::ofMajor($validated['statementAmount'], $base)->minorUnits;

        try {
            $run = $action->execute(
                bankAccountId: (int) $validated['bankAccountId'],
                statementOn: Carbon::parse($validated['statementOn']),
                statementAmountMinor: $minor,
                note: $validated['startNote'] === '' ? null : $validated['startNote'],
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->closeCreate();
        unset($this->runs);
        $this->dispatch('toast', message: __('Reconciliation opened.'), kind: 'success');

        $this->redirectRoute('accounting.reconciliations.show', ['reconciliation' => $run->id], navigate: true);
    }

    public function clearFilters(): void
    {
        $this->reset('statusFilter');
        $this->resetPage();
        unset($this->runs);
    }

    /** @return LengthAwarePaginator<int, Reconciliation> */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return Reconciliation::query()
            ->with('bankAccount')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByRaw('case when status = \'open\' then 0 else 1 end')
            ->orderByDesc('statement_on')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /** @return Collection<int, BankAccount> */
    #[Computed]
    public function linkableAccounts(): Collection
    {
        return BankAccount::query()
            ->where('active', true)
            ->whereNotNull('gl_account_id')
            ->orderBy('name')
            ->get();
    }

    public function money(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minorUnits, $base)->format($base);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Reconciliations') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The bank\'s figure against the book\'s, walked through its reconciling items. A run is approved only when the two agree to the minor unit; a wrong signature is voided and restarted, never edited.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Runs')">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <div class="grid gap-1.5">
                    <label for="status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>
                    <x-ui.select name="statusFilter" id="status-filter" wire:model.live="statusFilter">
                        <option value="">{{ __('All runs') }}</option>
                        @foreach (ReconciliationStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="$statusFilter === ''">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>

            @can('start', App\Domain\Accounting\Models\Reconciliation::class)
                <x-ui.button size="sm" wire:click="openCreate">{{ __('Reconcile an account') }}</x-ui.button>
            @endcan
        </div>

        <x-ui.table :caption="__('Reconciliation runs')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Reference') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Statement date') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Bank statement') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Book') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Open') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->runs as $run)
                    <tr wire:key="rec-{{ $run->id }}">
                        <x-ui.table.td identifier>
                            <a href="{{ route('accounting.reconciliations.show', $run, true) }}" wire:navigate class="text-fg-link hover:underline">{{ $run->number }}</a>
                        </x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $run->bankAccount?->name ?? __('(removed)') }}</x-ui.table.td>
                        <x-ui.table.td>{{ $run->statement_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($run->base_amount) }}</x-ui.table.td>
                        <x-ui.table.td numeric>
                            {{ $this->money($run->book_base_amount) }}
                            @if ($run->status === App\Domain\Accounting\ReconciliationStatus::Open)
                                <span class="block text-xs text-fg-ink-muted">{{ __('at opening') }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$run->status === App\Domain\Accounting\ReconciliationStatus::Approved ? 'success' : ($run->status === App\Domain\Accounting\ReconciliationStatus::Open ? 'warning' : 'neutral')">
                                {{ mb_strtolower($run->status->label()) }}
                            </x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td numeric class="text-fg-ink-muted">
                            {{ $run->started_by === null ? '—' : \App\Models\User::query()->find($run->started_by)?->name ?? '—' }}
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state
                                icon="clipboard-document-list"
                                :title="$this->statusFilter === '' ? __('No reconciliations yet') : __('No runs match this filter')"
                                :description="$this->statusFilter === ''
                                    ? __('Link a register row to its ledger account on the Bank & cash screen, then open the first statement here.')
                                    : __('Clear the filter to see every run.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->runs->hasPages())
            <div class="mt-4 grid gap-3">
                {{ $this->runs->links() }}
                <x-ui.text size="sm" color="muted">
                    {{ __('Showing :first–:last of :total runs.', [
                        'first' => $this->runs->firstItem(),
                        'last' => $this->runs->lastItem(),
                        'total' => $this->runs->total(),
                    ]) }}
                </x-ui.text>
            </div>
        @endif
    </x-ui.card>

    <x-ui.dialog name="start-reconciliation" maxWidth="2xl" :title="__('Reconcile an account')" x-on:dialog-closed="$wire.call('closeCreate')">
            <form class="grid gap-4 p-5" wire:submit="start">
                <x-ui.text size="sm" color="muted">
                    {{ __('Type the bank\'s closing figure exactly as the statement prints it — negative for an overdraft. The book reads itself from the ledger; you reconcile what the two already are.') }}
                </x-ui.text>

                <div class="grid gap-1.5">
                    <label for="bankAccountId" class="text-xs font-medium text-fg-ink-secondary">{{ __('Account') }}</label>
                    <x-ui.select name="bankAccountId" id="bankAccountId" wire:model="bankAccountId">
                        <option value="">{{ __('Choose a linked account') }}</option>
                        @foreach ($this->linkableAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency_code }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('bankAccountId') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="statementOn" name="statementOn" type="date" :label="__('Statement date')" required />
                    <x-ui.input wire:model="statementAmount" name="statementAmount" :label="__('Statement closing balance')" :hint="__('Major units, e.g. 12345.67')" required />
                </div>

                <x-ui.input wire:model="startNote" name="startNote" :label="__('Note (optional)')" />

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" type="submit">{{ __('Open reconciliation') }}</x-ui.button>
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-start-reconciliation')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
    </x-ui.dialog>
</div>
