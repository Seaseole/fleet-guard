<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Pages;

use App\Domain\Accounting\Actions\RecordSupplierPayment;
use App\Domain\Accounting\Actions\VoidBill;
use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\SupplierPaymentAllocation;
use App\Domain\Accounting\SupplierPaymentMethod;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One bill, exactly as the ledger received it: the supplier's document number, the lines with
 * the account each one debited, the entry the posting left behind, and the money that has since gone out.
 *
 * The ledger summary is `LedgerService::totalsByAccount` narrowed by the `bill_id` dimension —
 * the same one door every other read of the book goes through; this page does not re-add the lines
 * and hope they match the entry. After a void the pair nets to zero and the summary says so; after a
 * payment the debit on `2110` is the one the payment wrote, and the same card shows it.
 *
 * "Still owed" here is the balance projection of the application rows, never an edited status: a bill
 * whose due date has passed says so in words, and one that has been paid refuses its own void, because
 * unposting a liability the bank has discharged would leave the cash nowhere to sit (the rule).
 */
new #[Title('Bill')] class extends Component {
    public string $billId = '';

    public string $voidReason = '';

    public string $payAmount = '';

    public string $payMethod = 'bank';

    public string $payReference = '';

    public string $payOn = '';

    public ?string $error = null;

    public ?string $paid = null;

    public function mount(Bill $bill): void
    {
        $this->billId = (string) $bill->id;
        $now = Carbon::now();
        $this->payOn = $now->toDateString();
        // Prefilled with what is still owed: the common case is paying the paper in full, and a blank
        // box invites a clerk to retype a figure the screen already knows (the minor units, typed).
        $this->payAmount = number_format($bill->balance_amount / 100, 2, '.', '');
    }

    /**
     * Re-read on every action rather than trusting the Livewire snapshot: a void from another tab must
     * be visible here, and a refusal must leave the screen showing the row that actually exists.
     */
    #[Computed]
    public function billModel(): Bill
    {
        return Bill::query()->with('supplier')->whereKey($this->billId)->firstOrFail();
    }

    #[Computed]
    public function truckLabels(): array
    {
        $ids = $this->billModel->lines->pluck('truck_id')->filter()->unique()->all();

        return $ids === []
            ? []
            : DB::table('trucks')->whereIn('id', $ids)->pluck('registration_no', 'id')->all();
    }

    #[Computed]
    public function tripLabels(): array
    {
        $ids = $this->billModel->lines->pluck('trip_id')->filter()->unique()->all();

        return $ids === []
            ? []
            : DB::table('trips')->whereIn('id', $ids)->where('company_id', CurrentCompany::id())->pluck('trip_no', 'id')->all();
    }

    #[Computed]
    public function ledgerRows(): array
    {
        return app(LedgerService::class)->totalsByAccount(['bill_id' => $this->billModel->id]);
    }

    public function void(VoidBill $action): void
    {
        $this->error = null;

        if (! Gate::allows('bills.create')) {
            $this->error = __('Voiding a supplier bill needs the bills.create ability.');

            return;
        }

        $validated = $this->validate([
            'voidReason' => ['required', 'min:10', 'max:300'],
        ]);

        try {
            $action($this->billModel, $validated['voidReason'], auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->billModel, $this->ledgerRows);
        $this->reset('voidReason');
    }

    /** The applications that decided this bill's balance, newest last — the truth behind the projection. */
    #[Computed]
    public function payments(): \Illuminate\Support\Collection
    {
        return SupplierPaymentAllocation::query()
            ->with('payment')
            ->where('bill_id', $this->billModel->id)
            ->orderBy('applied_on')
            ->get();
    }

    public function pay(RecordSupplierPayment $action): void
    {
        $this->error = null;
        $this->paid = null;

        if (! Gate::allows('bills.create')) {
            $this->error = __('Paying a supplier bill needs the bills.create ability.');

            return;
        }

        $validated = $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01'],
            'payMethod' => ['required', 'in:'.implode(',', SupplierPaymentMethod::values())],
            'payOn' => ['required', 'date'],
        ]);

        $bill = $this->billModel;
        $currency = CurrentCompany::model()->base_currency_code;

        try {
            $amount = Money::ofMajor($validated['payAmount'], $currency);
        } catch (\DomainException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        try {
            $payment = $action->execute(
                supplierId: (int) $bill->supplier_id,
                amountMinor: $amount->minorUnits,
                method: SupplierPaymentMethod::from($validated['payMethod']),
                paidOn: Carbon::parse($validated['payOn']),
                billIds: [(string) $bill->id],
                reference: $this->payReference !== '' ? $this->payReference : null,
                actorId: auth()->id(),
            );
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->billModel, $this->ledgerRows, $this->payments);
        $this->reset(['payAmount', 'payReference']);
        $this->paid = $payment->number;
    }

    public function money(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    public function lineGross(\App\Domain\Accounting\Models\BillLine $line): int
    {
        return $line->net_amount + $line->tax_amount;
    }
}; ?>

