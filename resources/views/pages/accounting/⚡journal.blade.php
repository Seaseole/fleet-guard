<?php

use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The journal browser (§15.2 Phase 0: "read-only journal browser").
 *
 * Read-only is the design, not a missing feature: §6.3 gives the ledger no update and no delete
 * path, so a browser with an "edit" button would be promising something the database refuses.
 * Corrections are made by reversing an entry, which Phase 5 builds; what this screen owes the
 * owner is the truth, in date order, with the lines underneath it.
 *
 * Totals come from the entry row (§6.6 tier 1 — a list never sums gl_journal_lines). That is why
 * line_count/total_debit/total_credit are stored at all, and why ledger:verify exists to keep the
 * story the header tells honest.
 */
new #[Title('Journal')] class extends Component {
    use WithPagination;

    #[Url]
    public string $period = '';

    #[Url(as: 'account')]
    public string $accountCode = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $q = '';

    /** The entry whose lines are shown; one at a time, because a full ledger page of lines is unreadable. */
    public ?string $openEntry = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'accountCode', 'status', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['period', 'accountCode', 'status', 'q']);
        $this->resetPage();
        unset($this->entries);
    }

    public function hasFilters(): bool
    {
        return $this->period !== '' || $this->accountCode !== '' || $this->status !== '' || $this->q !== '';
    }

    public function showLines(string $entryId): void
    {
        $this->openEntry = $this->openEntry === $entryId ? null : $entryId;
    }

    /** @return LengthAwarePaginator<int, JournalEntry> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with('period')
            ->when($this->period !== '', fn ($query) => $query->whereHas(
                'period', fn ($period) => $period->where('period', $this->period)))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->accountCode !== '', fn ($query) => $query->whereHas(
                'lines.account', fn ($account) => $account->where('code', $this->accountCode)))
            ->when($this->q !== '', function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->q).'%';
                $query->where(fn ($or) => $or->where('entry_no', 'like', $like)
                    ->orWhere('description', 'like', $like));
            })
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Collection<int, Period> */
    #[Computed]
    public function periods(): Collection
    {
        return Period::query()->orderByDesc('period')->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /** @return Collection<int, \App\Domain\Kernel\Ledger\Models\JournalLine> */
    #[Computed]
    public function openLines(): Collection
    {
        if ($this->openEntry === null) {
            return collect();
        }

        $entry = JournalEntry::query()->with('lines.account')->find($this->openEntry);

        return $entry?->lines->sortBy(fn ($line) => $line->account->code)->values() ?? collect();
    }

    public function money(int $minorUnits, string $currencyCode): string
    {
        return Money::ofMinor($minorUnits, $currencyCode)->format($currencyCode);
    }

    public function base(): string
    {
        return CurrentCompany::model()->base_currency_code;
    }
}; ?>

    <div class="grid gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <x-ui.heading size="xl" level="1">{{ __('Journal') }}</x-ui.heading>
                <x-ui.text>
                    {{ __('Posted entries are immutable. A mistake is corrected with a reversing entry, never by editing one. (:ledger_verify)', ['ledger_verify' => 'ledger:verify']) }}
                </x-ui.text>
            </div>

            @can('ledger.journal.manual')
                <x-ui.button variant="primary" :href="route('accounting.journal.create')" wire:navigate>
                    {{ __('New journal') }}
                </x-ui.button>
            @endcan
        </header>

        <x-ui.card :title="__('Filter')">
            <div class="grid gap-4 sm:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
                <div class="grid gap-1.5">
                    <label for="period" class="text-xs font-medium text-fg-ink-secondary">{{ __('Period') }}</label>
                    <x-ui.select name="period" id="period" wire:model.live="period">
                        <option value="">{{ __('All periods') }}</option>
                        @foreach ($this->periods as $option)
                            <option value="{{ $option->period }}">{{ $option->period }} · {{ $option->status }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="accountCode" class="text-xs font-medium text-fg-ink-secondary">{{ __('Account') }}</label>
                    <x-ui.select name="accountCode" id="accountCode" wire:model.live="accountCode">
                        <option value="">{{ __('Any account') }}</option>
                        @foreach ($this->accounts as $option)
                            <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="status" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>
                    <x-ui.select name="status" id="status" wire:model.live="status">
                        <option value="">{{ __('Any status') }}</option>
                        @foreach (\App\Domain\Kernel\Ledger\Models\JournalEntry::statuses() as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input wire:model.live.debounce.400ms="q" name="q" :label="__('Search')" :hint="__('Number or description')" />

                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" class="self-end" :disabled="! $this->hasFilters()">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Entries')">
            <x-ui.table :caption="__('Journal entries')">
                <thead>
                    <tr>
                        <x-ui.table.th>{{ __('Number') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Date') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Description') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __('Debits') }}</x-ui.table.th>
                        <x-ui.table.th class="text-right">{{ __('Credits') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Source') }}</x-ui.table.th>
                        <x-ui.table.th></x-ui.table.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->entries as $entry)
                        <tr wire:click="showLines('{{ $entry->id }}')" class="cursor-pointer" wire:key="journal-entry-{{ $entry->id }}">
                            <x-ui.table.td class="font-mono text-xs text-fg-ink">{{ $entry->entry_no }}</x-ui.table.td>
                            <x-ui.table.td class="whitespace-nowrap"><span title="{{ $entry->posted_on->toDateString() }}">{{ $entry->posted_on->format('d M Y') }}</span></x-ui.table.td>
                            <x-ui.table.td class="text-fg-ink-secondary">{{ $entry->description }}</x-ui.table.td>
                            <x-ui.table.td class="text-right font-mono text-xs">
                                {{ $this->money($entry->total_debit, $this->base()) }}
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right font-mono text-xs">
                                {{ $this->money($entry->total_credit, $this->base()) }}
                            </x-ui.table.td>
                            <x-ui.table.td>
                                <x-ui.badge tone="neutral">{{ $entry->source_type }}</x-ui.badge>
                                @if ($entry->status !== \App\Domain\Kernel\Ledger\Models\JournalEntry::Posted)
                                    <x-ui.badge :tone="$entry->status === \App\Domain\Kernel\Ledger\Models\JournalEntry::Void ? 'danger' : 'warning'">
                                        {{ $entry->status }}
                                    </x-ui.badge>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td class="text-right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    type="button"
                                    wire:click="showLines('{{ $entry->id }}')"
                                    wire:key="journal-lines-{{ $entry->id }}"
                                    aria-controls="journal-entry-lines-{{ $entry->id }}"
                                    :aria-expanded="$openEntry === $entry->id"
                                >
                                    {{ $openEntry === $entry->id ? __('Hide lines') : __('View lines') }}
                                </x-ui.button>
                            </x-ui.table.td>
                        </tr>

                        @if ($openEntry === $entry->id)
                            <tr id="journal-entry-lines-{{ $entry->id }}" wire:key="journal-lines-{{ $entry->id }}">
                                <td colspan="7" class="bg-fg-surface-sunk px-4 py-3">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-xs text-fg-ink-muted">
                                                <th class="py-1 font-medium">{{ __('Account') }}</th>
                                                <th class="py-1 font-medium">{{ __('Note') }}</th>
                                                <th class="py-1 text-right font-medium">{{ __('Amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($this->openLines as $line)
                                                <tr class="border-t border-fg-line">
                                                    <td class="py-1.5">
                                                        <span class="font-mono text-xs">{{ $line->account->code }}</span>
                                                        <span class="text-fg-ink-secondary">{{ $line->account->name }}</span>
                                                    </td>
                                                    <td class="py-1.5 text-fg-ink-secondary">
                                                        {{ $line->line_note }}
                                                        @if ($line->truck_id || $line->trip_id || $line->customer_id || $line->driver_id)
                                                            <span class="ml-2 font-mono text-xs text-fg-ink-muted">
                                                                @if ($line->truck_id) truck {{ $line->truck_id }} @endif
                                                                @if ($line->trip_id) trip {{ $line->trip_id }} @endif
                                                                @if ($line->customer_id) cust {{ $line->customer_id }} @endif
                                                                @if ($line->driver_id) drv {{ $line->driver_id }} @endif
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="py-1.5 text-right font-mono text-xs">
                                                        <span class="{{ $line->isDebit() ? 'text-fg-ink' : 'text-fg-ink-secondary' }}">
                                                            {{ $line->direction->value }} {{ $this->money($line->amount_base, $this->base()) }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state
                                    icon="calculator"
                                    :title="$this->hasFilters() ? __('No entries match these filters') : __('Nothing posted yet')"
                                    :description="$this->hasFilters()
                                        ? __('Clear the period, account, status, or search filter to see the rest of the journal.')
                                        : __('Every operational module writes here — once trips, fuel and invoices exist, the journal fills itself. A manual journal can be posted now.')"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            <div class="mt-4">
                {{ $this->entries->links() }}
            </div>
        </x-ui.card>
    </div>
