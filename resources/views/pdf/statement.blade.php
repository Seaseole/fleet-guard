@extends('pdf.layout')

@section('body')
    @php
        $baseCurrency = $company->base_currency_code;
        $money = static fn (int $minor): string => \App\Domain\Kernel\Money\Money::ofMinor($minor, $baseCurrency)->format($baseCurrency);
    @endphp

    <table class="party">
        <tr>
            <td style="width: 55%">
                <div class="label">Statement for</div>
                <div style="font-weight: bold;">{{ $statement->customerName }}</div>
                @if ($statement->customerCode)
                    <div class="small muted">Account {{ $statement->customerCode }}</div>
                @endif
                @if ($statement->customerVatNumber)
                    <div class="small muted">VAT no. {{ $statement->customerVatNumber }}</div>
                @endif
            </td>
            <td class="right">
                <div class="label">Period</div>
                <div>{{ $statement->from->format('d M Y') }} – {{ $statement->to->format('d M Y') }}</div>
                <div class="label" style="margin-top: 3mm;">Outstanding now</div>
                <div style="font-size: 13pt; font-weight: bold;">{{ $money($statement->outstandingMinor) }}</div>
                @if ($statement->unappliedMinor > 0)
                    <div class="small muted">Plus {{ $money($statement->unappliedMinor) }} on account</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 26mm;">Invoice</th>
                <th style="width: 20mm;">Issued</th>
                <th style="width: 20mm;">Due</th>
                <th class="right" style="width: 24mm;">Gross</th>
                <th class="right" style="width: 24mm;">Settled</th>
                <th class="right" style="width: 24mm;">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement->lines as $line)
                <tr>
                    <td>
                        {{ $line->number }}
                        @if ($line->status === \App\Domain\Invoicing\InvoiceLifecycle::Void)
                            <div class="small status-void">Void</div>
                        @elseif ($line->balanceMinor > 0 && $line->ageing->value !== 'current')
                            <div class="small muted">{{ $line->ageing->label() }}</div>
                        @endif
                    </td>
                    <td class="nowrap">{{ $line->issuedOn->format('d M Y') }}</td>
                    <td class="nowrap">{{ $line->dueOn->format('d M Y') }}</td>
                    <td class="num">{{ $money($line->grossMinor) }}</td>
                    <td class="num">{{ $money($line->settledMinor()) }}</td>
                    <td class="num">{{ $money($line->balanceMinor) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted">No invoices were issued in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($statement->receipts !== [])
        <div class="label" style="margin-top: 5mm;">Receipts in this period</div>
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 30mm;">Receipt</th>
                    <th style="width: 22mm;">Received</th>
                    <th>Method</th>
                    <th class="right" style="width: 24mm;">Amount</th>
                    <th class="right" style="width: 26mm;">Still unapplied</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statement->receipts as $receipt)
                    <tr>
                        <td>{{ $receipt['number'] }}</td>
                        <td class="nowrap">{{ $receipt['receivedOn']->format('d M Y') }}</td>
                        <td>{{ $receipt['method'] }}</td>
                        <td class="num">{{ $money($receipt['amountMinor']) }}</td>
                        <td class="num">{{ $money($receipt['unappliedMinor']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr>
            <td class="muted">Invoiced in period</td>
            <td class="num">{{ $money($statement->invoicedMinor) }}</td>
        </tr>
        <tr>
            <td class="muted">Received in period</td>
            <td class="num">{{ $money($statement->receivedMinor) }}</td>
        </tr>
        <tr class="grand">
            <td>Outstanding now</td>
            <td class="num">{{ $money($statement->outstandingMinor) }}</td>
        </tr>
    </table>

    <div class="note">
        Please quote the invoice number with your payment. If any line here does not match your records, contact us
        before the next due date so the document can be corrected by credit note rather than disputed later.
    </div>
@endsection
