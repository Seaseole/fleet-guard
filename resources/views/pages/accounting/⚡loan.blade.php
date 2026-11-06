<?php

use App\Domain\Accounting\Actions\DisburseLoan;
use App\Domain\Accounting\Actions\PayLoanInstallment;
use App\Domain\Accounting\Actions\TransitionLoan;
use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One facility, its frozen plan, and the doors.
 *
 * The paper moves left to right — draft, submitted, approved, disbursed — and the ledger only
 * appears at the two money moments: disbursement (Dr bank / Cr 2210) and each instalment
 * (principal against 2210, interest against 5500, one Cr on the bank, the split read from the
 * stored row). Paying is never re-computed here: the row says what the plan froze, and the
 * Action allocates the typed amount across that split pro-rata (books may owe less
 * interest, never more).
 *
 * Payment reference is mandatory because it is the idempotency key: the same bank fact
 * arriving twice — a double-click, a retried queue job, a resubmitted form — buys the
 * instalment once.
 */
new #[Title('Loan')] class extends Component {
    public Loan $loan;

    public string $payAmount = '';

    public string $payOn = '';

    public string $payRef = '';

    public string $payingId = '';

    public function mount(Loan $loan): void
    {
        $this->authorize('view', $loan);

        $this->loan = $loan->load('installments', 'supplier', 'bankAccount');
    }

    public function submit(TransitionLoan $transition): void
    {
        $this->guarded(fn () => $transition->submit($this->loan, auth()->id()));
    }

    public function approve(TransitionLoan $transition): void
    {
        $this->guarded(fn () => $transition->approve($this->loan, auth()->id()));
    }

    public function cancel(TransitionLoan $transition): void
    {
        $this->guarded(fn () => $transition->cancel($this->loan, auth()->id()));
    }

    public function disburse(DisburseLoan $action): void
    {
        $this->guarded(fn () => $action->execute($this->loan, Carbon::now(CurrentCompany::timezone()), auth()->id()));
    }

    public function openPay(string $instalmentId): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('manage', $this->loan), 403);

        $row = $this->loan->installments->firstWhere('id', (int) $instalmentId);

        if ($row === null) {
            return;
        }

        $this->payingId = $instalmentId;
        $this->payAmount = '';
        $this->payRef = '';
        $this->payOn = Carbon::now(CurrentCompany::timezone())->toDateString();

        $this->dispatch('open-pay-instalment');
    }

    public function closePay(): void
    {
        $this->reset(['payingId', 'payAmount', 'payRef', 'payOn']);
        $this->resetValidation();
    }

    public function pay(PayLoanInstallment $action): void
    {
        $validated = $this->validate([
            'payAmount' => ['required', 'string', 'max:24'],
            'payOn' => ['required', 'date'],
            'payRef' => ['required', 'string', 'min:3', 'max:80'],
        ]);

        $row = $this->loan->installments->firstWhere('id', (int) $this->payingId);

        if ($row === null) {
            $this->dispatch('toast', message: __('That instalment row is gone — refresh.'), kind: 'danger');

            return;
        }

        $base = CurrentCompany::model()->base_currency_code;
        $minor = Money::ofMajor($validated['payAmount'], $base)->minorUnits;

        try {
            $action->execute($row, $minor, Carbon::parse($validated['payOn']), $validated['payRef'], auth()->id());
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->closePay();
        $this->dispatch('close-pay-instalment');
        $this->loan = $this->loan->fresh()->load('installments', 'supplier', 'bankAccount');
        $this->dispatch('toast', message: __('Instalment payment posted — Dr 2210 / Dr 5500, Cr bank.'), kind: 'success');
    }

    private function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $refused) {
            $this->dispatch('toast', message: $refused->getMessage(), kind: 'danger');

            return;
        }

        $this->loan = $this->loan->fresh()->load('installments', 'supplier', 'bankAccount');
    }

    public function money(?int $minorUnits): string
    {
        $base = CurrentCompany::model()->base_currency_code;

        if ($minorUnits === null) {
            return '—';
        }

        return Money::ofMinor($minorUnits, $base)->format($base);
    }

    public function net(int $minorUnits): string
    {
        return Money::ofMinor($minorUnits, CurrentCompany::model()->base_currency_code)->format(CurrentCompany::model()->base_currency_code);
    }

    public function truckLabel(): ?string
    {
        return $this->loan->truck_id === null ? null : Truck::query()->find($this->loan->truck_id)?->registration;
    }

    public function totals(): array
    {
        $p = 0;
        $i = 0;

        foreach ($this->loan->installments as $row) {
            if ($row->status === InstallmentStatus::Restructured) {
                continue;
            }

            $p += $row->principal_due;
            $i += $row->interest_due;
        }

        return ['principal' => $p, 'interest' => $i, 'total' => $p + $i];
    }
}; ?>

