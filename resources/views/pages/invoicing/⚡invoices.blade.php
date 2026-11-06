<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateManualInvoice;
use App\Domain\Invoicing\Actions\RunConsolidatedBilling;
use App\Domain\Invoicing\Data\ManualInvoiceDto;
use App\Domain\Invoicing\Data\ManualInvoiceLineDto;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
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
 * The invoice register: what was billed, to whom, and what is still owed.
 *
 * The settlement column is **derived**, not a stored badge: `Invoice::settlementState()` answers from the
 * balance the allocation rows maintain, and `ageingState()` asks `due_on` against today. A register that
 * stored either would be wrong the morning after a payment or a midnight.
 *
 * The consolidated run sits on this screen rather than behind a wizard because running a month is one
 * decision — customer, window — and its result belongs in the list the clerk is already reading. The
 * Action refuses a window with nothing ready, so the panel cannot produce an empty statement.
 *
 * A manual document is raised here too, since phase 4's third door has no other home: the office
 * needs the paper beside the register it will land in. The dialog is a **draft** only — no number, no
 * posting — and the fiscal act belongs to the invoice's own screen. Drafts are in the list on
 * purpose: an office that cannot see its own unfinished paper invents a second place to lose it.
 *
 * Since the register mixes currencies, so the rows print each document's own figures in its own
 * currency while the footer total is the **base** sum. One number that added Pula to Rand would be
 * neither of them.
 *
 * Only `invoices.view` is asked for the list; the run button is gated by `invoices.issue`, the manual
 * draft by `invoices.draft`, and every Action asks again, because a screen is not a permission check.
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

    /** @var array<int, array{description: string, amount: string, kind: string}> */
    public array $manualLines = [['description' => '', 'amount' => '', 'kind' => 'manual']];

    public string $manualCustomerId = '';

    public string $manualCurrency = '';

    public string $manualRate = '';

    public string $manualBase = '';

    public string $manualNote = '';

    public string $manualPo = '';

    public function mount(): void
    {
        $this->runFrom = Carbon::now()->startOfMonth()->toDateString();
        $this->runTo = Carbon::now()->toDateString();
        $this->manualCurrency = CurrentCompany::model()->base_currency_code;
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
            ->when($this->stateFilter === 'draft', fn ($query) => $query
                ->where('status', InvoiceLifecycle::Draft->value))
            ->when($this->stateFilter === 'void', fn ($query) => $query
                ->where('status', InvoiceLifecycle::Void->value))
            ->when($this->kindFilter !== '', fn ($query) => $query->where('kind', $this->kindFilter))
            ->orderByDesc('issue_date')
            ->orderByDesc('number')
            ->paginate(25);
    }

    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->where('active', true)->orderBy('name')->get();
    }

    /** @return Collection<int, Currency> */
    #[Computed]
    public function currencies(): Collection
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * The AR total the register's footer states. It is the **base** sum and the label says so: with
     * corridor documents in the same list, a figure that added a ZAR balance to a Pula one
     * would answer to neither currency and disagree with the trial balance on purpose.
     */
    #[Computed]
    public function outstandingMinor(): int
    {
        return (int) Invoice::query()
            ->where('status', InvoiceLifecycle::Issued->value)
            ->where('balance_amount', '>', 0)
            ->sum('balance_base');
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

    public function openManual(): void
    {
        abort_unless(Gate::allows('invoices.draft'), 403);

        $this->clearManual();

        $this->dispatch('open-manual-form');
    }

    public function addManualLine(): void
    {
        if (count($this->manualLines) >= 20) {
            $this->error = __('Twenty lines is a billing run, not a hand-raised document.');

            return;
        }

        $this->manualLines[] = ['description' => '', 'amount' => '', 'kind' => 'manual'];
    }

    public function removeManualLine(int $index): void
    {
        unset($this->manualLines[$index]);

        if ($this->manualLines === []) {
            $this->manualLines = [['description' => '', 'amount' => '', 'kind' => 'manual']];
        }

        $this->manualLines = array_values($this->manualLines);
    }

    public function clearManual(): void
    {
        $this->reset(['manualCustomerId', 'manualRate', 'manualBase', 'manualNote', 'manualPo', 'error']);

        $this->manualCurrency = CurrentCompany::model()->base_currency_code;
        $this->manualLines = [['description' => '', 'amount' => '', 'kind' => 'manual']];

        $this->resetValidation();
    }

    /**
     * The draft is written and left in the register; issuing it is a separate act on its own screen,
     * because that is where the number is spent and the journal is written. A refusal from the
     * Action is shown rather than pre-empted, so this screen and a script answer with the same sentence.
     */
    public function saveManual(CreateManualInvoice $create): void
    {
        abort_unless(Gate::allows('invoices.draft'), 403);

        $this->error = null;

        $validated = $this->validate([
            'manualCustomerId' => ['required', 'integer'],
            'manualCurrency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'manualRate' => ['nullable', 'string', 'max:20'],
            'manualBase' => ['nullable', 'string', 'max:24'],
            'manualNote' => ['nullable', 'string', 'max:500'],
            'manualPo' => ['nullable', 'string', 'max:60'],
            'manualLines.*.description' => ['required', 'string', 'max:160'],
            'manualLines.*.amount' => ['required', 'string', 'max:24'],
            'manualLines.*.kind' => ['required', 'string', Rule::in(array_column(ManualInvoiceLineDto::KINDS, 'value'))],
        ]);

        $isForeign = $validated['manualCurrency'] !== CurrentCompany::model()->base_currency_code;
        $lines = [];

        foreach ($validated['manualLines'] as $line) {
            $lines[] = new ManualInvoiceLineDto(
                description: $line['description'],
                amountMinor: Money::ofMajor($line['amount'], $validated['manualCurrency'])->minorUnits,
                kind: InvoiceLineKind::from($line['kind']),
            );
        }

        try {
            $invoice = $create(new ManualInvoiceDto(
                customerId: (int) $validated['manualCustomerId'],
                lines: $lines,
                currencyCode: $validated['manualCurrency'],
                fxRate: $isForeign && $validated['manualRate'] !== '' ? $validated['manualRate'] : null,
                baseAmountMinor: $isForeign && $validated['manualBase'] !== ''
                    ? Money::ofMajor($validated['manualBase'], CurrentCompany::model()->base_currency_code)->minorUnits
                    : null,
                fxRateSource: $validated['manualRate'] !== '' ? FxRateSource::Manual->value : FxRateSource::Contract->value,
                note: $validated['manualNote'] ?? null,
                poReference: $validated['manualPo'] ?? null,
            ), auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->clearManual();
        $this->dispatch('close-manual-form');
        unset($this->invoices, $this->outstandingMinor);

        $this->redirectRoute('invoicing.invoices.show', ['invoice' => $invoice->id]);
    }

    public function money(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    /** A row prints the currency the document names, not the one the books are kept in. */
    public function moneyIn(Invoice $invoice, int $minor): string
    {
        $currency = (string) $invoice->currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    public function stateTone(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === InvoiceLifecycle::Draft => 'info',
            $invoice->status === InvoiceLifecycle::Void => 'neutral',
            $invoice->settlementState() === SettlementState::Paid => 'success',
            $invoice->ageingState()->value !== 'current' => 'danger',
            default => 'warning',
        };
    }

    public function stateLabel(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === InvoiceLifecycle::Draft => __('Draft'),
            $invoice->status === InvoiceLifecycle::Void => __('Void'),
            default => $invoice->settlementState()->label(),
        };
    }
}; ?>

<div class="grid gap-6">
    <x-shell.nameplate
        :title="__('Invoices')"
        :stamped="true"
        :description="__('Every document this company has issued, its derived settlement state and the ageing bucket its due date puts it in. Outstanding in :base: :amount.', ['base' => CurrentCompany::model()->base_currency_code, 'amount' => $this->money($this->outstandingMinor)])"
    />

    @if ($error)
        <x-ui.alert tone="danger" :title="__('That document was not raised')">{{ $error }}</x-ui.alert>
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
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:flex-1">
                <x-ui.input wire:model.live.debounce.400ms="search" name="search" :label="__('Search')" :hint="__('Document number or customer')" />

                <div class="grid gap-1.5">
                    <label for="invoice-state-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('State') }}</label>

                    <x-ui.select name="stateFilter" id="invoice-state-filter" wire:model.live="stateFilter">
                        <option value="">{{ __('Everything') }}</option>
                        <option value="open">{{ __('Open') }}</option>
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="draft">{{ __('Drafts') }}</option>
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
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" :disabled="! $this->hasFilters()">
                    {{ __('Clear') }}
                </x-ui.button>

                @can('invoices.draft')
                    <x-ui.button variant="outline" size="sm" wire:click="openManual">{{ __('Raise a manual invoice') }}</x-ui.button>
                @endcan
            </div>
        </div>

        <x-ui.table :caption="__('Invoices')">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('Invoice') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Customer') }}</x-ui.table.th>
                    <x-ui.table.th nowrap hideBelow="lg">{{ __('Issued') }}</x-ui.table.th>
                    <x-ui.table.th nowrap>{{ __('Due') }}</x-ui.table.th>
                    <x-ui.table.th numeric hideBelow="lg">{{ __('Total') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Outstanding') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('State') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($this->invoices as $invoice)
                    <tr wire:key="invoice-{{ $invoice->id }}">
                        <x-ui.table.td>
                            <span class="fg-identifier font-medium text-fg-ink">{{ $invoice->reference() }}</span>
                            <div class="text-xs text-fg-ink-muted">{{ $invoice->kind->label() }} · {{ $invoice->currency_code }}</div>
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $invoice->customer?->name ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td nowrap hideBelow="lg">{{ $invoice->issue_date->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $invoice->due_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td numeric hideBelow="lg">{{ $this->moneyIn($invoice, $invoice->grossMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->moneyIn($invoice, $invoice->balance_amount) }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$this->stateTone($invoice)">{{ $this->stateLabel($invoice) }}</x-ui.badge>

                            @if ($invoice->status === InvoiceLifecycle::Issued && $invoice->balance_amount > 0)
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
                            :description="__('Issue one from a delivered trip, run a consolidated statement, or raise a manual document for a charge no delivery produced.')"
                        />
                    </x-ui.table.td>
                </tr>
            @endforelse
        </x-ui.table>

        <div class="pt-3">
            {{ $this->invoices->links() }}
        </div>
    </x-ui.card>

    {{-- The manual draft is written here and issued on its own screen: Spends the number only at
         the fiscal act, so this dialog can only ever produce an addressable draft. --}}
    @can('invoices.draft')
        <x-ui.dialog name="manual-form" maxWidth="3xl" :title="__('Raise a manual invoice')">
            <form class="grid gap-4 p-5" wire:submit="saveManual">
                <x-ui.text size="sm" color="muted">
                    {{ __('A draft spends no fiscal number and posts nothing. Issue it from the invoice screen when the paper is right; discard it when it is not.') }}
                </x-ui.text>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <label for="manual-customer" class="text-xs font-medium text-fg-ink-secondary">{{ __('Customer') }}</label>

                        <x-ui.select name="manualCustomerId" id="manual-customer" wire:model="manualCustomerId">
                            <option value="">{{ __('Choose a customer') }}</option>
                            @foreach ($this->customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-1.5">
                        <label for="manual-currency" class="text-xs font-medium text-fg-ink-secondary">{{ __('Currency') }}</label>

                        <x-ui.select name="manualCurrency" id="manual-currency" wire:model.live="manualCurrency">
                            @foreach ($this->currencies as $currency)
                                <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                @if ($manualCurrency !== CurrentCompany::model()->base_currency_code)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input
                            name="manualRate"
                            :label="__('Rate — 1 :code in base', ['code' => $manualCurrency])"
                            :hint="__('The rate the quote agreed. Leave empty to use the stored bulletin rate.')"
                            wire:model="manualRate"
                            numeric
                        />

                        <x-ui.input
                            name="manualBase"
                            :label="__('…or the base the charge is worth')"
                            :hint="__('Type this instead of a rate when the office decided the Pula figure (ADR-0004 #6).')"
                            wire:model="manualBase"
                            numeric
                        />
                    </div>
                @endif

                <div class="grid gap-3">
                    <div class="flex items-center justify-between">
                        <x-ui.text size="sm" class="font-medium">{{ __('Lines') }}</x-ui.text>

                        <x-ui.button variant="ghost" size="sm" type="button" wire:click="addManualLine">{{ __('Add a line') }}</x-ui.button>
                    </div>

                    @foreach ($manualLines as $index => $line)
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,3fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" wire:key="manual-line-{{ $index }}">
                            <x-ui.input
                                :name="'manualLines.'.$index.'.description'"
                                :label="__('Description')"
                                :wire:model="'manualLines.'.$index.'.description'"
                            />

                            <x-ui.input
                                :name="'manualLines.'.$index.'.amount'"
                                :label="__('Amount')"
                                :hint="__('Major units')"
                                :wire:model="'manualLines.'.$index.'.amount'"
                                numeric
                            />

                            <div class="grid gap-1.5">
                                <label for="manual-line-kind-{{ $index }}" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>

                                <x-ui.select :name="'manualLines.'.$index.'.kind'" id="manual-line-kind-{{ $index }}" :wire:model="'manualLines.'.$index.'.kind'">
                                    @foreach (ManualInvoiceLineDto::KINDS as $kind)
                                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <x-ui.button variant="ghost" size="sm" type="button" class="self-end" wire:click="removeManualLine({{ $index }})" :disabled="count($manualLines) === 1">
                                {{ __('Remove') }}
                            </x-ui.button>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="manualPo" :label="__('Customer reference (optional)')" wire:model="manualPo" />
                    <x-ui.input name="manualNote" :label="__('Note on the document (optional)')" wire:model="manualNote" />
                </div>

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" type="submit">{{ __('Save draft') }}</x-ui.button>

                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-manual-form')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan
</div>
