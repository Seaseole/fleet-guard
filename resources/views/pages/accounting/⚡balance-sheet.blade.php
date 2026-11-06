<?php

use App\Domain\Accounting\Data\BalanceSheet;
use App\Domain\Accounting\Data\BalanceSheetSection;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Series\BalanceSheetComposition;
use App\Domain\Reporting\Series\ChartWindow;
use App\Domain\Reporting\Series\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The balance sheet (Phase 5's statement): what the company held, owed and funded at an
 * as-at date, read through the ledger doors like every other financial statement. This
 * sheet is cumulative — the same door the trial balance uses, re-shaped by account type — so
 * opening balances from the cutover are inside it without a separate "opening" parameter.
 *
 * The earnings of not-yet-closed periods ride on the equity side as their own named line
 *: rolling them into retained earnings is what a period close *does*, and until the
 * first close the sheet shows the difference rather than hiding it. A sheet whose two sides
 * disagree prints the difference in red — the ledger cannot produce one, and if it ever does,
 * that is a posting bug the reader must see, not an arithmetic footnote.
 */
new #[Title('Balance sheet')] class extends Component {
    #[Url]
    public string $asAt = '';

    public function updated(string $property): void
    {
        if ($property === 'asAt') {
            unset($this->sheet, $this->composition);
        }
    }

    public function asAtDate(): Carbon
    {
        $today = $this->businessToday();

        if ($this->asAt === '') {
            return $today;
        }

        try {
            return Carbon::parse($this->asAt)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }
    }

    public function businessToday(): Carbon
    {
        return Carbon::now(CurrentCompany::timezone())->startOfDay();
    }

    #[Computed]
    public function sheet(): BalanceSheet
    {
        return app(LedgerStatements::class)->balanceSheet($this->asAtDate());
    }

    /**
     * The three sides the sheet totals below, drawn as one bar (docs/REDESIGN-CONCEPT.md §3). Same
     * door, same date, so the halves of the bar *are* the identity the sections print — a picture
     * with its own query would be a second definition of the balance sheet.
     */
    #[Computed]
    public function composition(): Series
    {
        return app(BalanceSheetComposition::class)(
            ChartWindow::fromRequest('', $this->asAt, CarbonImmutable::instance($this->businessToday())),
        );
    }

    public function money(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return $minorUnits === 0
            ? '—'
            : Money::ofMinor($minorUnits, $base)->format($base);
    }

    /** A total always prints a number — zero cash is a fact, not an absence. */
    public function total(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minorUnits, $base)->format($base);
    }

    /** A figure running against its section's natural side (an overdraft, a negative stock) keeps its sign. */
    public function tone(int $minorUnits): string
    {
        return $minorUnits < 0 ? 'text-warning' : '';
    }

    /** @param list<BalanceSheetSection> $sections */
    public function sectionsTotal(array $sections): int
    {
        return array_sum(array_map(static fn (BalanceSheetSection $s): int => $s->totalMinor(), $sections));
    }

    public function clear(): void
    {
        $this->reset('asAt');
        unset($this->sheet, $this->composition);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Balance sheet') }}</x-ui.heading>
        <x-ui.text>
            {{ __('What the ledger held, owed and funded as at:asAt. Financial statements read the ledger — this sheet is the journal, not a second source of it.', ['asAt' => $this->asAtDate()->format('d M Y')]) }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('As at')">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
            <x-ui.input
                type="date"
                name="asAt"
                :label="__('As-at date')"
                :hint="__('Blank means today. Movement through the whole posted book up to that date, in base minor units.')"
                wire:model.live="asAt"
                :value="$this->asAt"
            />

            <x-ui.button variant="ghost" size="sm" wire:click="clear" class="self-end" :disabled="$this->asAt === ''">
                {{ __('Clear') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- The identity before the detail: held on one half, owed-and-funded on the other. --}}
    <x-patterns.chart :series="$this->composition" />

    @foreach ($this->sheet->assetSections as $section)
        <x-ui.card :title="$section->heading">
            <x-ui.table :caption="__('Asset accounts with movement as at :asAt', ['asAt' => $this->asAtDate()->toDateString()])">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Held') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->lines as $line)
                        <tr wire:key="bs-a-{{ $line->code }}">
                            <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                            <x-ui.table.td numeric class="{{ $this->tone($line->amountMinor) }}">{{ $this->money($line->amountMinor) }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-fg-line font-semibold">
                        <x-ui.table.td colspan="2">{{ $section->heading }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->total($section->totalMinor()) }}</x-ui.table.td>
                    </tr>
                </tfoot>
            </x-ui.table>
        </x-ui.card>
    @endforeach

    <x-ui.card :title="__('Total assets')">
        <span class="fg-numeric text-2xl font-semibold">{{ $this->total($this->sheet->totalAssetsMinor()) }}</span>
    </x-ui.card>

    @foreach ($this->sheet->liabilitySections as $section)
        <x-ui.card :title="$section->heading">
            <x-ui.table :caption="__('Liability accounts with movement as at :asAt', ['asAt' => $this->asAtDate()->toDateString()])">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Owed') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->lines as $line)
                        <tr wire:key="bs-l-{{ $line->code }}">
                            <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                            <x-ui.table.td numeric class="{{ $this->tone($line->amountMinor) }}">{{ $this->money($line->amountMinor) }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-fg-line font-semibold">
                        <x-ui.table.td colspan="2">{{ $section->heading }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->total($section->totalMinor()) }}</x-ui.table.td>
                    </tr>
                </tfoot>
            </x-ui.table>
        </x-ui.card>
    @endforeach

    @foreach ($this->sheet->equitySections as $section)
        <x-ui.card :title="$section->heading">
            <x-ui.table :caption="__('Equity as at :asAt', ['asAt' => $this->asAtDate()->toDateString()])">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Funded') }}</x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->lines as $line)
                        <tr wire:key="bs-e-{{ $loop->index }}-{{ $line->code }}">
                            <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                            <x-ui.table.td numeric class="{{ $this->tone($line->amountMinor) }}">{{ $this->money($line->amountMinor) }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-fg-line font-semibold">
                        <x-ui.table.td colspan="2">{{ $section->heading }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->total($section->totalMinor()) }}</x-ui.table.td>
                    </tr>
                </tfoot>
            </x-ui.table>
        </x-ui.card>
    @endforeach

    <x-ui.card :title="__('The equation')">
        <div class="flex flex-wrap items-center gap-4">
            <span class="fg-numeric text-2xl font-semibold">
                {{ $this->total($this->sheet->totalLiabilitiesMinor() + $this->sheet->totalEquityMinor()) }}
            </span>
            <x-ui.badge :tone="$this->sheet->isBalanced() ? 'success' : 'danger'">
                {{ $this->sheet->isBalanced() ? __('Assets = Liabilities + Equity') : __('Out of balance') }}
            </x-ui.badge>
        </div>
        <p class="pt-2 text-xs text-fg-ink-muted">
            {{ __('Assets :assets · Liabilities :liabilities · Equity :equity (includes :earnings of open-period earnings) · as at :asAt', [
                'assets' => $this->total($this->sheet->totalAssetsMinor()),
                'liabilities' => $this->total($this->sheet->totalLiabilitiesMinor()),
                'equity' => $this->total($this->sheet->totalEquityMinor()),
                'earnings' => $this->money($this->sheet->accumulatedEarningsMinor),
                'asAt' => $this->asAtDate()->format('d M Y'),
            ]) }}
            @unless ($this->sheet->isBalanced())
                <span class="text-danger">{{ __('Difference::diff — the ledger posts balanced entries, so this is a bug to chase, not a rounding habit.', ['diff' => $this->total($this->sheet->differenceMinor())]) }}</span>
            @endunless
        </p>
    </x-ui.card>
</div>