<div class="grid gap-6">
    <header class="fg-page-header grid gap-1.5">
        <x-ui.heading size="xl" level="1">{{ $loan->reference }} — {{ $loan->supplier?->name ?? $loan->purpose->label() }}</x-ui.heading>
        <x-ui.text>
            {{ __(':purpose facility of :principal at :rate% over :count :frequency repayments, first due :first. :status', [
                'purpose' => $loan->purpose->label(),
                'principal' => $this->money($loan->base_principal),
                'rate' => rtrim(rtrim($loan->interest_rate, '0'), '.'),
                'count' => $loan->repayment_count,
                'frequency' => mb_strtolower($loan->frequency->label()),
                'first' => $loan->first_payment_on->format('d M Y'),
                'status' => mb_strtolower($loan->status->label()).($loan->status->isOwed() ? ' — outstanding '.$this->net($loan->outstandingMinor()) : '.'),
            ]) }}
        </x-ui.text>
        @if ($this->truckLabel())
            <x-ui.text size="sm" color="muted">{{ __('Finances truck:truck: its name rides the ledger lines this facility posts.', ['truck' => $this->truckLabel()]) }}</x-ui.text>
        @endif
    </header>

    <x-ui.card :title="__('The paper')">
        @can('manage', $loan)
            <div class="flex flex-wrap items-center gap-3">
                @if ($loan->status === LoanLifecycle::Draft)
                    <x-ui.button variant="outline" size="sm" wire:click="submit">{{ __('Submit for approval') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="cancel" wire:confirm="{{ __('Cancel this draft?') }}">{{ __('Cancel') }}</x-ui.button>
                @elseif ($loan->status === LoanLifecycle::Submitted)
                    <x-ui.button variant="outline" size="sm" wire:click="approve" wire:confirm="{{ __('Approve the terms as frozen? The instalment plan becomes the contract.') }}">{{ __('Approve') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="cancel" wire:confirm="{{ __('Cancel before disbursement?') }}">{{ __('Cancel') }}</x-ui.button>
                @elseif ($loan->status === LoanLifecycle::Approved)
                    <x-ui.button variant="primary" size="sm" wire:click="disburse" wire:confirm="{{ __('Record the disbursement — posts Dr bank / Cr 2210 at today\'s date. The account is :bank.', ['bank' => $loan->bankAccount?->name ?? '(missing — set it first)']) }}">{{ __('Disbursed') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="cancel" wire:confirm="{{ __('Cancel an approved loan? Only legal because no money moved.') }}">{{ __('Cancel') }}</x-ui.button>
                @endif
            </div>
        @else
            <x-ui.text size="sm" color="muted">{{ __('This seat reads the financing book; it does not move it.') }}</x-ui.text>
        @endcan

        @if ($loan->disbursed_on)
            <p class="pt-3 text-xs text-fg-ink-muted">
                {{ __('Disbursed :on · entry :e · repayment account :bank', [
                    'on' => $loan->disbursed_on->format('d M Y'),
                    'e' => $loan->disbursement_entry_id ?? '—',
                    'bank' => $loan->bankAccount?->name ?? '—',
                ]) }}
            </p>
        @endif
        @if ($loan->notes) <p class="pt-1 text-xs text-fg-ink-secondary">{{ $loan->notes }}</p> @endif
    </x-ui.card>

    <x-ui.card :title="__('The plan (the rows are the truth)')">
        <x-ui.table :caption="__('Stored amortisation schedule — read, never recomputed')">
            <thead>
                <tr>
                    <x-ui.table.th>#</x-ui.table.th>
                    <x-ui.table.th>{{ __('Due') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Principal') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Interest') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Paid') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Status') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Actions') }}</x-ui.table.th>
                </tr>
            </thead>
            <tbody>
                @forelse ($loan->installments as $row)
                    <tr wire:key="inst-{{ $row->id }}">
                        <x-ui.table.td identifier>{{ $row->seq }}</x-ui.table.td>
                        <x-ui.table.td>{{ $row->due_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->principal_due) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->interest_due) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->total_due) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($row->paid_principal + $row->paid_interest) }}</x-ui.table.td>
                        <x-ui.table.td>
                            @php($late = $row->status->isOutstanding() && $row->due_on->isPast())
                            <x-ui.badge :tone="$row->status === InstallmentStatus::Paid ? 'success' : ($late ? 'danger' : ($row->status === InstallmentStatus::Restructured ? 'neutral' : 'warning'))">
                                {{ $late && $row->status !== InstallmentStatus::Paid ? 'past due' : mb_strtolower($row->status->label()) }}
                            </x-ui.badge>
                        </x-ui.table.td>
                        <x-ui.table.td numeric>
                            @can('manage', $loan)
                                @if ($row->status->isOutstanding() && $loan->status->isOwed())
                                    <x-ui.button variant="ghost" size="sm" wire:click="openPay('{{ $row->id }}')">{{ __('Pay') }}</x-ui.button>
                                @endif
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr><td colspan="8">{{ __('No plan rows — this loan predates the schedule or was raised without one.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-fg-line font-semibold">
                    <x-ui.table.td colspan="2">{{ __('Plan total') }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->totals()['principal']) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->totals()['interest']) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->totals()['total']) }}</x-ui.table.td>
                    <x-ui.table.td colspan="3"></x-ui.table.td>
                </tr>
            </tfoot>
        </x-ui.table>
    </x-ui.card>

    @can('manage', $loan)
        <x-ui.dialog name="pay-instalment" maxWidth="md" :title="__('Pay instalment')" x-on:dialog-closed="$wire.call('closePay')">
            <form class="grid gap-4 p-5" wire:submit="pay">
                <x-ui.text size="sm" color="muted">
                    {{ __('The amount is allocated across the row\'s frozen principal/interest split and posts Dr 2210 / Dr 5500 / Cr:bank. The reference is the idempotency key: the same bank fact twice posts once.', ['bank' => $loan->bankAccount?->name?? 'the bank']) }}
                </x-ui.text>

                <x-ui.input wire:model="payAmount" name="payAmount" :label="__('Amount')" :hint="__('Blank-check it against the row: the Action refuses more than the outstanding figure.')" required />
                <x-ui.input wire:model="payOn" name="payOn" type="date" :label="__('Paid on')" required />
                <x-ui.input wire:model="payRef" name="payRef" :label="__('Payment reference')" :hint="__('RTGS / slip number — one per real movement of money.')" required />

                <div class="flex items-center gap-3">
                    <x-ui.button variant="primary" type="submit">{{ __('Post payment') }}</x-ui.button>
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-pay-instalment')">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endcan
</div>
