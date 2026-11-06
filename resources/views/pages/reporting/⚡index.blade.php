<?php

use App\Domain\Reporting\Data\ReportDefinition;
use App\Domain\Reporting\MetricCatalog;
use App\Domain\Reporting\ReportsCatalogue;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The report library. One screen, one catalogue: the cards are
 * ReportsCatalogue::visibleToCurrentSeat(), the table is the chosen builder's ReportData, and
 * the sentences underneath it are MetricCatalog's definitions — the screen never re-derives a
 * figure and never re-writes a definition, so the library, the exports and the scheduled mails
 * cannot drift apart (the one-definition rule, applied to reports).
 *
 * A report the seat may not read is absent, not greyed: Hides the *field* behind a mask
 * inside a screen the seat owns, but a whole report another role's matrix forbids has no row
 * here to mask. The route already asked `reports.view`; each card asks its own gate as well.
 */
new #[Title('Reports')] class extends Component {
    #[Url]
    public string $report = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if ($property === 'from' && $this->to !== '' && $this->from > $this->to) {
            $this->to = $this->from;
        }

        unset($this->selected, $this->data);
    }

    /** @return list<ReportDefinition> */
    #[Computed]
    public function visible(): array
    {
        return app(ReportsCatalogue::class)->visibleToCurrentSeat();
    }

    #[Computed]
    public function selected(): ?ReportDefinition
    {
        if ($this->report === '') {
            return null;
        }

        $definition = app(ReportsCatalogue::class)->find($this->report);

        return $definition !== null && $definition->visibleToCurrentSeat() ? $definition : null;
    }

    #[Computed]
    public function data(): ?\App\Domain\Reporting\Data\ReportData
    {
        $definition = $this->selected;

        if ($definition === null) {
            return null;
        }

        $today = $this->businessToday();
        $from = $this->from !== '' ? CarbonImmutable::parse($this->from) : $today->startOfMonth();
        $to = $this->to !== '' ? CarbonImmutable::parse($this->to) : $today;

        if ($definition->isDayWindow()) {
            $from = $to = $today;
        }

        if ($from > $to) {
            $from = $to;
        }

        return app($definition->builder)->build($from, $to);
    }

    /** @return list<\App\Domain\Reporting\Data\Metric> */
    #[Computed]
    public function metrics(): array
    {
        return $this->selected === null ? [] : app(MetricCatalog::class)->forReport($this->selected->key);
    }

    public function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::now(CurrentCompany::timezone())->startOfDay();
    }

    public function open(string $key): void
    {
        $this->report = $key;
        $this->loadSchedule();
        unset($this->selected, $this->data);
    }

    public string $schedFreq = '';

    public string $schedDow = '1';

    public string $schedDom = '1';

    public string $schedNote = '';

    public function mount(): void
    {
        $this->loadSchedule();
    }

    private function loadSchedule(): void
    {
        $row = \App\Domain\Reporting\Models\ScheduledReport::query()
            ->where('report', $this->report)
            ->first();

        $this->schedFreq = $row !== null && $row->enabled ? $row->frequency : '';
        $this->schedDow = (string) ($row?->day_of_week ?? 1);
        $this->schedDom = (string) ($row?->day_of_month ?? 1);
        $this->schedNote = '';
    }

    public function saveSchedule(): void
    {
        $definition = $this->selected;

        if ($definition === null || ! $definition->schedulable) {
            return;
        }

        try {
            app(\App\Domain\Reporting\Actions\UpsertScheduledReport::class)->handle(
                $definition->key,
                $this->schedFreq,
                $this->schedFreq === 'weekly' ? (int) $this->schedDow : null,
                $this->schedFreq === 'monthly' ? (int) $this->schedDom : null,
                auth()->user(),
            );

            $this->schedNote = __('Nomination saved. Recipients are the seats that hold the export ability — resolved fresh on every send.');
        } catch (\Throwable $e) {
            $this->schedNote = $e->getMessage();
        }

        unset($this->schedules);
    }

    public function stopSchedule(): void
    {
        $definition = $this->selected;

        if ($definition === null) {
            return;
        }

        app(\App\Domain\Reporting\Actions\UpsertScheduledReport::class)->disable($definition->key);
        $this->schedFreq = '';
        $this->schedNote = __('Stopped. The row stays so the last send is still readable in the register.');
    }

    /** @return \Illuminate\Support\Collection<string, \App\Domain\Reporting\Models\ScheduledReport> */
    #[Computed]
    public function schedules(): \Illuminate\Support\Collection
    {
        return \App\Domain\Reporting\Models\ScheduledReport::query()->get()->keyBy('report');
    }

    public function export(string $format): void
    {
        $definition = $this->selected;

        if ($definition === null) {
            return;
        }

        $today = $this->businessToday();
        $from = $definition->isDayWindow()
            ? $today
            : ($this->from !== '' ? CarbonImmutable::parse($this->from) : $today->startOfMonth());
        $to = $definition->isDayWindow()
            ? $today
            : ($this->to !== '' ? CarbonImmutable::parse($this->to) : $today);

        try {
            app(\App\Domain\Reporting\Actions\QueueReportExport::class)->handle(
                $definition->key,
                \App\Domain\Reporting\ReportExportFormat::from($format),
                $from,
                $to,
                auth()->user(),
            );

            $this->queued = __('Queued. The screen under the library will show it when the file is finished.');
        } catch (\Throwable $e) {
            // A refusal that only happened in the door would otherwise look like a dead button.
            $this->queued = $e->getMessage();
        }

        unset($this->recentExports);
    }

    public string $queued = '';

    /** @return \Illuminate\Support\Collection<int, \App\Domain\Reporting\Models\ReportExport> */
    #[Computed]
    public function recentExports(): \Illuminate\Support\Collection
    {
        return \App\Domain\Reporting\Models\ReportExport::query()
            ->where('actor_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Reports') }}</x-ui.heading>
        <x-ui.text>{{ __('Every figure here has a written definition and one source door. Financial statements read the ledger; profitability reads the operational tables;:tie proves the two agree.', ['tie' => 'reports:tie-to-ledger']) }}</x-ui.text>
    </header>

    <x-ui.card :title="__('The library')">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->visible as $definition)
                <button
                    type="button"
                    wire:click="open('{{ $definition->key }}')"
                    wire:key="report-{{ $definition->key }}"
                    class="flex w-full items-center justify-between gap-3 rounded-fg-md border border-fg-line bg-fg-surface px-4 py-3 text-left text-sm text-fg-ink transition hover:bg-fg-surface-sunk"
                >
                    <span class="grid gap-0.5">
                        <span class="font-medium">{{ __($definition->title) }}</span>
                        <span class="text-xs text-fg-ink-muted">{{ __($definition->summary) }}</span>
                    </span>
                    <x-ui.badge size="xs" :tone="$definition->category === 'financial' ? 'info' : 'neutral'">{{ ucfirst($definition->category) }}</x-ui.badge>
                    @if (($this->schedules[$definition->key] ?? null)?->enabled)
                        <x-ui.badge size="xs" tone="success" icon="clock">{{ __('Mailed :freq', ['freq' => $this->schedules[$definition->key]->frequency]) }}</x-ui.badge>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($this->visible === [])
            <x-ui.empty-state
                icon="chart-bar"
                :title="__('No reports for this seat')"
                :description="__('Every report in the catalogue names an ability this seat does not hold. The library lists what your matrix can read, not what it cannot.')"
            />
        @endif
    </x-ui.card>

    @if ($this->recentExports->isNotEmpty())
        <x-ui.card :title="__('Your recent exports')":description="__('Files are streamed to the seat that queued them — a finished export is nobody else\'s read of the book.')">
            <ul class="grid gap-1">
                @foreach ($this->recentExports as $export)
                    <li class="flex flex-wrap items-center gap-3 rounded-fg-sm px-2 py-2 hover:bg-fg-surface-sunk" wire:key="export-row-{{ $export->id }}">
                        <x-ui.badge size="xs" :tone="$export->status->value === 'ready' ? 'success' : ($export->status->value === 'failed' ? 'danger' : 'neutral')">
                            {{ ucfirst($export->status->value) }}
                        </x-ui.badge>
                        <span class="fg-identifier text-xs text-fg-ink-secondary">{{ $export->report }} · {{ strtoupper($export->format->value) }}</span>
                        <span class="text-xs text-fg-ink-muted">{{ $export->window_from->format('d M') }} → {{ $export->window_to->format('d M') }}</span>
                        @if ($export->isReady())
                            <x-ui.link :href="route('reports.exports.download', $export)">{{ __('Download') }}</x-ui.link>
                        @elseif ($export->failure_reason !== null)
                            <span class="text-xs text-danger">{{ $export->failure_reason }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($this->selected !== null && $this->data !== null)
        <x-ui.card :title="__($this->selected->title)" :description="$this->data->window">
            <div class="grid gap-4">
                @unless ($this->selected->isDayWindow())
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input type="date" name="from" :label="$this->selected->key === 'trial_balance' ? __('As at (end)') : __('From')" wire:model.live="from" :value="$this->from" />
                        <x-ui.input type="date" name="to" :label="$this->selected->key === 'trial_balance' ? __('Through') : __('To')" wire:model.live="to" :value="$this->to" />
                    </div>
                @endunless

                <x-ui.table :caption="__(':title from :w', ['title' => __($this->selected->title), 'w' => $this->data->window])">
                    <thead>
                        <tr>
                            @foreach ($this->data->columns as $column)
                                <x-ui.table.th :numeric="$column['numeric']">{{ __($column['label']) }}</x-ui.table.th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->data->rows as $index => $row)
                            <tr wire:key="report-row-{{ $index }}">
                                @foreach ($row as $cellIndex => $cell)
                                    <x-ui.table.td :numeric="$this->data->columns[$cellIndex]['numeric']">{{ $cell }}</x-ui.table.td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($this->data->columns) }}">
                                    <x-ui.empty-state
                                        icon="chart-bar"
                                        :title="__('Nothing answers this window')"
                                        :description="$this->data->note ?? __('The population the report reads is empty for these dates — an honest zero, not a missing query.')"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($this->data->totals !== null)
                        <tfoot>
                            <tr class="border-t-2 border-fg-line font-semibold">
                                @foreach ($this->data->totals as $cellIndex => $cell)
                                    <x-ui.table.td :numeric="$this->data->columns[$cellIndex]['numeric']">{{ $cell }}</x-ui.table.td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </x-ui.table>

                @if ($this->data->note !== null)
                    <p class="text-xs text-fg-ink-muted">{{ $this->data->note }}</p>
                @endif

                @if (\Illuminate\Support\Facades\Gate::allows($this->selected->exportAbility))
                    <div class="flex flex-wrap items-center gap-2 border-t border-fg-line pt-4">
                        <span class="text-xs font-medium text-fg-ink-secondary">{{ __('Export this window') }}</span>
                        @foreach (\App\Domain\Reporting\ReportExportFormat::cases() as $format)
                            <x-ui.button
                                size="sm"
                                type="button"
                                wire:click="export('{{ $format->value }}')"
                                wire:key="export-{{ $format->value }}"
                            >{{ strtoupper($format->value) }}</x-ui.button>
                        @endforeach
                    </div>

                    @if ($this->queued !== '')
                        <p class="text-xs text-fg-ink-secondary" role="status">{{ $this->queued }}</p>
                    @endif
                @endif

                @if ($this->selected->schedulable && \Illuminate\Support\Facades\Gate::allows($this->selected->exportAbility))
                    <form wire:submit="saveSchedule" class="grid gap-3 border-t border-fg-line pt-4">
                        <span class="text-xs font-medium text-fg-ink-secondary">{{ __('Scheduled mail') }}</span>

                        <div class="grid gap-4 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto_auto]">
                            <div class="grid gap-1.5">
                                <label for="schedFreq" class="text-xs font-medium text-fg-ink-secondary">{{ __('Frequency') }}</label>
                                <x-ui.select name="schedFreq" id="schedFreq" wire:model.live="schedFreq">
                                    <option value="">{{ __('Not scheduled') }}</option>
                                    <option value="daily">{{ __('Daily') }}</option>
                                    <option value="weekly">{{ __('Weekly') }}</option>
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                </x-ui.select>
                            </div>

                            @if ($this->schedFreq === 'weekly')
                                <div class="grid gap-1.5">
                                    <label for="schedDow" class="text-xs font-medium text-fg-ink-secondary">{{ __('Day') }}</label>
                                    <x-ui.select name="schedDow" id="schedDow" wire:model.live="schedDow">
                                        @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $index => $name)
                                            <option value="{{ $index }}">{{ $name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            @elseif ($this->schedFreq === 'monthly')
                                <x-ui.input type="number" name="schedDom" :label="__('Day of month')" hint="1–28" wire:model.live="schedDom" :value="$this->schedDom" min="1" max="28" />
                            @else
                                <div></div>
                            @endif

                            <div class="flex items-end gap-2">
                                <x-ui.button type="submit" variant="primary" :disabled="$this->schedFreq === ''">{{ __('Nominate') }}</x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="stopSchedule" :disabled="! isset($this->schedules[$this->selected->key])">{{ __('Stop') }}</x-ui.button>
                            </div>
                        </div>

                        @if ($this->schedNote !== '')
                            <p class="text-xs text-fg-ink-secondary" role="status">{{ $this->schedNote }}</p>
                        @endif
                    </form>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Metric definitions')" :description="__('What each figure on this report means, and the door it reads.')">
            <ul class="grid gap-3">
                @foreach ($this->metrics as $metric)
                    <li class="grid gap-1 border-b border-fg-line pb-3 last:border-0 last:pb-0">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="text-sm font-medium text-fg-ink">{{ __($metric->label) }}</span>
                            <span class="fg-identifier text-xs text-fg-ink-muted">{{ $metric->key }} · {{ $metric->unit }}</span>
                        </div>
                        <p class="text-sm text-fg-ink-secondary">{{ __($metric->definition) }}</p>
                        <p class="text-xs text-fg-ink-muted">{{ $metric->source }}</p>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
