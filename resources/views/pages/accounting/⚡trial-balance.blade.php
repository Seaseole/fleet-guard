<?php

use App\Domain\Accounting\Data\TrialBalance;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The trial balance (Phase 5's statement, landed as Phase 8's foundation because the
 * report library ties its numbers to it): every account the posted ledger moved, in the
 * ledger's own debit and credit columns, through an as-at date.
 *
 * This screen adds nothing the journal does not already claim. It sums posted entries through
 * LedgerStatements — the Kernel's sanctioned unnarrowed door — which is why the two column
 * totals must agree: every entry was balanced at the door that wrote it. When they do
 * not agree, the book is saying something the entry rows deny, and the answer is the same
 * self-check the schedule runs: ledger:verify. The screen names that instead of quietly
 * printing two different totals side by side.
 */
new #[Title('Trial balance')] class extends Component {
    #[Url]
    public string $asAt = '';

    public function updated(string $property): void
    {
        if ($property === 'asAt') {
            unset($this->statement);
        }
    }

    public function asAtDate(): Carbon
    {
        if ($this->asAt === '') {
            return $this->businessToday();
        }

        try {
            return Carbon::parse($this->asAt)->startOfDay();
        } catch (\Throwable) {
            return $this->businessToday();
        }
    }

    public function businessToday(): Carbon
    {
        return Carbon::now(CurrentCompany::timezone())->startOfDay();
    }

    #[Computed]
    public function statement(): TrialBalance
    {
        return app(LedgerStatements::class)->trialBalance($this->asAtDate());
    }

    public function money(int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        return $minorUnits === 0
            ? '—'
            : Money::ofMinor($minorUnits, $base)->format($base);
    }

    public function clear(): void
    {
        $this->reset('asAt');
        unset($this->statement);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Trial balance') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Every posted ledger movement through :date, summed through the journal doors — this screen adds no figures of its own.', ['date' => $this->asAtDate()->format('d M Y')]) }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('As at')">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
            <x-ui.input
                type="date"
                name="asAt"
                :label="__('Statement date')"
                :hint="__('Blank means today. Movements after this date are not counted.')"
                wire:model.live="asAt"
                :value="$this->asAt"
                :max="now()->toDateString()"
            />

            <x-ui.button variant="ghost" size="sm" wire:click="clear" class="self-end" :disabled="$this->asAt === ''">
                {{ __('Clear') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.card :title="__(':count accounts moved', ['count' => count($this->statement->lines)])">
        <x-slot:header>
            <x-ui.badge :tone="$this->statement->isBalanced() ? 'success' : 'danger'">
                {{ $this->statement->isBalanced() ? __('Balanced') : __('Out of balance') }}
            </x-ui.badge>
        </x-slot:header>

        @if (! $this->statement->isBalanced())
            <x-ui.alert tone="danger" :title="__('The journal and this sum disagree')">
                {{ __('A posted book cannot fail to balance unless a row bypassed the door. Run :cmd and read the posting-failure screen before trusting any figure on it.', ['cmd' => 'php artisan ledger:verify']) }}
            </x-ui.alert>
        @endif

        <x-ui.table :caption="__('Trial balance as at :date', ['date' => $this->asAtDate()->toDateString()])">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Name') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Debit') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Credit') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->statement->lines as $line)
                    <tr wire:key="tb-{{ $line->code }}">
                        <x-ui.table.td identifier>{{ $line->code }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $line->name }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($line->debitMinor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($line->creditMinor) }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty-state
                                icon="calculator"
                                :title="__('Nothing posted through this date')"
                                :description="__('Every operational module writes through the ledger doors — until a trip, bill or invoice posts, the book is legitimately empty.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-fg-line font-semibold">
                    <x-ui.table.td colspan="2">{{ __('Totals') }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->statement->totalDebitMinor()) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->statement->totalCreditMinor()) }}</x-ui.table.td>
                </tr>
            </tfoot>
        </x-ui.table>
    </x-ui.card>
</div>
