<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\AllocatePayment;
use App\Domain\Invoicing\Actions\DiscardManualInvoice;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueManualInvoice;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\VoidInvoice;
use App\Domain\Invoicing\Data\CreditNoteDto;
use App\Domain\Invoicing\Data\PaymentDto;
use App\Domain\Invoicing\Documents\InvoiceDocuments;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Mail\InvoiceIssuedMail;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\PaymentAllocation;
use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One invoice, its money and the three things that may happen to it.
 *
 * The screen shows the three axes the design keeps apart — lifecycle, derived settlement state, derived ageing
 * bucket — because most arguments about an invoice are really an argument about which of the three someone
 * meant. Nothing here writes a state: every button calls the Action that owns the transition, and a refusal
 * from that Action is displayed rather than pre-empted, so the screen and a script get the same sentence.
 *
 * The money panel deliberately separates **recording a receipt** from **allocating it**: Banks money
 * into `2150` first, and an overpayment is a real balance on the customer's account rather than an
 * adjustment to this invoice. "Record and apply" does both in order, and if the allocation is refused the
 * receipt still exists — which is the correct outcome, not a partial failure.
 */
new #[Title('Invoice')] class extends Component {
    use ConfirmsRecentPassword;

    public string $invoiceId = '';

    public string $voidReason = '';

    public string $receiptAmount = '';

    public string $receiptMethod = 'bank';

    public string $receiptReference = '';

    public string $receiptRate = '';

    public string $receiptBase = '';

    public string $discardReason = '';

    public string $receiptOn = '';

    public string $creditLineId = '';

    public string $creditAmount = '';

    public string $creditReason = 'over_billed';

    public string $creditNarrative = '';

    public ?string $error = null;

    public ?string $status = null;

    public function mount(Invoice $invoice): void
    {
        $this->invoiceId = (string) $invoice->id;
        $this->receiptOn = Carbon::now()->toDateString();
        $this->creditLineId = (string) ($invoice->lines()->value('id') ?? '');
    }

    /**
     * Re-read on every action rather than trusting the Livewire snapshot: a receipt allocated from another
     * tab must be visible here, and an Action's refusal must leave the screen showing the row that actually
     * exists (the "derived, never stored" applies to the screen too).
     */
    #[Computed]
    public function invoice(): Invoice
    {
        return Invoice::query()->with(['lines', 'customer'])->findOrFail($this->invoiceId);
    }

    /** @return \Illuminate\Support\Collection<int, PaymentAllocation> */
    #[Computed]
    public function allocations(): \Illuminate\Support\Collection
    {
        return PaymentAllocation::query()
            ->with('payment')
            ->where('invoice_id', $this->invoiceId)
            ->orderBy('allocated_on')
            ->orderBy('created_at')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, CreditNote> */
    #[Computed]
    public function creditNotes(): \Illuminate\Support\Collection
    {
        return CreditNote::query()
            ->where('invoice_id', $this->invoiceId)
            ->orderBy('issue_date')
            ->get();
    }

    public function money(int $minor): string
    {
        $currency = (string) $this->invoice->currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    /** What the books carry, in the currency the ledger is kept in (the second half). */
    public function baseMoney(int $minor): string
    {
        $currency = CurrentCompany::model()->base_currency_code;

        return Money::ofMinor($minor, $currency)->format($currency);
    }

    public function canVoid(): bool
    {
        return Gate::allows('invoices.void')
            && $this->invoice->status === InvoiceLifecycle::Issued
            && $this->invoice->balance_amount === $this->invoice->grossMinor()
            && $this->invoice->balance_amount > 0;
    }

    public function void(VoidInvoice $void): void
    {
        $this->clearMessages();

        abort_unless(Gate::allows('invoices.void'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        $this->validate(['voidReason' => ['required', 'string', 'min:10', 'max:300']], [
            'voidReason.min' => __('Say why in at least ten characters — a void without a reason is an edit nobody can audit.'),
        ]);

        try {
            $void($this->invoice, $this->voidReason, auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->voidReason = '';
        $this->status = __('The invoice is void. Its number is kept and its posting is mirrored; the delivery is billable again.');

        unset($this->invoice, $this->allocations);
    }

    /**
     * The fiscal act for a hand-raised document: the number is spent here, VAT is computed at the
     * rate effective today, and the receivable is posted. Everything before this was a draft with no
     * existence in the books, so this is the one button on the screen that cannot be undone — which is
     * why it asks for the password again and mirrors nothing.
     */
    public function issue(IssueManualInvoice $issue): void
    {
        $this->clearMessages();

        abort_unless(Gate::allows('invoices.issue'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        try {
            $issued = $issue($this->invoice, auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->status = __(':number is issued and posted. The receivable is on the ledger and the ageing clock starts today.', [
            'number' => $issued->reference(),
        ]);

        unset($this->invoice, $this->allocations);
    }

    /**
     * A draft that should not have been raised is discarded: no number was spent and nothing was
     * posted, so there is no mirror to write and no row to keep (the design refuses a void on a draft for
     * exactly this reason). The reason is required because the row disappears and the trail is all
     * that stays.
     */
    public function discard(DiscardManualInvoice $discard): void
    {
        $this->clearMessages();

        abort_unless(Gate::allows('invoices.draft'), 403);

        $this->validate(['discardReason' => ['required', 'string', 'min:10', 'max:300']], [
            'discardReason.min' => __('Say why in at least ten characters — a discard with no reason is a document nobody can explain.'),
        ]);

        try {
            $discard($this->invoice, $this->discardReason, auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->dispatch('toast', message: __('The draft is discarded and its lines with it.'), kind: 'success');

        $this->redirectRoute('invoicing.index');
    }

    public function isDraft(): bool
    {
        return $this->invoice->status === InvoiceLifecycle::Draft;
    }

    public function isForeign(): bool
    {
        return (string) $this->invoice->currency_code !== CurrentCompany::model()->base_currency_code;
    }

    /**
     * Record the receipt, then apply what the customer owes. The receipt is banked even if allocation is
     * refused: the money exists whether or not this invoice is the one it was for.
     */
    public function recordAndApply(RecordPayment $record, AllocatePayment $allocate): void
    {
        $this->clearMessages();

        abort_unless(Gate::allows('payments.allocate'), 403);

        if (! $this->confirmRecentPassword()) {
            return;
        }

        $validated = $this->validate([
            'receiptAmount' => ['required', 'string', 'max:24'],
            'receiptMethod' => ['required', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'receiptOn' => ['required', 'date'],
            'receiptReference' => ['nullable', 'string', 'max:80'],
            'receiptRate' => ['nullable', 'string', 'max:20'],
            'receiptBase' => ['nullable', 'string', 'max:24'],
        ]);

        $currency = (string) $this->invoice->currency_code;
        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $isForeign = $currency !== $baseCurrency;
        $minor = Money::ofMajor($validated['receiptAmount'], $currency)->minorUnits;

        if ($minor <= 0) {
            $this->error = __('A receipt is a positive amount. Nothing arrived if it is zero.');

            return;
        }

        try {
            $payment = $record(new PaymentDto(
                customerId: $this->invoice->customer_id,
                amountMinor: $minor,
                method: PaymentMethod::from($validated['receiptMethod']),
                receivedOn: Carbon::parse($validated['receiptOn']),
                reference: $validated['receiptReference'] === '' ? null : $validated['receiptReference'],
                // The receipt is banked in the currency the invoice was billed in, and the
                // desk states the conversion the way the bank gave it — the rate it struck, or the
                // base it actually took. Neither figure typed on a foreign receipt is a refusal,
                // not a guess: only the paper says how many Pula arrived.
                currencyCode: $currency,
                fxRate: $isForeign && $validated['receiptRate'] !== '' ? $validated['receiptRate'] : null,
                baseAmountMinor: $isForeign && $validated['receiptBase'] !== ''
                    ? Money::ofMajor($validated['receiptBase'], $baseCurrency)->minorUnits
                    : null,
                fxRateSource: $validated['receiptRate'] !== '' ? FxRateSource::BankStatement->value : FxRateSource::Manual->value,
            ), auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        try {
            $settled = $allocate($this->invoice, auth()->id());
            $this->status = __('Receipt :number banked and applied. Balance now :balance.', [
                'number' => $payment->number,
                'balance' => $this->money($settled->balance_amount),
            ]);
        } catch (\Throwable $refused) {
            $this->status = __('Receipt :number banked with :amount unapplied. :reason', [
                'number' => $payment->number,
                'amount' => $this->money($payment->unapplied_amount),
                'reason' => $refused->getMessage(),
            ]);
        }

        $this->receiptAmount = '';
        $this->receiptReference = '';
        $this->receiptRate = '';
        $this->receiptBase = '';

        unset($this->invoice, $this->allocations);
    }

    public function issueCredit(IssueCreditNote $credit): void
    {
        $this->clearMessages();

        $validated = $this->validate([
            'creditLineId' => ['required', 'string', 'exists:invoice_lines,id'],
            'creditAmount' => ['required', 'string', 'max:24'],
            'creditReason' => ['required', 'string', 'max:32'],
            'creditNarrative' => ['nullable', 'string', 'max:500'],
        ]);

        $line = InvoiceLine::query()->whereKey($validated['creditLineId'])->where('invoice_id', $this->invoiceId)->first();

        if ($line === null) {
            $this->error = __('That line is not on this invoice.');

            return;
        }

        $currency = (string) $this->invoice->currency_code;

        try {
            $note = $credit($this->invoice, new CreditNoteDto(
                reason: $validated['creditReason'],
                lines: [$line->id => Money::ofMajor($validated['creditAmount'], $currency)->minorUnits],
                narrative: $validated['creditNarrative'] === '' ? null : $validated['creditNarrative'],
            ), auth()->id());
        } catch (\Throwable $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->creditAmount = '';
        $this->creditNarrative = '';
        $this->status = __('Credit note :number issued and applied.', ['number' => $note->number]);

        unset($this->invoice, $this->creditNotes);
    }

    /**
     * The delivery. The PDF is rendered here, while the company is pinned, and travels on the mailable
     * with every word it prints — a worker has no tenant context.
     */
    public function email(InvoiceDocuments $documents): void
    {
        $this->clearMessages();

        if (! Gate::allows('invoices.issue')) {
            $this->error = __('Sending a document to a customer needs the invoices.issue ability.');

            return;
        }

        $invoice = $this->invoice;
        $email = $invoice->customer?->contact_email;

        if ($email === null || $email === '') {
            $this->error = __('This customer has no contact email on file, so there is nowhere to send the invoice.');

            return;
        }

        if ($invoice->status === InvoiceLifecycle::Draft) {
            $this->error = __('A draft has no number and no posting. Issue it before sending it.');

            return;
        }

        $company = CurrentCompany::model();
        $pdf = $documents->invoice($invoice);

        Mail::to($email)->queue(new InvoiceIssuedMail(
            documentNumber: $invoice->reference(),
            customerName: (string) $invoice->customer?->name,
            companyName: $company->name,
            totalFormatted: Money::ofMinor($invoice->balance_amount, (string) $invoice->currency_code)
                ->format((string) $invoice->currency_code),
            dueOn: $invoice->due_on->format('d M Y'),
            kindLabel: $invoice->kind->label(),
            pdf: $pdf,
            filename: str_replace(['/', '\\'], '-', $invoice->reference()).'.pdf',
        ));

        $this->status = __('Queued for :email.', ['email' => $email]);
    }

    private function clearMessages(): void
    {
        $this->error = null;
        $this->status = null;
        $this->resetValidation();
    }
}; ?>

<div class="grid gap-6">
    @php($invoice = $this->invoice)

    <header class="fg-page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="grid gap-1">
            <x-ui.heading size="xl" level="1">{{ $invoice->reference() }}</x-ui.heading>
            <x-ui.text>
                {{ $invoice->customer?->name ?? '—' }}
                · {{ $invoice->kind->label() }}
                · {{ $invoice->status === InvoiceLifecycle::Draft
                    ? __('raised :date', ['date' => $invoice->issue_date->format('d M Y')])
                    : __('issued :date', ['date' => $invoice->issue_date->format('d M Y')]) }}
                · {{ __('due :date', ['date' => $invoice->due_on->format('d M Y')]) }}
            </x-ui.text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge :tone="$invoice->status === \App\Domain\Invoicing\InvoiceLifecycle::Void ? 'neutral' : ($invoice->status === InvoiceLifecycle::Draft ? 'info' : 'success')">
                {{ $invoice->status->label() }}
            </x-ui.badge>

            {{-- A draft is owed nothing and has no number, so it has no settlement or ageing axis —
                 printing them would say the books hold a receivable they do not. --}}
            @if ($invoice->status !== InvoiceLifecycle::Draft)
                <x-ui.badge :tone="$invoice->settlementState() === \App\Domain\Invoicing\SettlementState::Paid ? 'success' : ($invoice->balance_amount > 0 ? 'warning' : 'neutral')">
                    {{ $invoice->settlementState()->label() }}
                </x-ui.badge>
                <x-ui.badge tone="neutral">{{ $invoice->ageingState()->label() }}</x-ui.badge>
            @endif

            @if (! $this->isDraft())
                <x-ui.button variant="outline" :href="route('invoicing.invoices.pdf', ['invoice' => $invoice->id])">
                    {{ __('Download PDF') }}
                </x-ui.button>
            @endif

            @can('invoices.issue')
                @if (! $this->isDraft())
                    <x-ui.button variant="outline" wire:click="email" wire:loading.attr="disabled">{{ __('Email to customer') }}</x-ui.button>
                @endif
            @endcan

            <x-ui.button variant="ghost" :href="route('invoicing.index')" wire:navigate>{{ __('Back to register') }}</x-ui.button>
        </div>
    </header>

    @if ($error)
        <x-ui.alert tone="danger" :title="__('That did not happen')">{{ $error }}</x-ui.alert>
    @endif

    @if ($status)
        <x-ui.alert tone="success">{{ $status }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.card :title="__('Lines')">
            <x-ui.table :caption="__('Invoice lines')">
                <x-slot name="head">
                    <tr>
                        <x-ui.table.th>{{ __('Description') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Kind') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Net') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('VAT') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    </tr>
                </x-slot>
                @foreach ($invoice->lines as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <x-ui.table.td>{{ $line->description }}</x-ui.table.td>
                        <x-ui.table.td>{{ $line->line_kind->label() }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($line->amount) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($line->tax_amount) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($line->amount + $line->tax_amount) }}</x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4 grid justify-end gap-1 text-sm">
                <div class="flex justify-between gap-8"><span class="text-fg-ink-muted">{{ __('Net') }}</span><span class="fg-numeric">{{ $this->money($invoice->amount) }}</span></div>
                <div class="flex justify-between gap-8"><span class="text-fg-ink-muted">{{ __('VAT') }}</span><span class="fg-numeric">{{ $this->money($invoice->tax_amount) }}</span></div>
                <div class="flex justify-between gap-8 font-medium"><span>{{ __('Total') }}</span><span class="fg-numeric">{{ $this->money($invoice->grossMinor()) }}</span></div>
                <div class="flex justify-between gap-8"><span class="text-fg-ink-muted">{{ __('Outstanding') }}</span><span class="fg-numeric">{{ $this->money($invoice->balance_amount) }}</span></div>
            </div>
        </x-ui.card>

        <div class="grid gap-6">
            <x-ui.card :title="__('Parties')">
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Customer') }}</dt><dd>{{ $invoice->customer?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Terms') }}</dt><dd>{{ \App\Domain\Kernel\Commercial\PaymentTerms::label($invoice->terms_snapshot) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Currency') }}</dt><dd>{{ $invoice->currency_code }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Issued by') }}</dt><dd>{{ $invoice->issued_at?->format('d M Y H:i') ?? '—' }}</dd></div>

                    {{-- the block, printed rather than re-derived: the rate the document froze, and
                         the base the books carry, which is the pair a realised difference is measured
                         against when the money lands. --}}
                    @if ($this->isForeign())
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Rate') }}</dt><dd class="fg-numeric">1 {{ $invoice->currency_code }} = {{ $invoice->fx_rate }} {{ $invoice->base_currency_code }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Rate source') }}</dt><dd>{{ str_replace('_', ' ', (string) $invoice->fx_rate_source) }} · {{ $invoice->fx_rate_date?->format('d M Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('In the books') }}</dt><dd class="fg-numeric">{{ $this->baseMoney($invoice->base_amount) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Still owed (books)') }}</dt><dd class="fg-numeric">{{ $this->baseMoney($invoice->balance_base) }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            @if ($this->isDraft())
                <x-ui.card :title="__('This is a draft')">
                    <x-ui.text size="sm" color="muted">
                        {{ __('No fiscal number is spent and nothing is posted until you issue it. A draft that is wrong is discarded — there is nothing in the books to correct.') }}
                    </x-ui.text>

                    <dl class="mt-3 grid gap-2 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Raised on') }}</dt><dd>{{ $invoice->issue_date->format('d M Y') }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-fg-ink-muted">{{ __('Lines') }}</dt><dd>{{ $invoice->lines->count() }}</dd></div>
                    </dl>

                    @can('invoices.issue')
                        <div class="mt-4">
                            <x-ui.button
                                variant="outline"
                                wire:click="issue"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ __('Issue this draft? It spends the next fiscal number and posts the receivable today.') }}"
                            >{{ __('Issue and post') }}</x-ui.button>
                        </div>
                    @endcan

                    @can('invoices.draft')
                        <form wire:submit="discard" class="mt-4 grid gap-3">
                            <x-ui.input name="discardReason" :label="__('Why is this draft discarded?')" :hint="__('At least ten characters; the row disappears, the trail does not')" wire:model="discardReason" required />

                            <x-ui.button
                                type="submit"
                                variant="danger"
                                wire:confirm="{{ __('Discard this draft and its lines? Nothing was posted, so there is nothing to reverse.') }}"
                            >{{ __('Discard draft') }}</x-ui.button>
                        </form>
                    @endcan
                </x-ui.card>
            @endif

            @if ($invoice->status === InvoiceLifecycle::Void)
                <x-ui.card :title="__('Voided')">
                    <p class="text-sm text-fg-ink-secondary">{{ $invoice->void_reason }}</p>
                    <p class="mt-2 text-xs text-fg-ink-muted">{{ $invoice->voided_at?->format('d M Y H:i') }}</p>
                </x-ui.card>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Receipts applied')">
            <x-ui.table :caption="__('Payment allocations')">
                <x-slot name="head">
                    <tr>
                        <x-ui.table.th>{{ __('Receipt') }}</x-ui.table.th>
                        <x-ui.table.th nowrap>{{ __('Allocated') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Amount') }}</x-ui.table.th>
                    </tr>
                </x-slot>
                @forelse ($this->allocations as $allocation)
                    <tr wire:key="allocation-{{ $allocation->id }}">
                        <x-ui.table.td>
                            <span class="fg-identifier">{{ $allocation->payment?->number ?? '—' }}</span>
                            @if ($allocation->settled)
                                <div class="text-xs text-fg-ink-muted">{{ __('closed the invoice') }}</div>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $allocation->allocated_on->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($allocation->amount) }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <x-ui.table.td colspan="3" class="text-sm text-fg-ink-muted">{{ __('No money has been applied to this invoice.') }}</x-ui.table.td>
                    </tr>
                @endforelse
            </x-ui.table>

            @can('payments.allocate')
                @if ($invoice->status === InvoiceLifecycle::Issued && $invoice->balance_amount > 0)
                    <form wire:submit="recordAndApply" class="mt-4 grid gap-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.input name="receiptAmount" :label="__('Amount')" :hint="__('Major units, e.g. 12 500.00')" wire:model="receiptAmount" numeric />
                            <x-ui.input type="date" name="receiptOn" :label="__('Received on')" wire:model="receiptOn" />
                        </div>

                        <div class="grid gap-1.5">
                            <label for="receipt-method" class="text-xs font-medium text-fg-ink-secondary">{{ __('Method') }}</label>
                            <x-ui.select name="receiptMethod" id="receipt-method" wire:model="receiptMethod">
                                @foreach (PaymentMethod::options() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        @if ($this->isForeign())
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-ui.input
                                    name="receiptRate"
                                    :label="__('Rate the bank struck — 1 :code in :base', ['code' => $invoice->currency_code, 'base' => $invoice->base_currency_code])"
                                    :hint="__('The money was converted at this figure when it landed.')"
                                    wire:model="receiptRate"
                                    numeric
                                />

                                <x-ui.input
                                    name="receiptBase"
                                    :label="__('…or the :base the bank took', ['base' => $invoice->base_currency_code])"
                                    :hint="__('Type this instead of a rate when only the Pula figure is on the statement (ADR-0004 #6).')"
                                    wire:model="receiptBase"
                                    numeric
                                />
                            </div>

                            <x-ui.text size="xs" color="muted">
                                {{ __('The difference between this receipt’s rate and the invoice’s own is booked as realised FX when the money is applied.') }}
                            </x-ui.text>
                        @endif

                        <x-ui.input name="receiptReference" :label="__('Reference')" :hint="__('Bank reference or slip number')" wire:model="receiptReference" />

                        <div>
                            <x-ui.button type="submit" variant="primary">{{ __('Record and apply') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            @endcan
        </x-ui.card>

        <x-ui.card :title="__('Credit notes')">
            <x-ui.table :caption="__('Credit notes')">
                <x-slot name="head">
                    <tr>
                        <x-ui.table.th>{{ __('Note') }}</x-ui.table.th>
                        <x-ui.table.th nowrap>{{ __('Issued') }}</x-ui.table.th>
                        <x-ui.table.th>{{ __('Reason') }}</x-ui.table.th>
                        <x-ui.table.th numeric>{{ __('Total') }}</x-ui.table.th>
                    </tr>
                </x-slot>
                @forelse ($this->creditNotes as $note)
                    <tr wire:key="credit-{{ $note->id }}">
                        <x-ui.table.td><span class="fg-identifier">{{ $note->number }}</span></x-ui.table.td>
                        <x-ui.table.td nowrap>{{ $note->issue_date->format('d M Y') }}</x-ui.table.td>
                        <x-ui.table.td>{{ str_replace('_', ' ', $note->reason) }}</x-ui.table.td>
                        <x-ui.table.td numeric>{{ $this->money($note->grossMinor()) }}</x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <x-ui.table.td colspan="4" class="text-sm text-fg-ink-muted">{{ __('No credit has been raised against this invoice.') }}</x-ui.table.td>
                    </tr>
                @endforelse
            </x-ui.table>

            @can('credit_notes.manage')
                @if ($invoice->status === InvoiceLifecycle::Issued && $invoice->balance_amount > 0)
                    <form wire:submit="issueCredit" class="mt-4 grid gap-3">
                        <div class="grid gap-1.5">
                            <label for="credit-line" class="text-xs font-medium text-fg-ink-secondary">{{ __('Line to credit') }}</label>
                            <x-ui.select name="creditLineId" id="credit-line" wire:model="creditLineId">
                                @foreach ($invoice->lines as $line)
                                    <option value="{{ $line->id }}">{{ $line->description }} — {{ $this->money($line->amount) }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.input name="creditAmount" :label="__('Amount')" :hint="__('Not more than the line net')" wire:model="creditAmount" numeric />

                            <div class="grid gap-1.5">
                                <label for="credit-reason" class="text-xs font-medium text-fg-ink-secondary">{{ __('Reason') }}</label>
                                <x-ui.select name="creditReason" id="credit-reason" wire:model="creditReason">
                                    <option value="over_billed">{{ __('Over billed') }}</option>
                                    <option value="pricing_error">{{ __('Pricing error') }}</option>
                                    <option value="goods_damaged">{{ __('Goods damaged') }}</option>
                                    <option value="service_not_rendered">{{ __('Service not rendered') }}</option>
                                    <option value="void_leg">{{ __('Void leg') }}</option>
                                    <option value="other">{{ __('Other') }}</option>
                                </x-ui.select>
                            </div>
                        </div>

                        <x-ui.input name="creditNarrative" :label="__('Narrative')" wire:model="creditNarrative" />

                        <div>
                            <x-ui.button type="submit" variant="outline">{{ __('Issue credit note') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            @endcan
        </x-ui.card>
    </div>

    @can('invoices.void')
        @if ($this->canVoid())
            <x-ui.card :title="__('Void this invoice')" :description="__('Only an invoice with no money applied can be voided. The number is kept, the posting is mirrored, and the delivery becomes billable again.')">
                <form wire:submit="void" class="grid gap-3 sm:grid-cols-[minmax(0,3fr)_auto] sm:items-end">
                    <x-ui.input name="voidReason" :label="__('Reason')" :hint="__('At least ten characters, written for the auditor')" wire:model="voidReason" required />

                    <x-ui.button type="submit" variant="danger" wire:confirm="{{ __('Void this invoice? Its number is kept and the posting is reversed.') }}">
                        {{ __('Void invoice') }}
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    @endcan
</div>
