<?php

declare(strict_types=1);

use App\Domain\Invoicing\Data\AgeingRow;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Receivables ageing (§10.4, §10.6): the AR figure split by how late it is.
 *
 * The buckets are computed from `due_on` against the company's today when this page renders — never read
 * from a stored column, because a stored bucket is wrong every midnight (§10.4). The page therefore has no
 * write path at all: chasing a customer is an email or a phone call, and the statement link hands them the
 * document that shows what they owe.
 *
 * Rows are ordered by money owed, and the footer totals are the same sum the register's outstanding figure
 * shows, because both ask the invoices table rather than a cached number.
 */
new #[Title('Ageing')] class extends Component {
    public string $statementFrom = '';

    public string $statementTo = '';

    public function mount(): void
    {
        $this->statementFrom = Carbon::now()->startOfMonth()->toDateString();
        $this->statementTo = Carbon::now()->toDateString();
    }

    /** @return list<AgeingRow> */
    #[Computed]
    public function rows(): array
    {
        return (new AgeingReport)();
    }

    /** @return array{current: int, d1_30: int, d31_60: int, d61_90: int, d90_plus: int, total: int, overdue: int, invoices: int, customers: int} */
    #[Computed]
    public function totals(): array
    {
        return AgeingReport::totals($this->rows);
    }

    public function money(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }
}; ?>

<div class="grid gap-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ __('Ageing') }}</x-ui.heading>
            <x-ui.text>
                {{ __('What customers owe, split by how far past its due date each invoice is. :customers customers, :invoices open invoices, :overdue of it overdue.', [
                    'customers' => $this->totals['customers'],
                    'invoices' => $this->totals['invoices'],
                    'overdue' => $this->money($this->totals['overdue']),
                ]) }}
            </x-ui.text>
        </div>

        <x-ui.button variant="outline" :href="route('invoicing.index')" wire:navigate>{{ __('Invoice register') }}</x-ui.button>
    </header>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @foreach ([
            ['label' => __('Not yet due'), 'minor' => $this->totals['current']],
            ['label' => __('1–30 days'), 'minor' => $this->totals['d1_30']],
            ['label' => __('31–60 days'), 'minor' => $this->totals['d31_60']],
            ['label' => __('61–90 days'), 'minor' => $this->totals['d61_90']],
            ['label' => __('Over 90 days'), 'minor' => $this->totals['d90_plus']],
            ['label' => __('Total AR'), 'minor' => $this->totals['total']],
        ] as $bucket)
            <x-ui.metric-card :label="$bucket['label']" :value="$this->money($bucket['minor'])" />
        @endforeach
    </div>

    <x-ui.card :title="__('By customer')" :description="__('A statement is built for the window below. Outstanding on the statement is everything still owed today, not only what this window invoiced.')">
        <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <x-ui.input type="date" name="statementFrom" :label="__('Statement from')" wire:model.live="statementFrom" />
            <x-ui.input type="date" name="statementTo" :label="__('Statement to')" wire:model.live="statementTo" />
        </div>

        <x-ui.table :caption="__('Receivables ageing by customer')">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('Customer') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Not yet due') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('1–30') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('31–60') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('61–90') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('90+') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Statement') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($this->rows as $row)
                    <tr wire:key="ageing-{{ $row->customerId }}">
                        <x-ui.table.td>
                            <span class="font-medium text-fg-ink">{{ $row->customerName }}</span>
                            <div class="text-xs text-fg-ink-muted">
                                {{ trans_choice(':count invoice|:count invoices', $row->invoiceCount, ['count' => $row->invoiceCount]) }}
                                @if ($row->oldestDueOn)
                                    · {{ __('oldest due :date', ['date' => $row->oldestDueOn->format('d M Y')]) }}
                                @endif
                            </div>
                        </x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->currentMinor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->days1to30Minor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->days31to60Minor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->days61to90Minor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->over90Minor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->totalMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric nowrap>
                            <x-ui.button
                                variant="ghost"
                                size="sm"
                                :href="route('invoicing.statements.pdf', ['customer' => $row->customerId, 'from' => $this->statementFrom, 'to' => $this->statementTo])"
                            >{{ __('PDF') }}</x-ui.button>
                        </x-ui.table.td>
                    </tr>
            @empty
                <tr>
                    <x-ui.table.td colspan="8">
                        <x-ui.empty-state
                            :title="__('Nothing is outstanding')"
                            :description="__('Every issued invoice is fully settled. The ageing report fills as soon as one is not.')"
                        />
                    </x-ui.table.td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
