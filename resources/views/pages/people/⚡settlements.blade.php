<?php

use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\RecordTripSettlement;
use App\Domain\People\Actions\SettleTripSettlement;
use App\Domain\People\Actions\VoidTripSettlement;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
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
 * The settlement desk (the `TripSettled`, the witness). The screen pre-fills the
 * sheet from the trip's own facts — the advance its rows issued, the petty-cash costs that say
 * what the driver's money bought, the driver's daily allowance rate over the trip's days — and
 * the accountant edits what reality says and signs. The Action then enforces only the
 * arithmetic a signature must not break.
 *
 * Pre-filling happens *here*, in the UI, on purpose (D5): People reads Operations through the
 * screen, not through a backwards import; the Action receives the numbers a human confirmed.
 * Settling re-authenticates because `payroll.manage` carries the catalogue's ⚑ — this is
 * money owed to a person, signed into the book.
 */
new #[Title('Settlements')] class extends Component {
    use ConfirmsRecentPassword;
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    public string $tripId = '';

    public string $advanceTotal = '';

    public string $expensesCovered = '';

    public string $cashReturned = '';

    public string $allowance = '';

    public string $settlementNotes = '';

    public string $voidReason = '';

    public ?string $voidingId = null;

    public function updated(string $property): void
    {
        if ($property === 'statusFilter') {
            $this->resetPage();
            unset($this->sheets);
        }

        if ($property === 'tripId' && $this->tripId !== '') {
            $this->prefill();
        }
    }

    private function prefill(): void
    {
        $trip = Trip::query()->where('id', $this->tripId)->first();

        if ($trip === null) {
            return;
        }

        $advance = (int) TripCost::query()->where('trip_id', $trip->id)->where('cost_type', TripCostType::Advance->value)->sum('base_amount');
        $petty = (int) TripCost::query()->where('trip_id', $trip->id)
            ->where('cost_type', '!=', TripCostType::Advance->value)
            ->where('paid_with', TripCostPayment::PettyCash->value)
            ->sum('base_amount');

        $covered = min($advance, $petty);
        $returned = $advance - $covered;

        $rate = $trip->driver_id === null ? 0 : (int) (Driver::query()->find($trip->driver_id)?->default_allowance_rate_base ?? 0);
        $days = match (true) {
            $trip->departed_at === null || $trip->arrived_at === null => 1,
            default => max(1, $trip->departed_at->startOfDay()->diffInDays($trip->arrived_at->startOfDay()) + 1),
        };

        $base = CurrentCompany::model()->base_currency_code;
        // Major units into the inputs, the way every money form here pre-fills (the bill):
        // plain two-decimal strings that `Money::ofMajor` can read back exactly.
        $major = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');

        $this->advanceTotal = $advance === 0 ? '' : $major($advance);
        $this->expensesCovered = $covered === 0 ? '' : $major($covered);
        $this->cashReturned = $returned === 0 ? '' : $major($returned);
        $this->allowance = $rate === 0 ? '' : $major($rate * $days);
    }

    public function openCreate(): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('record', TripSettlement::class), 403);

        $this->reset(['tripId', 'advanceTotal', 'expensesCovered', 'cashReturned', 'allowance', 'settlementNotes']);
        $this->dispatch('open-record-settlement');
    }

    public function clearForm(): void
    {
        $this->reset(['tripId', 'advanceTotal', 'expensesCovered', 'cashReturned', 'allowance', 'settlementNotes']);
        $this->resetValidation();
    }

    public function record(RecordTripSettlement $action): void
    {
        $validated = $this->validate([
            'tripId' => ['required', 'string', 'max:26'],
            'advanceTotal' => ['nullable', 'string', 'max:24'],
            'expensesCovered' => ['nullable', 'string', 'max:24'],
            'cashReturned' => ['nullable', 'string', 'max:24'],
            'allowance' => ['nullable', 'string', 'max:24'],
            'settlementNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $trip = Trip::query()->where('id', $validated['tripId'])->first();

        if ($trip === null || $trip->driver_id === null) {
            $this->dispatch('toast', message: __('That trip is not here or names no driver — a settlement needs a person to settle with.'), kind: 'danger');

            return;
        }

        $base = CurrentCompany::model()->base_currency_code;
        $parse = fn (string $value): int => $value === '' ? 0 : Money::ofMajor($value, $base)->minorUnits;

        try {
            $action->execute(
                tripId: (string) $trip->id,
                driverId: (int) $trip->driver_id,
                advanceTotal: $parse($validated['advanceTotal']),
                expensesCovered: $parse($validated['expensesCovered']),
                cashReturned: $parse($validated['cashReturned']),
                allowance: $parse($validated['allowance']),
                notes: $validated['settlementNotes'] === '' ? null : $validated['settlementNotes'],
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->clearForm();
        $this->dispatch('close-record-settlement');
        unset($this->sheets);
        $this->dispatch('toast', message: __('Settlement sheet recorded. Settle it when the numbers are confirmed.'), kind: 'success');
    }

    public function settle(SettleTripSettlement $action): void
    {
        if (! $this->confirmRecentPassword()) {
            return;
        }

        $sheet = TripSettlement::query()->find($this->settleTargetId ?? '');

        if ($sheet === null) {
            return;
        }

        try {
            $action->execute($sheet, auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        unset($this->sheets);
        $this->dispatch('toast', message: __('Settlement posted — advances cleared, allowance owed to the driver.'), kind: 'success');
    }

    public ?string $settleTargetId = null;

    public function askSettle(string $id): void
    {
        $this->settleTargetId = $id;
    }

    public function askVoid(string $id): void
    {
        $this->voidingId = $id;
        $this->reset('voidReason');
        $this->dispatch('open-void-settlement');
    }

    public function clearVoid(): void
    {
        $this->reset(['voidingId', 'voidReason']);
        $this->resetValidation('voidReason');
    }

    public function void(VoidTripSettlement $action): void
    {
        $validated = $this->validate(['voidReason' => ['required', 'string', 'min:8', 'max:500']]);

        $sheet = $this->voidingId === null ? null : TripSettlement::query()->find($this->voidingId);

        if ($sheet === null) {
            return;
        }

        try {
            $action->execute($sheet, $validated['voidReason'], auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->clearVoid();
        $this->dispatch('close-void-settlement');
        unset($this->sheets);
        $this->dispatch('toast', message: __('Settlement voided.') . ($sheet->entry_id !== null ? ' ' . __('Its journal was reversed.') : ''), kind: 'success');
    }

    /** @return LengthAwarePaginator<int, TripSettlement> */
    #[Computed]
    public function sheets(): LengthAwarePaginator
    {
        return TripSettlement::query()
            ->with('driver')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /** Trips whose drivers held cash: arrived or completed, no live sheet, with the trip_no to pick. */
    #[Computed]
    public function tripsNeedingSheets(): Collection
    {
        $live = TripSettlement::query()->where('status', '!=', TripSettlementStatus::Void->value)->pluck('trip_id')->all();

        return Trip::query()
            ->whereIn('status', [TripStatus::Arrived->value, TripStatus::Delivered->value, TripStatus::Completed->value])
            ->whereNotNull('driver_id')
            ->when($live !== [], fn ($q) => $q->whereNotIn('id', $live))
            ->latest()
            ->limit(30)
            ->get(['id', 'trip_no', 'driver_id']);
    }

    public function tripNo(?string $tripId): string
    {
        if ($tripId === null) {
            return '—';
        }

        return Trip::query()->where('id', $tripId)->value('trip_no') ?? '(gone)';
    }

    public function money(?int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        if ($minorUnits === null) {
            return '—';
        }

        return Money::ofMinor($minorUnits, $base)->format($base);
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ __('Driver settlements') }}</x-ui.heading>
        <x-ui.text>
            {{ __('The sheet that says what the advance really funded, what cash came back, and what allowance the trip earned. Settling posts one entry: advance cleared against petty cash, allowance owed to the driver. A trip that advanced cash cannot close until its sheet is settled.') }}
        </x-ui.text>
    </header>

    <x-ui.card :title="__('Sheets')">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <div class="grid gap-1.5">
                    <label for="status-filter" class="text-xs font-medium text-fg-ink-secondary">{{ __('Status') }}</label>
                    <x-ui.select name="statusFilter" id="status-filter" wire:model.live="statusFilter">
                        <option value="">{{ __('All sheets') }}</option>
                        @foreach (TripSettlementStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button variant="ghost" size="sm" wire:click="$set('statusFilter', '')" class="self-end" :disabled="$statusFilter === ''">{{ __('Clear') }}</x-ui.button>
            </div>

            @can('record', App\Domain\People\Models\TripSettlement::class)
                <x-ui.button size="sm" wire:click="openCreate">{{ __('Record a settlement') }}</x-ui.button>
            @endcan
        </div>

        <x-ui.table :caption="__('Settlement sheets')">
            <thead>
                <tr>
                    <x-ui.table.th>{{ __('Reference') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Trip') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Driver') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Advance') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Covered') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Returned') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Owed still') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Allowance') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->sheets as $sheet)
                    <tr wire:key="st-{{ $sheet->id }}">
                        <x-ui.table.td identifier>{{ $sheet->reference }}</x-ui.table.td>
                        <x-ui.table.td class="text-fg-ink-secondary">
                            <a href="{{ route('operations.trip', ['trip' => $sheet->trip_id], true) }}" wire:navigate class="text-fg-link hover:underline">{{ $this->tripNo($sheet->trip_id) }}</a>
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $sheet->driver?->name ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($sheet->advance_total) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($sheet->expenses_covered) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($sheet->cash_returned) }}</x-ui.table.td>
                        <x-ui.table.td numeric class="{{ $sheet->outstanding_minor > 0 ? 'text-warning' : '' }}">{{ $this->money($sheet->outstanding_minor) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($sheet->allowance) }}</x-ui.table.td>
                        <x-ui.table.td>
                            <x-ui.badge :tone="$sheet->status === TripSettlementStatus::Settled ? 'success' : ($sheet->status === TripSettlementStatus::Open ? 'warning' : 'neutral')">
                                {{ mb_strtolower($sheet->status->label()) }}
                            </x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td numeric>
                            @can('settle', $sheet)
                                <x-ui.button variant="outline" size="sm" wire:click="askSettle('{{ $sheet->id }}')" wire:confirm="{{ __('Settle ') . $sheet->reference . __('? Posts the clear-and-allowance entry and re-authenticates.')}}" class="mr-1">
                                    {{ __('Settle') }}
                                </x-ui.button>
                            @endcan
                            @can('void', $sheet)
                                <x-ui.button variant="ghost" size="sm" wire:click="askVoid('{{ $sheet->id }}')">{{ __('Void') }}</x-ui.button>
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <x-ui.empty-state
                                icon="clipboard-document-list"
                                :title="__('No settlement sheets yet')"
                                :description="__('Trips that advanced driver cash appear here once recorded; a trip with an advance cannot close until its sheet is settled.')"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @if ($this->sheets->hasPages())
            <div class="mt-4">{{ $this->sheets->links() }}</div>
        @endif
    </x-ui.card>

    @can('record', App\Domain\People\Models\TripSettlement::class)
        <x-ui.dialog name="record-settlement" maxWidth="2xl" :title="__('Record a settlement')" x-on:dialog-closed="$wire.call('clearForm')">
            <form class="grid gap-4 p-5" wire:submit="record">
                <x-ui.text size="sm" color="muted">
                    {{ __('Pick the trip and the sheet pre-fills from its rows: the advance it issued, the petty-cash costs that say what the driver\'s money bought, the cash assumed back, and the allowance at the driver\'s daily rate over the trip\'s days. Edit anything reality disagrees with — you are the one signing it.') }}
                </x-ui.text>

                <div class="grid gap-1.5">
                    <label for="tripId" class="text-xs font-medium text-fg-ink-secondary">{{ __('Trip') }}</label>
                    <x-ui.select name="tripId" id="tripId" wire:model.live="tripId">
                        <option value="">{{ __('Choose a trip that held driver cash') }}</option>
                        @foreach ($this->tripsNeedingSheets as $trip)
                            <option value="{{ $trip->id }}">{{ $trip->trip_no }} — {{ $trip->driver?->name ?? \App\Domain\People\Models\Driver::query()->find($trip->driver_id)?->name ?? 'driver' }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('tripId') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="advanceTotal" name="advanceTotal" :label="__('Advance issued')" :hint="__('Major units; blank means none.')" />
                    <x-ui.input wire:model="expensesCovered" name="expensesCovered" :label="__('Expenses the advance covered')" />
                    <x-ui.input wire:model="cashReturned" name="cashReturned" :label="__('Cash returned by the driver')" />
                    <x-ui.input wire:model="allowance" name="allowance" :label="__('Trip allowance')" :hint="__('Days × the driver\'s daily rate, unless reality says otherwise.')" />
                </div>

                <x-ui.input wire:model="settlementNotes" name="settlementNotes" :label="__('Note (optional)')" />

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" type="submit">{{ __('Record sheet') }}</x-ui.button>
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-record-settlement')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>

        <x-ui.dialog name="void-settlement" maxWidth="md" :title="__('Void this settlement')" x-on:dialog-closed="$wire.call('clearVoid')">
            <form class="grid gap-4 p-5" wire:submit="void">
                <x-ui.text size="sm" color="muted">
                    {{ __('A settled sheet is reversed by its mirror entry; an open one simply goes. The numbers stay as evidence either way.') }}
                </x-ui.text>

                <x-ui.input wire:model="voidReason" name="voidReason" :label="__('Why? (8+ characters, audited)')" required />

                <div class="flex items-center gap-3">
                    <x-ui.button variant="danger" type="submit">{{ __('Void settlement') }}</x-ui.button>
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-void-settlement')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan
</div>
