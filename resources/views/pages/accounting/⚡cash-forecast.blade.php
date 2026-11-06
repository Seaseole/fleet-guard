<?php

use App\Domain\Accounting\Queries\CashForecastQuery;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The 13-week cash schedule roll-up. It is deliberately a *roll-up of the
 * schedules the database already holds* — invoices on their due dates, bills on theirs, loan
 * instalments on their frozen plan rows — and not a forecasting model: an invented growth
 * assumption would be a second truth nobody signs (the principle, aimed at this screen).
 *
 * The row that matters is the first negative week, printed as a callout rather than a colour:
 * a 21-truck fleet does not run out of cash suddenly, it runs out on a Tuesday thirteen weeks
 * away that this sheet names.
 */
new #[Title('Cash forecast')] class extends Component {
    #[Url]
    public int $weeks = 13;

    public function updated(string $property): void
    {
        if ($property === 'weeks') {
            unset($this->sheet);
        }
    }

    #[Computed]
    public function sheet()
    {
        $weeks = min(max($this->weeks, 4), 26);

        return app(CashForecastQuery::class)->build(
            Carbon::now(CurrentCompany::timezone()),
            $weeks,
        );
    }

    public function money(int $minorUnits): string
    {
        return Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)->format(CurrentCompany::model()->base_currency_code);
    }

    public function dash(int $minorUnits): string
    {
        return $minorUnits === 0 ? '—' : Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)->format(CurrentCompany::model()->base_currency_code);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Cash forecast') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Weeks of cash the schedules already promise, from opening bank and cash forward. Nothing here is modelled: expected-in is unpaid invoices on their due dates, expected-out is unpaid bills and the loan instalment rows frozen at their plan dates.') }}
        </x-ui.text>
    </header>

    @php($negative = $this->sheet->firstNegativeWeek())
    @if ($negative !== null)
        <x-ui.alert tone="danger" :title="__('Projected shortfall')">
            {{ __('Cash goes negative in the week starting :week, closing at :amount — the schedule dates it, not a guess.', [
                'week' => $negative->weekStart,
                'amount' => $this->money($negative->closingCashMinor),
            ]) }}
        </x-ui.alert>
    @endif

    <x-ui.card :title="__('The sheet')">
        <x-ui.table :caption="__('Opening :open · expected in :in · expected out :out · closing :close', [
            'open' => $this->money($this->sheet->openingCashMinor),
            'in' => $this->money($this->sheet->expectedInMinor()),
            'out' => $this->money($this->sheet->expectedOutMinor()),
            'close' => $this->money($this->sheet->closingCashMinor()),
        ])"
        >
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Week') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('In') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Out') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Net') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Closing cash') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Scheduled receipts') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->sheet->lines as $index => $line)
                    <tr wire:key="cfw-{{ $line->weekStart }}" @if ($line->closingCashMinor < 0) class="bg-danger-soft/40" @endif>
                        <x-ui.table.td identifier>{{ $index + 1 }} · {{ \Illuminate\Support\Carbon::parse($line->weekStart)->format('d M') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->dash($line->expectedInMinor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->dash($line->expectedOutMinor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->dash($line->netMinor()) }}</x-ui.table.td>
                        <x-ui.table.td numeric class="{{ $line->closingCashMinor < 0 ? 'text-danger font-medium' : '' }}">{{ $this->money($line->closingCashMinor) }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-muted text-xs">{{ $line->inKeys === [] ? '—' : implode(', ', array_slice($line->inKeys, 0, 4)).(count($line->inKeys) > 4 ? ' +'.(count($line->inKeys) - 4) : '') }}</x-ui.table.td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        <div class="mt-4 grid gap-1">
            <x-ui.text size="sm" color="muted">{{ __('Assumptions') }}</x-ui.text>
            @foreach ($this->sheet->assumptions as $assumption)
                <x-ui.text size="xs" color="muted">· {{ $assumption }}</x-ui.text>
            @endforeach
        </div>
    </x-ui.card>
</div>
