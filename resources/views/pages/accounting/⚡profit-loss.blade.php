<?php

use App\Domain\Accounting\Data\ProfitAndLoss;
use App\Domain\Accounting\Data\ProfitAndLossLine;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Series\ChartWindow;
use App\Domain\Reporting\Series\MonthlyRevenueAndExpense;
use App\Domain\Reporting\Series\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The profit and loss statement (Phase 5's statement, here because Phase 8's margin
 * reports read its numbers through the same door): the window's net movement on the accounts
 * whose statement axis is profit-and-loss, revenue stated as earned and expenses as
 * spent, nothing else.
 *
 * The axis decides membership, not the code range — "5000–5999 is revenue" is a numbering
 * habit, and an enum is the one fact the chart keeps (the AccountType::statement()). The
 * window is the reader's question, but the figures are the ones frozen at write time:
 * re-asking a past month after an FX or rate change returns the same sheet, because nothing
 * here recomputes a historical amount from a current rate.
 */
new #[Title('Profit and loss')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            // A window cannot end before it starts; the later date answers for both rather
            // than rendering an empty sheet that looks like a bug.
            if ($this->fromDate() > $this->toDate()) {
                $this->to = $this->from;
            }

            unset($this->statement, $this->trend);
        }
    }

    public function fromDate(): Carbon
    {
        $today = $this->businessToday();

        if ($this->from === '') {
            return $today->copy()->startOfMonth();
        }

        try {
            return Carbon::parse($this->from)->startOfDay();
        } catch (\Throwable) {
            return $today->copy()->startOfMonth();
        }
    }

    public function toDate(): Carbon
    {
        $today = $this->businessToday();

        if ($this->to === '') {
            return $today;
        }

        try {
            return Carbon::parse($this->to)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }
    }

    public function businessToday(): Carbon
    {
        return Carbon::now(CurrentCompany::timezone())->startOfDay();
    }

    #[Computed]
    public function statement(): ProfitAndLoss
    {
        return app(LedgerStatements::class)->profitAndLoss($this->fromDate(), $this->toDate());
    }

    /**
     * The same sheet asked once per month — the reading room's figure for this statement
     * (docs/REDESIGN-CONCEPT.md §3). When the window is blank the sheet reads its own month and the
     * trend reads its six, each saying which it is; the door is the same one, so the picture and the
     * signed lines cannot hold two definitions of "earned".
     */
    #[Computed]
    public function trend(): Series
    {
        return app(MonthlyRevenueAndExpense::class)(
            ChartWindow::fromRequest($this->from, $this->to, CarbonImmutable::instance($this->businessToday())),
        );
    }

    public function money(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return $minorUnits === 0
            ? '—'
            : Money::ofMinor($minorUnits, $base)->format($base);
    }

    /** The net figure always prints a number — a zero profit is a fact, not an absence. */
    public function net(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minorUnits, $base)->format($base);
    }

    /** A figure that runs against its column (a contra revenue or a credit note against cost) keeps its sign visible. */
    public function tone(ProfitAndLossLine $line): string
    {
        return $line->amountMinor < 0 ? 'text-warning' : '';
    }

    public function clear(): void
    {
        $this->reset(['from', 'to']);
        unset($this->statement, $this->trend);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Profit and loss') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Net ledger movement on the profit-and-loss accounts from:from through:to. Financial statements read the ledger — this sheet is the journal, not a second source of it.', ['from' => $this->fromDate()->format('d M Y'), 'to' => $this->toDate()->format('d M Y')]) }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Window')">
        <div class="grid gap-4 sm:grid-cols-[repeat(2,minmax(0,1fr))_auto]">
            <x-ui.input
                type="date"
                name="from"
                :label="__('From')"
                :hint="__('Blank means the first of this month.')"
                wire:model.live="from"
                :value="$this->from"
            />

            <x-ui.input
                type="date"
                name="to"
                :label="__('To')"
                :hint="__('Blank means today.')"
                wire:model.live="to"
                :value="$this->to"
            />

            <x-ui.button variant="ghost" size="sm" wire:click="clear" class="self-end" :disabled="$this->from === '' && $this->to === ''">
                {{ __('Clear') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Revenue')">
        <x-ui.table :caption="__('Revenue accounts moved between :from and :to', ['from' => $this->fromDate()->toDateString(), 'to' => $this->toDate()->toDateString()])">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Earned') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->statement->revenueLines as $line)
                    <tr wire:key="pl-r-{{ $line->code }}">
                        <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                        <x-ui.table.td numeric class="{{ $this->tone($line) }}">{{ $this->money($line->amountMinor) }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <x-ui.empty-state
                                icon="banknotes"
                                :title="__('No revenue posted in this window')"
                                :description="__('Invoices post 4000-side revenue at issue; a quiet month legitimately prints nothing here.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-fg-line font-semibold">
                    <x-ui.table.td colspan="2">{{ __('Total revenue') }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->statement->totalRevenueMinor()) }}</x-ui.table.td>
                </tr>
            </tfoot>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Expenses')">
        <x-ui.table :caption="__('Expense accounts moved between :from and :to', ['from' => $this->fromDate()->toDateString(), 'to' => $this->toDate()->toDateString()])">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Spent') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->statement->expenseLines as $line)
                    <tr wire:key="pl-e-{{ $line->code }}">
                        <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                        <x-ui.table.td numeric class="{{ $this->tone($line) }}">{{ $this->money($line->amountMinor) }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <x-ui.empty-state
                                icon="wrench"
                                :title="__('No expenses posted in this window')"
                                :description="__('Fuel, parts, work orders and bills all post 5000-side costs; nothing has been spent on the books yet.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-fg-line font-semibold">
                    <x-ui.table.td colspan="2">{{ __('Total expenses') }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->statement->totalExpenseMinor()) }}</x-ui.table.td>
                </tr>
            </tfoot>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Net profit')">
        <div class="flex flex-wrap items-center gap-4">
            <span class="fg-numeric text-2xl font-semibold {{ $this->statement->netProfitMinor() < 0 ? 'text-danger' : 'text-fg-ink' }}">
                {{ $this->net($this->statement->netProfitMinor()) }}
            </span>
            <x-ui.badge :tone="$this->statement->netProfitMinor() < 0 ? 'danger' : 'success'">
                {{ $this->statement->netProfitMinor() < 0 ? __('Loss') : __('Profit') }}
            </x-ui.badge>
        </div>
        <p class="pt-2 text-xs text-fg-ink-muted">
            {{ __('Revenue :rev · Expenses :exp · window :from → :to', [
                'rev' => $this->money($this->statement->totalRevenueMinor()),
                'exp' => $this->money($this->statement->totalExpenseMinor()),
                'from' => $this->fromDate()->format('d M Y'),
                'to' => $this->toDate()->format('d M Y'),
            ]) }}
        </p>
    </x-ui.card>

    {{-- The month-by-month reading of the very figures the sheets above sum. --}}
    <x-patterns.chart :series="$this->trend" />
</div>