<div class="grid gap-6">
    @php $bill = $this->billModel; @endphp

    <header class="fg-page-header grid gap-1.5">
        <div class="flex items-center gap-3">
            <x-ui.button variant="ghost" size="sm" :href="route('accounting.bills')" wire:navigate>{{ __('Back to register') }}</x-ui.button>
            <x-ui.badge :tone="$bill->status === BillLifecycle::Void ? 'neutral' : ($bill->status === BillLifecycle::Draft ? 'warning' : ($bill->balance_amount === 0 ? 'success' : ($bill->isOverdue() ? 'danger' : 'info')))">
                {{ $bill->status === BillLifecycle::Recorded ? $bill->settlementState()->label() : $bill->status->label() }}
            </x-ui.badge>
        </div>
        <x-ui.heading size="xl" level="1">{{ $bill->number }}</x-ui.heading>
        <x-ui.text>
            {{ __(':supplier · their document :doc · billed :on, due :due', [
                'supplier' => $bill->supplier?->name ?? '—',
                'doc' => $bill->supplier_doc_no ?? 'not stated',
                'on' => $bill->bill_on->format('d M Y'),
                'due' => $bill->due_on->format('d M Y'),
            ]) }}
        </x-ui.text>
        @if ($bill->linked_source !== null)
            <x-ui.text>
                {{ __('This paper documents a:kind the ledger already owes — it posted nothing of its own.', ['kind' => mb_strtolower($bill->linked_source->label())]) }}
            </x-ui.text>
        @endif
        @if ($bill->status->isOpenItem())
            <x-ui.text>
                {{ __('Billed :gross · still owed :balance', [
                    'gross' => $this->money($bill->amount),
                    'balance' => $this->money($bill->balance_amount),
                ]) }}
                @if ($bill->isOverdue())
                    <span class="text-fg-danger">{{ __('· past due since :due', ['due' => $bill->due_on->format('d M Y')]) }}</span>
                @endif
            </x-ui.text>
        @endif
    </header>

    @if ($error)
        <x-ui.alert tone="danger" :title="__('That action was refused')">{{ $error }}</x-ui.alert>
    @endif

    @if ($paid)
        <x-ui.alert tone="success" :title="__('Payment recorded')">{{ __(':number left the till and has been applied to this bill and, if any remained, to the oldest other paper of the same supplier.', ['number' => $paid]) }}</x-ui.alert>
    @endif

    @if ($bill->note)
        <x-ui.alert tone="info" :title="__('Note on the document')">{{ $bill->note }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Lines')">
        <x-ui.table :caption="__('Lines of bill :number', ['number' => $bill->number])">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('What was done') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Category') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th hideBelow="lg">{{ __('Leg') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Net') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('VAT') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Gross') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($bill->lines as $line)
                <tr wire:key="bill-line-{{ $line->id }}">
                    <x-ui.table.td>
                        {{ $line->description }}
                        @if ($line->released_at !== null)
                            <div class="text-xs text-fg-ink-muted">{{ __('released by the void') }}</div>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td hideBelow="lg">{{ $line->category->label() }}</x-ui.table.td>
                    <x-ui.table.td><span class="fg-identifier">{{ $line->expense_account_code }}</span></x-ui.table.td>
                    <x-ui.table.td hideBelow="lg">
                        @if ($line->truck_id !== null)
                            <div>{{ $this->truckLabels[$line->truck_id] ?? 'truck '.$line->truck_id }}</div>
                        @endif
                        @if ($line->trip_id !== null)
                            <div class="text-xs text-fg-ink-muted">{{ $this->tripLabels[$line->trip_id] ?? $line->trip_id }}</div>
                        @endif
                        @if ($line->truck_id === null && $line->trip_id === null)
                            —
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($line->net_amount) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($line->tax_amount) }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($this->lineGross($line)) }}</x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <x-ui.table.td colspan="7">{{ __('A draft bill has no lines yet.') }}</x-ui.table.td>
                </tr>
            @endforelse
            <tr wire:key="bill-totals">
                <x-ui.table.td colspan="2" class="font-medium">{{ __('Bill total (gross)') }}</x-ui.table.td>
                {{-- The spacer covers the two columns that drop below lg; without it the totals row's figures
                     would sit two slots right of the figures they add up. --}}
                <x-ui.table.td colspan="2" hideBelow="lg"></x-ui.table.td>
                <x-ui.table.td numeric>{{ $this->money($bill->amount - $bill->tax_amount) }}</x-ui.table.td>
                <x-ui.table.td numeric>{{ $this->money($bill->tax_amount) }}</x-ui.table.td>
                <x-ui.table.td numeric class="font-medium">{{ $this->money($bill->amount) }}</x-ui.table.td>
            </tr>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('What the ledger holds')":description="__('The entry narrowed by this bill\'s dimension — read through the ledger door, not re-added here.')">
        <x-ui.table :caption="__('Ledger turnover for :number', ['number' => $bill->number])">
            <x-slot name="head">
                <tr>
                    <x-ui.table.th>{{ __('Account') }}</x-ui.table.th>
                    <x-ui.table.th>{{ __('Side') }}</x-ui.table.th>
                    <x-ui.table.th numeric>{{ __('Base amount') }}</x-ui.table.th>
                </tr>
            </x-slot>
            @forelse ($this->ledgerRows as $row)
                <tr wire:key="bill-ledger-{{ $row->accountCode }}-{{ $row->side->value }}">
                    <x-ui.table.td><span class="fg-identifier">{{ $row->accountCode }}</span></x-ui.table.td>
                    <x-ui.table.td>{{ $row->side->value }}</x-ui.table.td>
                    <x-ui.table.td numeric>{{ $this->money($row->amountBase) }}</x-ui.table.td>
                </tr>
            @empty
                <tr>
                    <x-ui.table.td colspan="3">
                        @if ($bill->linked_source !== null)
                            {{ __('This bill documents an obligation Phase 3 already posted, so it wrote no entry of its own — the turnover under the movement is on the fuel card or the parts register.') }}
                        @else
                            {{ __('The ledger holds nothing under this bill — a draft posts nothing.') }}
                        @endif
                    </x-ui.table.td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($this->payments->isNotEmpty())
        <x-ui.card :title="__('Money that has gone out')":description="__('The application rows are the truth of the balance above; the projection is only what makes it a query (copied sideways).')">
            <x-ui.table :caption="__('Payments applied to :number', ['number' => $bill->number])">
                <x-slot name="head">
                    <tr>
                        <x-ui.table.th>{{ __('Payment') }}</x-ui.table.th>
                        <x-ui.table.th nowrap>{{ __('Paid') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Method') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Reference') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Applied here') }}</x-ui.table.th>
                    </tr>
                </x-slot>
                @foreach ($this->payments as $allocation)
                    <tr wire:key="bill-payment-{{ $allocation->id }}">
                        <x-ui.table.td><span class="fg-identifier">{{ $allocation->payment?->number ?? '—' }}</span></x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $allocation->applied_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td>{{ $allocation->payment?->method->label() ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $allocation->payment?->reference ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td numeric>
                            {{ $this->money($allocation->amount) }}
                            @if ($allocation->reversed)
                                <div class="text-xs text-fg-danger">{{ __('reversed') }}</div>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($bill->status === BillLifecycle::Recorded && $bill->balance_amount > 0)
        @can('bills.create')
            <x-ui.card :title="__('Pay this bill')":description="__('Money leaves the till as Dr 2110 / Cr the chosen account and is applied here first; anything over settles the supplier\'s next-oldest paper, and a figure no bill has claimed stays as an advance on 2110.')">
                <form wire:submit="pay" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    <x-ui.input type="number" step="0.01" min="0" name="payAmount" :label="__('Amount')" :hint="__('Prefilled with the balance; type less to pay part of it.')" wire:model="payAmount" required />

                    <div class="grid gap-1.5">
                        <label for="pay-method" class="text-xs font-medium text-fg-ink-secondary">{{ __('From') }}</label>

                        <x-ui.select name="payMethod" id="pay-method" wire:model="payMethod">
                            @foreach (SupplierPaymentMethod::cases() as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input type="date" name="payOn" :label="__('Paid on')" wire:model="payOn" required />
                    <x-ui.input name="payReference" :label="__('Bank reference')" :hint="__('What the statement will say.')" wire:model="payReference" />

                    <x-ui.button type="submit" variant="primary">{{ __('Pay') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endcan
    @endif

    @if ($bill->status === BillLifecycle::Recorded)
        @can('bills.create')
            @if ($bill->hasPayments())
                <x-ui.alert tone="warning" :title="__('This bill cannot be voided')">
                    {{ __('Money has already been applied to it, so the payable is partly settled and unposting the debt would leave the cash nowhere to sit. A paid bill is corrected by a debit note.') }}
                </x-ui.alert>
            @else
                <x-ui.card :title="__('Void this bill')":description="__('Mirrors the posting through the ledger\'s own reversal door and releases the lines, or releases the movement this paper documented. The document keeps its number and its record — it is never deleted.')">
                    <form wire:submit="void" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <x-ui.input name="voidReason" :label="__('Reason')" :hint="__('At least ten characters; stored on the document and in the operational trail.')" wire:model="voidReason" required />
                        <x-ui.button type="submit" variant="danger">{{ __('Void bill') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        @endcan
    @elseif ($bill->status === BillLifecycle::Void)
        <x-ui.alert tone="neutral" :title="__('Voided')">
            {{ __(':reason — recorded :on', ['reason' => $bill->void_reason, 'on' => $bill->voided_at?->format('d M Y') ?? '—']) }}
        </x-ui.alert>
    @endif
</div>
