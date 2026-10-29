<?php

use App\Domain\Kernel\Ledger\Actions\PostManualJournal;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Post a manual journal (§15.2 Phase 0's "manually posted balanced journal", §9.1's ⚑ re-auth
 * ability, §6.3's one write door).
 *
 * The screen collects major-unit text and hands the Action integer minor units: §5.1 puts the
 * conversion at the boundary, and Money::ofMajor() is where "1 234,56" becomes P1 234.56 rather
 * than P123 456.00. Nothing here does money arithmetic — the running totals below only ever add
 * parsed minor units, so a rounding rule cannot be invented by whoever edits this file.
 *
 * The balance indicator is a courtesy. The refusal that matters is the one the Action raises and,
 * behind it, the DB CHECK — a screen that validated perfectly would still not be the reason the
 * ledger balances (§6.3's whole point is that the layer under the app is the one that holds).
 */
new #[Title('New journal entry')] class extends Component
{
    public string $description = '';

    public string $postedOn = '';

    /** @var list<array{account: string, debit: string, credit: string}> */
    public array $rows = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->postedOn = now()->toDateString();
        $this->rows = [$this->blankRow(), $this->blankRow()];
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    /** @return array{account: string, debit: string, credit: string} */
    private function blankRow(): array
    {
        return ['account' => '', 'debit' => '', 'credit' => ''];
    }

    public function post(): void
    {
        $this->error = null;

        $this->validate([
            'description' => ['required', 'string', 'max:255'],
            'postedOn' => ['required', 'date'],
        ]);

        try {
            $lines = $this->lines();

            $entry = app(PostManualJournal::class)->handle(
                description: $this->description,
                postedOn: \Carbon\CarbonImmutable::parse($this->postedOn),
                lines: $lines,
                userId: (int) auth()->id(),
            );

            $this->reset('description');
            $this->rows = [$this->blankRow(), $this->blankRow()];

            $this->dispatch('toast', message: __('Journal :no posted.', ['no' => $entry->entry_no]), kind: 'success');

            $this->redirectRoute('accounting.journal', navigate: true);
        } catch (PostingRejected $rejected) {
            // One message, from the layer that refused it. The ledger's own refusals are written
            // to be read by the person who hit them, not swallowed into a generic "validation failed".
            $this->error = $rejected->getMessage();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->error = $e->errorInfo[2] ?? $e->getMessage();
        }
    }

    /**
     * @return list<array{account_id: int, direction: string, amount: int, note: string|null}>
     */
    private function lines(): array
    {
        $base = CurrentCompany::model()->base_currency_code;
        $lines = [];

        foreach ($this->rows as $index => $row) {
            $debit = trim($row['debit'] ?? '') !== '';
            $credit = trim($row['credit'] ?? '') !== '';

            if (! $debit && ! $credit) {
                continue;
            }

            if ($debit && $credit) {
                throw PostingRejected::oneSided();
            }

            $side = $debit ? 'debit' : 'credit';

            try {
                $amount = Money::ofMajor($row[$side], $base);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    "rows.{$index}.{$side}" => $exception->getMessage(),
                ]);
            }

            if (trim($row['account']) === '') {
                throw new PostingRejected(__('Each line needs an account — line :n has none.', ['n' => $index + 1]));
            }

            $account = Account::query()->where('code', trim($row['account']))->first();

            if ($account === null) {
                throw PostingRejected::accountCodeUnknown(trim($row['account']));
            }

            $lines[] = [
                'account_id' => $account->id,
                'direction' => $side,
                // Parsed at the boundary, exactly once: the Action receives minor units and cannot
                // be handed a float by a screen that forgot which unit it was thinking in.
                'amount' => $amount->minorUnits,
                'note' => null,
            ];
        }

        if ($lines === []) {
            throw PostingRejected::noLines();
        }

        return $lines;
    }

    /**
     * Running totals for the balance indicator. Returns null rather than a wrong number when a
     * field cannot be parsed — an editor mid-typing shows "incomplete", not a false "balanced".
     *
     * @return array{debit: ?int, credit: ?int}|null
     */
    #[Computed]
    public function running(): ?array
    {
        $base = CurrentCompany::model()->base_currency_code;
        $debit = 0;
        $credit = 0;

        foreach ($this->rows as $row) {
            foreach (['debit', 'credit'] as $side) {
                $typed = trim($row[$side] ?? '');

                if ($typed === '') {
                    continue;
                }

                try {
                    $minor = Money::ofMajor($typed, $base)->minorUnits;
                } catch (\DomainException) {
                    return null;
                }

                $side === 'debit' ? $debit += $minor : $credit += $minor;
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'normal_balance']);
    }
}; ?>

        <div class="grid gap-6">
            <header class="grid gap-1">
                <x-ui.heading size="xl" level="1">{{ __('New journal entry') }}</x-ui.heading>
                <x-ui.text>
                    {{ __('This posts straight into the ledger. A posted entry cannot be edited or deleted — a mistake is corrected by reversing it.') }}
                </x-ui.text>
            </header>

            @if ($error !== null)
                <x-ui.alert tone="danger" :title="__('The ledger refused this entry')">
                    {{ $error }}
                </x-ui.alert>
            @endif

            <x-ui.card :title="__('Entry')">
                <form class="grid gap-5" wire:submit="post">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input wire:model="description" name="description" :label="__('Description')" :hint="__('What this entry is for, in the words an auditor would accept.')" required />
                        <x-ui.input wire:model="postedOn" name="postedOn" type="date" :label="__('Business date')" :hint="__('Decides the accounting period. A locked month is refused.')" required />
                    </div>

                    <div class="grid gap-2">
                        @php($baseCurrency = \App\Domain\Kernel\Tenancy\CurrentCompany::model()->base_currency_code)
                        <div class="hidden grid-cols-12 gap-2 text-xs font-medium text-fg-ink-muted sm:grid">
                            <span class="col-span-5">{{ __('Account') }}</span>
                            <span class="col-span-3 text-right">{{ __('Debit') }}</span>
                            <span class="col-span-3 text-right">{{ __('Credit') }}</span>
                            <span class="col-span-1"></span>
                        </div>

                        @foreach ($rows as $index => $row)
                            <fieldset class="grid grid-cols-1 items-start gap-3 border-b border-fg-line pb-3 sm:grid-cols-12 sm:gap-2 sm:border-none sm:pb-0">
                                <legend class="sr-only">{{ __('Journal line :n', ['n' => $index + 1]) }}</legend>
                                <div class="grid gap-1.5 sm:col-span-5">
                                    <span class="text-xs font-medium text-fg-ink-secondary sm:hidden" aria-hidden="true">{{ __('Account') }}</span>
                                    <select
                                        wire:model="rows.{{ $index }}.account"
                                        aria-label="{{ __('Account for line :n', ['n' => $index + 1]) }}"
                                        class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                                    >
                                        <option value="">{{ __('Choose an account') }}</option>
                                        @foreach ($this->accounts as $account)
                                            <option value="{{ $account->code }}">{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="grid gap-1.5 sm:col-span-3">
                                    <span class="text-xs font-medium text-fg-ink-secondary sm:hidden" aria-hidden="true">{{ __('Debit') }}</span>
                                    <x-ui.input
                                        :wire:model.live.debounce.400ms="'rows.'.$index.'.debit'"
                                        :name="'rows.'.$index.'.debit'"
                                        :aria-label="__('Debit for line :n (:currency)', ['n' => $index + 1, 'currency' => $baseCurrency])"
                                        :title="__('Use a dot or comma for decimals; spaces for thousands.')"
                                        inputmode="decimal"
                                        class="text-right font-mono text-xs"
                                    />
                                </div>

                                <div class="grid gap-1.5 sm:col-span-3">
                                    <span class="text-xs font-medium text-fg-ink-secondary sm:hidden" aria-hidden="true">{{ __('Credit') }}</span>
                                    <x-ui.input
                                        :wire:model.live.debounce.400ms="'rows.'.$index.'.credit'"
                                        :name="'rows.'.$index.'.credit'"
                                        :aria-label="__('Credit for line :n (:currency)', ['n' => $index + 1, 'currency' => $baseCurrency])"
                                        :title="__('Use a dot or comma for decimals; spaces for thousands.')"
                                        inputmode="decimal"
                                        class="text-right font-mono text-xs"
                                    />
                                </div>

                                <div class="sm:col-span-1 sm:pt-2">
                                    @if (count($rows) > 2)
                                        <x-ui.button variant="ghost" size="sm" type="button" wire:click="removeRow({{ $index }})" :aria-label="__('Remove line :n', ['n' => $index + 1])">
                                            <x-ui.icon name="trash" />
                                            <span class="sm:hidden">{{ __('Remove line') }}</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </fieldset>
                        @endforeach

                        <div>
                            <x-ui.button variant="ghost" size="sm" type="button" wire:click="addRow">
                                <x-ui.icon name="plus" /> {{ __('Add a line') }}
                            </x-ui.button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-fg-line pt-4">
                        <div class="flex items-center gap-4 font-mono text-xs">
                            <span class="text-fg-ink-secondary">
                                {{ __('Debits') }}
                                {{ $this->running === null ? '—' : \App\Domain\Kernel\Money\Money::ofMinor($this->running['debit'], \App\Domain\Kernel\Tenancy\CurrentCompany::model()->base_currency_code)->format($baseCurrency) }}
                            </span>
                            <span class="text-fg-ink-secondary">
                                {{ __('Credits') }}
                                {{ $this->running === null ? '—' : \App\Domain\Kernel\Money\Money::ofMinor($this->running['credit'], \App\Domain\Kernel\Tenancy\CurrentCompany::model()->base_currency_code)->format($baseCurrency) }}
                            </span>

                            @if ($this->running === null)
                                <x-ui.badge tone="neutral">{{ __('incomplete') }}</x-ui.badge>
                            @elseif ($this->running['debit'] === $this->running['credit'] && $this->running['debit'] > 0)
                                <x-ui.badge tone="success" icon="check">{{ __('balanced') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="warning" icon="exclamation-triangle">{{ __('out of balance') }}</x-ui.badge>
                            @endif
                        </div>

                        <div class="flex items-center gap-3">
                            <x-ui.button variant="ghost" :href="route('accounting.journal')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button variant="primary" type="submit">{{ __('Post journal') }}</x-ui.button>
                        </div>
                    </div>
                </form>
            </x-ui.card>
        </div>
