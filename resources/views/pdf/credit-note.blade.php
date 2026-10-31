@extends('pdf.layout')

@section('body')
    @php
        $baseCurrency = $company->base_currency_code;
        $money = static fn (int $minor): string => \App\Domain\Kernel\Money\Money::ofMinor($minor, $baseCurrency)->format($baseCurrency);
        $net = $note->amount;
        $tax = $note->tax_amount;
        $gross = $net + $tax;
    @endphp

    <table class="party">
        <tr>
            <td style="width: 55%">
                <div class="label">Issued to</div>
                <div style="font-weight: bold;">{{ $customer->name }}</div>
                @if ($customer->code)
                    <div class="small muted">Account {{ $customer->code }}</div>
                @endif
                @if ($customer->vat_number)
                    <div class="small muted">VAT no. {{ $customer->vat_number }}</div>
                @endif
            </td>
            <td class="right">
                <div class="label">Corrects</div>
                <div>{{ $invoice->reference() }}</div>
                <div class="small muted">Issued {{ $invoice->issue_date->format('d M Y') }}</div>
                <div class="label" style="margin-top: 3mm;">Reason</div>
                <div>{{ str_replace('_', ' ', $note->reason) }}</div>
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
            @foreach ($note->lines as $index => $line)
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
            <td>Credit total</td>
            <td class="num">{{ $money($gross) }}</td>
        </tr>
    </table>

    @if ($note->narrative)
        <div class="note">{{ $note->narrative }}</div>
    @endif

    <div class="note">
        This credit note has been applied to {{ $invoice->reference() }}. It does not change the invoice, which stays
        on file with its own number.
    </div>
@endsection
