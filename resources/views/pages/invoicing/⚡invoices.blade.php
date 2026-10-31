<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RunConsolidatedBilling;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The invoice register (§3.2, §10.4): what was billed, to whom, and what is still owed.
 *
 * The settlement column is **derived**, not a stored badge: `Invoice::settlementState()` answers from the
 * balance the allocation rows maintain, and `ageingState()` asks `due_on` against today. A register that
 * stored either would be wrong the morning after a payment or a midnight.
 *
 * The consolidated run sits on this screen rather than behind a wizard because running a month is one
 * decision — customer, window — and its result belongs in the list the clerk is already reading. The
 * Action refuses a window with nothing ready, so the panel cannot produce an empty statement.
 *
 * Only `invoices.view` is asked for the list; the run button is gated by `invoices.issue` and the Action
 * asks again, because a screen is not a permission check (§9.2).
 */
new #[Title('Invoices')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url('state')]
    public string $stateFilter = '';

    #[Url('kind')]
    public string $kindFilter = '';

    public string $runCustomerId = '';

    public string $runFrom = '';

    public string $runTo = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->runFrom = Carbon::now()->startOfMonth()->toDateString();
        $this->runTo = Carbon::now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stateFilter', 'kindFilter'], true)) {
            $this->resetPage();
            unset($this->invoices);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'stateFilter', 'kindFilter']);
        $this->resetPage();
        unset($this->invoices);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->stateFilter !== '' || $this->kindFilter !== '';
    }

    /**
     * One row per document, newest first. A paid invoice stays in the register — the list is a record of
     * what was issued, not a work queue — but the state filter is how a clerk asks for the queue.
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return Invoice::query()
            ->with('customer')
            ->when($this->search !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('number', 'like', $like)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like)));
            })
            ->when($this->stateFilter === 'open', fn ($query) => $query
                ->where('status', InvoiceLifecycle::Issued->value)
                ->where('balance_amount', '>', 0))
            ->when($this->stateFilter === 'paid', fn ($query) => $query
                ->where('status', InvoiceLifecycle::Issued->value)
                ->where('balance_amount', 0))
            ->when($this->stateFilter === 'void', fn ($query) => $query
                ->where('status', InvoiceLifecycle::Void->value))
            ->when($this->kindFilter !== '', fn ($query) => $query->where('kind', $this->kindFilter))
            ->orderByDesc('issue_date')
            ->orderByDesc('number')
            ->paginate(25);
    }

    #[Computed]
    public function customers(): \Illuminate\Support\Collection
    {
        return Customer::query()->where('active', true)->orderBy('name')->get();
    }

    /** The AR total the register's footer states, so the screen and the ageing report agree by construction. */
    #[Computed]
    public function outstandingMinor(): int
    {
        return (int) Invoice::query()
            ->where('status', InvoiceLifecycle::Issued->value)
            ->where('balance_amount', '>', 0)
            ->sum('balance_amount');
    }

    public function runConsolidated(RunConsolidatedBilling $run): void
    {
        $this->error = null;

        if (! Gate::allows('invoices.issue')) {
            $this->error = __('Running a billing statement needs the invoices.issue ability.');

            return;
        }

        $validated = $this->validate([
            'runCustomerId' => ['required', 'integer'],
            'runFrom' => ['required', 'date'],
            'runTo' => ['required', 'date', 'after_or_equal:runFrom'],
        ]);

        try {
            $invoice = $run(
                (int) $validated['runCustomerId'],
                Carbon::parse($validated['runFrom']),
                Carbon::parse($validated['runTo']),
                auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->invoices, $this->outstandingMinor);

        $this->redirectRoute('invoicing.invoices.show', ['invoice' => $invoice->id]);
    }

    public function money(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    public function stateTone(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === InvoiceLifecycle::Void => 'neutral',
            $invoice->settlementState() === SettlementState::Paid => 'success',
            $invoice->ageingState()->value !== 'current' => 'danger',
            default => 'warning',
        };
    }

    public function stateLabel(Invoice $invoice): string
    {
        return $invoice->status === InvoiceLifecycle::Void
            ? __('Void')
            : $invoice->settlementState()->label();
    }
}; ?>

