<?php

use App\Domain\Accounting\Actions\AddReconcilingItem;
use App\Domain\Accounting\Actions\ApproveReconciliation;
use App\Domain\Accounting\Actions\RemoveReconcilingItem;
use App\Domain\Accounting\Actions\VoidReconciliation;
use App\Domain\Accounting\Models\ReconcilingItem;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Accounting\ReconcilingItemDirection;
use App\Domain\Accounting\ReconcilingItemKind;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One reconciliation run (DESIGN.md, Phase 5 slice 5d): the bank's closing figure, the
 * book read through the ledger door, and the reconciling lines between them. The screen shows
 * the arithmetic in the order the formula runs — book, plus the adds, minus the subs, expected
 * statement, actual statement, variance — because a signature should be given with the maths
 * visible, not behind a badge.
 *
 * Open runs are editable and re-read the live book on every render; approved and voided runs
 * freeze and show exactly what their signer saw. Approving with a non-zero variance is refused
 * by the Action, and the button here only says so earlier — the rule that a disagreement is
 * a fact to chase, never a tolerance to tune.
 */
new #[Title('Reconciliation')] class extends Component {
    public Reconciliation $reconciliation;

    public string $kind = ReconcilingItemKind::DepositOutstanding->value;

    public string $direction = ReconcilingItemDirection::Subtract->value;

    public string $itemAmount = '';

    public string $itemOn = '';

    public string $itemReference = '';

    public string $itemNote = '';

    public string $voidReason = '';

    public function mount(Reconciliation $reconciliation): void
    {
        $this->authorize('view', $reconciliation);

        $this->reconciliation = $reconciliation->load('bankAccount', 'items');
    }

    public function updatedKind(string $value): void
    {
        // The kind suggests a direction so a clerk typing deposits does not have to re-derive
        // signs; the field stays free because book errors genuinely run both ways.
        $this->direction = ReconcilingItemKind::from($value)->suggestedDirection()->value;
    }

    public function addItem(AddReconcilingItem $action): void
    {
        $validated = $this->validate([
            'kind' => ['required', 'in:'.implode(',', ReconcilingItemKind::values())],
            'direction' => ['required', 'in:add,sub'],
            'itemAmount' => ['required', 'string', 'max:24'],
            'itemOn' => ['nullable', 'date'],
            'itemReference' => ['nullable', 'string', 'max:80'],
            'itemNote' => ['nullable', 'string', 'max:500'],
        ]);

        $base = CurrentCompany::model()->base_currency_code;
        $minor = Money::ofMajor($validated['itemAmount'], $base)->minorUnits;

        try {
            $action->execute(
                reconciliation: $this->reconciliation,
                kind: ReconcilingItemKind::from($validated['kind']),
                direction: ReconcilingItemDirection::from($validated['direction']),
                amountMinor: $minor,
                reference: $validated['itemReference'] === '' ? null : $validated['itemReference'],
                note: $validated['itemNote'] === '' ? null : $validated['itemNote'],
                onDate: $validated['itemOn'] === '' ? null : $validated['itemOn'],
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->reset(['itemAmount', 'itemOn', 'itemReference', 'itemNote']);
        $this->reconciliation->load('items');
        unset($this->maths);
    }

    public function removeItem(string $itemId): void
    {
        $item = ReconcilingItem::query()->where('reconciliation_id', $this->reconciliation->id)->findOrFail($itemId);

        try {
            (new RemoveReconcilingItem)->execute($item);
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->reconciliation->load('items');
        unset($this->maths);
    }

    public function approve(ApproveReconciliation $action): void
    {
        try {
            $action->execute($this->reconciliation, auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Reconciliation approved — statement and book agree.'), kind: 'success');
        $this->reconciliation->refresh()->load('items');
        unset($this->maths);
    }

    public function clearVoid(): void
    {
        $this->reset('voidReason');
        $this->resetValidation('voidReason');
    }

    public function void(VoidReconciliation $action): void
    {
        $validated = $this->validate(['voidReason' => ['required', 'string', 'min:8', 'max:500']]);

        try {
            $action->execute($this->reconciliation, $validated['voidReason'], auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->reset('voidReason');
        $this->dispatch('close-void-reconciliation');
        $this->reconciliation->refresh();
        unset($this->maths);
    }

    /** @return array{book: int, adds: int, subs: int, expected: int, variance: int} */
    #[Computed]
    public function maths(): array
    {
        $math = app(ReconciliationMath::class);
        $run = $this->reconciliation;
        $book = $run->status === ReconciliationStatus::Open
            ? $math->liveBookBase($run->bankAccount, $run->statement_on)
            : $run->book_base_amount;

        $adds = 0;
        $subs = 0;

        foreach ($run->items as $item) {
            if ($item->direction === ReconcilingItemDirection::Add) {
                $adds += $item->base_amount;
            } else {
                $subs += $item->base_amount;
            }
        }

        $expected = $book + $adds - $subs;

        return [
            'book' => $book,
            'adds' => $adds,
            'subs' => $subs,
            'expected' => $expected,
            'variance' => $run->base_amount - $expected,
        ];
    }

    public function money(int $minorUnits): string
    {
        return Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)->format(CurrentCompany::model()->base_currency_code);
    }

    public function tone(int $minorUnits): string
    {
        return $minorUnits < 0 ? 'text-warning' : '';
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ $reconciliation->number }} — {{ $reconciliation->bankAccount?->name ?? __('Reconciliation') }}</x-ui.heading>
        <x-ui.text>
            {{ __('Statement of :account closed at :date. :status', [
                'account' => $reconciliation->bankAccount?->name ?? '(removed)',
                'date' => $reconciliation->statement_on->format('d M Y'),
                'status' => $reconciliation->status === ReconciliationStatus::Approved
                    ? __('Approved :at by :who — this run is a signed document and does not edit (void and restart instead).', [
                        'at' => $reconciliation->approved_at?->format('d M Y H:i'),
                        'who' => \App\Models\User::query()->find($reconciliation->approved_by)?->name ?? '—',
                    ])
                    : ($reconciliation->status === ReconciliationStatus::Void ? __('Voided. Retained as evidence.') : mb_strtolower($reconciliation->status->label()).' — the walk is still happening.'),
            ]) }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('The walk')">
        <div class="grid gap-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <x-ui.text>Book ({{ $reconciliation->status === ReconciliationStatus::Open ? 'live from the ledger' : 'frozen at signing' }})</x-ui.text>
            <x-ui.text class="fg-numeric text-right">{{ $this->money($this->maths['book']) }}</x-ui.text>

            @if ($this->maths['adds'] !== 0)
                <x-ui.text>Plus reconciling lines ({{ $this->maths['adds'] }} minor units)</x-ui.text>
                <x-ui.text class="fg-numeric text-right">+ {{ $this->money($this->maths['adds']) }}</x-ui.text>
            @endif

            @if ($this->maths['subs'] !== 0)
                <x-ui.text>Minus reconciling lines</x-ui.text>
                <x-ui.text class="fg-numeric text-right text-warning">− {{ $this->money($this->maths['subs']) }}</x-ui.text>
            @endif

            <x-ui.text class="font-medium">{{ __('Expected bank statement') }}</x-ui.text>
            <x-ui.text class="fg-numeric text-right font-medium">{{ $this->money($this->maths['expected']) }}</x-ui.text>

            <x-ui.text class="font-medium">{{ __('Actual bank statement') }}</x-ui.text>
            <x-ui.text class="fg-numeric text-right font-medium {{ $this->tone($reconciliation->base_amount) }}">{{ $this->money($reconciliation->base_amount) }}</x-ui.text>

            <x-ui.text class="font-semibold">{{ __('Variance') }}</x-ui.text>
            <x-ui.text class="fg-numeric text-right font-semibold {{ $this->maths['variance'] === 0 ? 'text-success' : 'text-danger' }}">{{ $this->money($this->maths['variance']) }}</x-ui.text>
        </div>

        @if ($reconciliation->note)
            <p class="pt-3 text-xs text-fg-ink-muted">{{ $reconciliation->note }}</p>
        @endif

        @can('approve', $reconciliation)
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-ui.button
                    variant="primary"
                    wire:click="approve"
                    wire:confirm="{{ $this->maths['variance'] === 0 ? __('Sign this reconciliation — the statement and the book agree to the minor unit.') : __('Variance is not zero — approval will be refused. Do you want to try anyway?') }}"
                    :disabled="$this->maths['variance'] !== 0"
                >
                    {{ __('Approve — statement agrees') }}
                </x-ui.button>

                @if ($this->maths['variance'] !== 0)
                    <x-ui.text size="sm" color="muted">{{ __('A non-zero variance is refused by design: add the missing line, correct the statement figure by voiding and restarting, or record what the bank did through a manual journal.') }}</x-ui.text>
                @endif
            </div>
        @endcan

        @can('void', $reconciliation)
            <div class="mt-4">
                <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-void-reconciliation')">{{ __('Void this run') }}</x-ui.button>
            </div>
        @endcan
    </x-ui.card>

    <x-ui.card :title="__('Reconciling lines')">
        <x-ui.table :caption="__('Lines between the book and the bank')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Date') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Kind') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Direction') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Reference') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reconciliation->items as $item)
                    <tr wire:key="item-{{ $item->id }}">
                        <x-ui.table.td>{{ $item->on_date?->format('d M Y') ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">{{ $item->kind->label() }}</x-ui.table.td>
                        <x-ui.table.td>
                            <span class="{{ $item->direction === ReconcilingItemDirection::Add ? 'text-success' : 'text-warning' }}">
                                {{ $item->direction === ReconcilingItemDirection::Add ? '+' : '−' }} {{ $item->direction->label() }}
                            </span>
                        </x-ui.table.td>
                        <x-ui.table.td identifier>{{ $item->reference ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($item->base_amount) }}</x-ui.table.td>
                        <x-ui.table.td numeric>
                            @can('mutate', $reconciliation)
                                <x-ui.button variant="ghost" size="sm" wire:click="removeItem('{{ $item->id }}')" wire:confirm="{{ __('Remove this reconciling line?') }}">
                                    {{ __('Remove') }}
                                </x-ui.button>
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state
                                icon="hashtag"
                                :title="__('No reconciling lines yet')"
                                :description="__('Everything the book says has already reached the bank — or nothing has been entered yet, and the variance above says whether that is true.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @can('mutate', $reconciliation)
            <form class="mt-4 grid gap-4 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto]" wire:submit="addItem">
                <div class="grid gap-1.5">
                    <label for="kind" class="text-xs font-medium text-fg-ink-secondary">{{ __('Kind') }}</label>
                    <x-ui.select name="kind" id="kind" wire:model.live="kind">
                        @foreach (ReconcilingItemKind::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid gap-1.5">
                    <label for="direction" class="text-xs font-medium text-fg-ink-secondary">{{ __('Direction') }}</label>
                    <x-ui.select name="direction" id="direction" wire:model="direction">
                        @foreach (ReconcilingItemDirection::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input wire:model="itemAmount" name="itemAmount" :label="__('Amount')" :hint="__('Positive; the sign lives in the direction.')" required />

                <x-ui.button variant="outline" size="sm" type="submit" class="self-end">{{ __('Add line') }}</x-ui.button>
            </form>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="itemOn" name="itemOn" type="date" :label="__('Dated (optional)')" />
                <x-ui.input wire:model="itemReference" name="itemReference" :label="__('Reference (optional)')" :hint="__('Cheque number, deposit slip, statement line.')" />
                <x-ui.input wire:model="itemNote" name="itemNote" :label="__('Note (optional)')" class="sm:col-span-2" />
            </div>
        @endcan
    </x-ui.card>

    <x-ui.dialog name="void-reconciliation" maxWidth="lg" :title="__('Void this reconciliation')" x-on:dialog-closed="$wire.call('clearVoid')">
        <form class="grid gap-4 p-5" wire:submit="void">
            <x-ui.text size="sm" color="muted">
                {{ __('A voided run is retained as evidence and frees the statement date for a fresh run (void and restart, never edit).') }}
            </x-ui.text>

            <x-ui.input wire:model="voidReason" name="voidReason" :label="__('Why is this run discarded?')" :hint="__('At least eight characters — the audit trail reads this.')" required />

            <div class="flex items-center gap-3">
                <x-ui.button variant="danger" type="submit">{{ __('Void reconciliation') }}</x-ui.button>
                <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-void-reconciliation')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
