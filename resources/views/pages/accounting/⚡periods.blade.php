<?php

use App\Domain\Accounting\Queries\ClosePeriodValidation;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Kernel\Ledger\Actions\ClosePeriod;
use App\Domain\Kernel\Ledger\Actions\LockPeriod;
use App\Domain\Kernel\Ledger\Actions\ReopenPeriod;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The periods screen (Phase 5: "a real month closes"). The three states are the page:
 * open accepts postings, closed is refused by the application door, locked is refused by the
 * trigger below it. The close button runs the pre-close report first — verifier clean, tie-out
 * clean, every cash account that moved signed off — and refuses while a blocking row
 * says no, because a month closed over a red check is the number the owner stops trusting.
 *
 * Closing is ⚑ re-authenticated like every other thing that cannot easily be undone
 *; locking is stated twice and permanent; reopening needs its sentence, because the
 * reopen is the event an auditor asks about first.
 */
new #[Title('Accounting periods')] class extends Component {
    use ConfirmsRecentPassword;

    #[Url]
    public string $month = '';

    public string $reopenReason = '';

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = Carbon::now(CurrentCompany::timezone())->format('Y-m');
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'month') {
            unset($this->report, $this->selected);
        }
    }

    /** @return Collection<int, Period> */
    #[Computed]
    public function periods(): Collection
    {
        return Period::query()->withCount('entries')->orderByDesc('period')->get();
    }

    #[Computed]
    public function selected(): ?Period
    {
        return $this->periods->firstWhere('period', $this->month);
    }

    #[Computed]
    public function report(): array
    {
        return app(ClosePeriodValidation::class)->run($this->month);
    }

    public function close(ClosePeriod $action): void
    {
        if (! $this->confirmRecentPassword()) {
            return;
        }

        if (! ($this->report['canClose'] ?? false)) {
            $this->dispatch('toast', message: __('The pre-close report still has a red row — close refused.'), kind: 'danger');

            return;
        }

        try {
            $action->execute($this->month, auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->resetReport();
        $this->dispatch('toast', message: __('Period closed — the application will refuse postings into it.'), kind: 'success');
    }

    public function lock(LockPeriod $action): void
    {
        if (! $this->confirmRecentPassword()) {
            return;
        }

        try {
            $action->execute($this->month, auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->resetReport();
        $this->dispatch('toast', message: __('Period locked — the refusal now lives in the database.'), kind: 'success');
    }

    public function reopen(ReopenPeriod $action): void
    {
        if (! $this->confirmRecentPassword()) {
            return;
        }

        $validated = $this->validate(['reopenReason' => ['required', 'string', 'min:8', 'max:500']]);

        try {
            $action->execute($this->month, $validated['reopenReason'], auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->reset('reopenReason');
        $this->resetReport();
        $this->dispatch('toast', message: __('Period reopened; postings into it are accepted again.'), kind: 'success');
    }

    private function resetReport(): void
    {
        unset($this->report, $this->selected, $this->periods);
    }

    public function statusLabel(?Period $period): string
    {
        return $period === null ? '—' : ucfirst($period->status);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Accounting periods') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Open accepts postings; closed is refused by the application; locked is refused by the database itself. Closing runs the pre-flight report first — verifier, tie-out, and every cash account that moved signed off — and refuses while a blocking row is red.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('The month')">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div class="grid gap-1.5">
                <label for="month" class="text-xs font-medium text-fg-ink-secondary">{{ __('Month') }}</label>
                <x-ui.select name="month" id="month" wire:model.live="month">
                    @forelse ($this->periods as $period)
                        <option value="{{ $period->period }}">{{ $period->period }} — {{ $this->statusLabel($period) }}</option>
                    @empty
                        <option value="{{ $this->month }}">{{ $this->month }} — (no row)</option>
                    @endforelse
                </x-ui.select>
            </div>

            @if ($this->selected)
                <div class="self-end text-xs text-fg-ink-muted">
                    {{ __(':from → :to · closed :at by :who', [
                        'from' => $this->selected->starts_on->format('d M Y'),
                        'to' => $this->selected->ends_on->format('d M Y'),
                        'at' => $this->selected->closed_at?->format('d M Y H:i') ?? '—',
                        'who' => $this->selected->closed_by === null ? '—' : (\App\Models\User::query()->find($this->selected->closed_by)?->name ?? '—'),
                    ]) }}
                </div>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Pre-close report')">
        <x-ui.table :caption="__('Checks the close answers before it moves the status')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Check') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Verdict') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Detail') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->report['checks'] as $check)
                    <tr wire:key="chk-{{ $check['key'] }}">
                        <x-ui.table.td class="font-medium">{{ $check['label'] }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$check['ok'] ? 'success' : ($check['blocking'] ? 'danger' : 'warning')">
                                {{ $check['ok'] ? __('pass') : ($check['blocking'] ? __('blocking') : __('advisory')) }}
                            </x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $check['detail'] }}</x-ui.table.td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        @can('ledger.period.close')
            <div class="mt-4 grid gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    @if ($this->selected?->isOpen())
                        <x-ui.button
                            variant="primary"
                            wire:click="close"
                            wire:confirm="{{ $this->report['canClose'] ? __('Close '.e($this->month).' — nothing will post into it afterwards.') : __('A blocking check is red — closing will be refused. Continue anyway to see the refusal?') }}"
                        >
                            {{ __('Close period') }}
                        </x-ui.button>
                    @endif

                    @if ($this->selected?->status === 'closed')
                        <x-ui.button variant="outline" wire:click="lock" wire:confirm="{{ __('Lock '.e($this->month).'? The database itself will refuse postings into it and there is no way back.') }}">
                            {{ __('Lock period') }}
                        </x-ui.button>

                        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <x-ui.input wire:model="reopenReason" name="reopenReason" :label="__('Reopen — why? (8+ characters, audited)')" />
                            <x-ui.button variant="ghost" size="sm" wire:click="reopen" class="self-end">{{ __('Reopen') }}</x-ui.button>
                        </div>
                    @endif

                    @if ($this->selected?->isLocked())
                        <x-ui.text size="sm" color="muted">{{ __('Locked: the trigger refuses this month below the application. Corrections post at today\'s date as reversals.') }}</x-ui.text>
                    @endif
                </div>

                @if (! ($this->report['canClose'] ?? false))
                    <x-ui.text size="sm" color="muted">{{ __('The close is refused while any blocking row is red (a real month closes cleanly or not at all).') }}</x-ui.text>
                @endif
            </div>
        @endcan
    </x-ui.card>

    <x-ui.card :title="__('All periods')">
        <x-ui.table :caption="__('gl_periods rows — the ledger\'s own calendar')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Period') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Closed at') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Entries') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->periods as $period)
                    <tr wire:key="per-{{ $period->period }}">
                        <x-ui.table.td identifier><a href="#" wire:click.prevent="$set('month', '{{ $period->period }}')" class="text-fg-link hover:underline">{{ $period->period }}</a></x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$period->status === 'open' ? 'neutral' : ($period->status === 'closed' ? 'warning' : 'success')">
                                {{ $period->status }}
                            </x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $period->closed_at?->format('d M Y H:i') ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $period->entries_count }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty-state
                                icon="clock"
                                :title="__('No period rows yet')"
                                :description="__('A period row appears with the first posting that resolves into its month.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</div>
