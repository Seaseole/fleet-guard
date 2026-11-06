<?php

use App\Domain\Accounting\Data\CashFlow;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Series\CashTrends;
use App\Domain\Reporting\Series\CashTrendsPayload;
use App\Domain\Reporting\Series\ChartWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The cash-flow statement (Phase 5's statement): not accruals, but the money that
 * actually moved through the company's bank and cash accounts, told through the counterparties
 * of the entries that moved them (the ledger-only rule). Cash accounts are the ones the
 * Bank & cash register links to the ledger — the register decides what counts as cash.
 *
 * Direct method, deliberately: an indirect (net-profit-plus-adjustments) sheet would need this
 * build to invent a fixed-asset register and a tax provision that don't exist yet, and a
 * 21-truck fleet deserves to see receipts and diesel as lines, not as reconciling items.
 *
 * The foot of the sheet proves itself: opening cash + operating + investing + financing must
 * equal closing cash, each half read through a different ledger door. A disagreement is a
 * posting bug printed in red (the tie-out principle inside one statement), not a footnote.
 */
new #[Title('Cash flow')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            if ($this->fromDate() > $this->toDate()) {
                $this->to = $this->from;
            }

            unset($this->statement, $this->trends);
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
    public function statement(): CashFlow
    {
        return app(LedgerStatements::class)->cashFlow($this->fromDate(), $this->toDate());
    }

    /**
     * The sheet told as diverging columns (docs/DESIGN-SYSTEM.md §5). The door is the same `cashFlow`
     * read asked at three zoom levels, so the columns and the signed sections below can only ever
     * disagree by a posting bug — and the Week / Month / Year control is the card's own.
     */
    #[Computed]
    public function trends(): CashTrendsPayload
    {
        return app(CashTrends::class)(
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

    /** Net figures always print a number — a flat cash month is a fact, not an absence. */
    public function net(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minorUnits, $base)->format($base);
    }

    public function tone(int $minorUnits): string
    {
        return $minorUnits < 0 ? 'text-warning' : '';
    }

    public function clear(): void
    {
        $this->reset(['from', 'to']);
        unset($this->statement, $this->trends);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Cash flow') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Money in and money out of the linked bank and cash accounts from:from through:to, told through the counterparty of each posted entry. Transfers between the fleet\'s own accounts net to zero and appear nowhere.', ['from' => $this->fromDate()->format('d M Y'), 'to' => $this->toDate()->format('d M Y')]) }}
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

    {{-- The reading of the window above, in the same door's words — the sheet sections then give the
         counterparty detail the columns only summarise. --}}
    <x-patterns.trends-chart :payload="$this->trends" />

    @forelse ($this->statement->sections as $section)
        <x-ui.card :title="$section->heading">
            <x-ui.table :caption="__('Counterparty accounts that moved cash between :from and :to', ['from' => $this->fromDate()->toDateString(), 'to' => $this->toDate()->toDateString()])">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('In') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Out') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Net') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->lines as $line)
                        <tr wire:key="cf-{{ $section->key }}-{{ $line->code }}">
                            <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $this->money($line->inflowMinor) }}</x-ui.table.td>
                            <x-ui.table.td numeric>{{ $this->money($line->outflowMinor) }}</x-ui.table.td>
                            <x-ui.table.td numeric class="{{ $this->tone($line->netMinor()) }}">{{ $this->net($line->netMinor()) }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-fg-line font-semibold">
                        <x-ui.table.td colspan="2">{{ $section->heading }} net</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($section->inflowMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($section->outflowMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric class="{{ $this->tone($section->netMinor()) }}">{{ $this->net($section->netMinor()) }}</x-ui.table.td>
                    </tr>
                </tfoot>
            </x-ui.table>
        </x-ui.card>
    @empty
        <x-ui.card :title="__('Movement')">
            <x-ui.empty-state
                icon="banknotes"
                :title="__('No cash moved in this window')"
                :description="__('Payments, supplier payments, kiosk slips and fuel issues all post through a linked bank or cash account; nothing crossed one between these dates. Register a bank account against the ledger first if that is wrong.')"
            />
        </x-ui.card>
    @endforelse

    <x-ui.card :title="__('Cash position')">
        <div class="flex flex-wrap items-center gap-4">
            <span class="fg-numeric text-2xl font-semibold {{ $this->statement->netChangeMinor() < 0 ? 'text-warning' : 'text-fg-ink' }}">
                {{ $this->net($this->statement->closingCashMinor) }}
            </span>
            <x-ui.badge :tone="$this->statement->isConsistent() ? 'success' : 'danger'">
                {{ $this->statement->isConsistent() ? __('Ties out') : __('Does not tie out') }}
            </x-ui.badge>
        </div>
        <p class="pt-2 text-xs text-fg-ink-muted">
            {{ __('Opening :open · Net change :change (in :in · out :out) · Closing :close · window :from → :to', [
                'open' => $this->net($this->statement->openingCashMinor),
                'change' => $this->net($this->statement->netChangeMinor()),
                'in' => $this->money(array_sum(array_map(static fn ($s): int => $s->inflowMinor(), $this->statement->sections))),
                'out' => $this->money(array_sum(array_map(static fn ($s): int => $s->outflowMinor(), $this->statement->sections))),
                'close' => $this->net($this->statement->closingCashMinor),
                'from' => $this->fromDate()->format('d M Y'),
                'to' => $this->toDate()->format('d M Y'),
            ]) }}
            @unless ($this->statement->isConsistent())
                <span class="text-danger">{{ __('Opening plus the section nets disagree with closing cash by:diff — a posting bug to chase, not a rounding habit.', ['diff' => $this->net($this->statement->closingCashMinor - ($this->statement->openingCashMinor + $this->statement->netChangeMinor()))]) }}</span>
            @endunless
        </p>
    </x-ui.card>
</div>
