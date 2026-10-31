@extends('pdf.layout')

@section('body')
    @php
        $baseCurrency = $company->base_currency_code;
        $money = static fn (int $minor): string => \App\Domain\Kernel\Money\Money::ofMinor($minor, $baseCurrency)->format($baseCurrency);
        $net = $invoice->amount;
        $tax = $invoice->tax_amount;
        $gross = $net + $tax;
        $settledState = $invoice->settlementState();
        $isVoid = $invoice->status === \App\Domain\Invoicing\InvoiceLifecycle::Void;
    @endphp

    <table class="party">
        <tr>
            <td style="width: 55%">
                <div class="label">Billed to</div>
                <div style="font-weight: bold;">{{ $customer->name }}</div>
                @if ($customer->code)
                    <div class="small muted">Account {{ $customer->code }}</div>
                @endif
                @if ($customer->contact_name)
                    <div class="small">{{ $customer->contact_name }}</div>
                @endif
                @if ($customer->contact_email)
                    <div class="small muted">{{ $customer->contact_email }}</div>
                @endif
                @if ($customer->vat_number)
                    <div class="small muted">VAT no. {{ $customer->vat_number }}</div>
                @endif
            </td>
            <td class="right">
                <div class="label">Terms</div>
                <div>{{ \App\Domain\Kernel\Commercial\PaymentTerms::label($invoice->terms_snapshot) }}</div>
                <div class="label" style="margin-top: 3mm;">Status</div>
                <div>
                    @if ($isVoid)
                        <span class="status-void">Void</span>
                    @else
                        {{ $invoice->status->label() }} · {{ $settledState->label() }}
                    @endif
                </div>
                @if ($invoice->po_reference)
                    <div class="label" style="margin-top: 3mm;">Your reference</div>
                    <div>{{ $invoice->po_reference }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 6mm;">#</th>
                <th>Description</th>
                <th class="right" style="width: 24mm;">Net</th>
                <th class="right" style="width: 16mm;">VAT</th>
                <th class="right" style="width: 24mm;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $index => $line)
                <tr>
                    <td class="muted">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->description }}
                        <div class="small muted">{{ $line->line_kind->label() }}</div>
                    </td>
                    <td class="num">{{ $money($line->amount) }}</td>
                    <td class="num">{{ $money($line->tax_amount) }}</td>
                    <td class="num">{{ $money($line->amount + $line->tax_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Net</td>
            <td class="num">{{ $money($net) }}</td>
        </tr>
        <tr>
            <td class="muted">VAT</td>
            <td class="num">{{ $money($tax) }}</td>
        </tr>
        <tr class="grand">
            <td>Total</td>
            <td class="num">{{ $money($gross) }}</td>
        </tr>
        <tr>
            <td class="muted">Outstanding</td>
            <td class="num">{{ $money($invoice->balance_amount) }}</td>
        </tr>
    </table>

    @if ($invoice->note)
        <div class="note">{{ $invoice->note }}</div>
    @endif

    @if ($isVoid)
        <div class="note">
            Voided {{ $invoice->voided_at?->format('d M Y') }}: {{ $invoice->void_reason }}
        </div>
    @endif

    <div class="note">
        Pay by {{ $invoice->due_on->format('d M Y') }} quoting {{ $invoice->reference() }}.
        Please report any query on this document within 7 days of the issue date.
    </div>
@endsection