<div class="grid gap-6">
    <header class="grid gap-1">
        <x-ui.heading size="xl" level="1">{{ __('Invoices') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Every document this company has issued, its derived settlement state and the ageing bucket its due date puts it in. Outstanding: :amount.', ['amount' => $this->money($this->outstandingMinor)]) }}
        </x-ui.text>
    </header>

    @if ($error)
        <x-ui.alert tone="danger" :title="__('That statement was not issued')">{{ $error }}</x-ui.alert>
    @endif

    @if (session('status'))
        <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
    @endif

    @can('invoices.issue')
        <x-ui.card :title="__('Run a consolidated statement')" :description="__('Bundles every ready charge the customer earned in the window into one document. A retried run returns the statement it already issued.')">
            <form wire:submit="runConsolidated" class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                <div class="grid gap-1.5">
                    <label for="run-customer" class="text-xs font-medium text-fg-ink-secondary">{{ __('Customer') }}</label>

                    <x-ui.select name="runCustomerId" id="run-customer" wire:model="runCustomerId">
                        <option value="">{{ __('Choose a customer') }}</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input type="date" name="runFrom" :label="__('From')" wire:model="runFrom" />
                <x-ui.input type="date" name="runTo" :label="__('To')" wire:model="runTo" />

                <x-ui.button type="submit" variant="primary" class="sm:mb-px">{{ __('Run statement') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card :title="__('Register')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Document number or customer')" />

            <div class="grid gap-1.5">
                <label for="invoice-state-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('State') }}</label>

                <x-ui.select name="stateFilter" id="invoice-state-filter" wire:model.live="stateFilter">
                    <option value="">{{ __('Everything') }}</option>
                    <option value="open">{{ __('Open') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                    <option value="void">{{ __('Void') }}</option>
                </x-ui.select>
            </div>

            <div class="grid gap-1.5">
                <label for="invoice-kind-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                <x-ui.select name="kindFilter" id="invoice-kind-filter" wire:model.live="kindFilter">
                    <option value="">{{ __('All kinds') }}</option>
                    @foreach (InvoiceKind::cases() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                {{ __('Clear') }}
            </x-ui.button>
        </div>

        <x-ui.table :caption="__('Invoices')">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('Invoice') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Customer') }}</x-ui.table.th>
                    <x-ui.table.th nowrap>{{ __('Issued') }}</x-ui.table.th>
                    <x-ui.table.th nowrap>{{ __('Due') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Outstanding') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($this->invoices as $invoice)
                    <tr wire:key="invoice-{{ $invoice->id }}">
                        <x-ui.table.td>
                            <span class="fg-identifier font-medium text-fg-ink">{{ $invoice->reference() }}</span>
                            <div class="text-xs text-fg-ink-muted">{{ $invoice->kind->label() }}</div>
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $invoice->customer?->name ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $invoice->issue_date->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $invoice->due_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($invoice->grossMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($invoice->balance_amount) }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$this->stateTone($invoice)">{{ $this->stateLabel($invoice) }}</x-ui.badge>

                            @if ($invoice->status !== InvoiceLifecycle::Void && $invoice->balance_amount > 0)
                                <div class="text-xs text-fg-ink-muted">{{ $invoice->ageingState()->label() }}</div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            <x-ui.button
                                variant="ghost"
                                size="sm"
                                :href="route('invoicing.invoices.show', ['invoice' => $invoice->id])"
                                wire:navigate
                            >{{ __('Open') }}</x-ui.button>
                        </x-ui.table.td>
                    </tr>
            @empty
                <tr>
                    <x-ui.table.td colspan="8">
                        <x-ui.empty-state
                            :title="__('No invoices match')"
                            :description="__('Issue one from a delivered trip, or run a consolidated statement for a customer with ready charges.')"
                        />
                    </x-ui.table.td>
                </tr>
            @endforelse
        </x-ui.table>

        <div class="pt-3">
            {{ $this->invoices->links() }}
        </div>
    </x-ui.card>
</div>
