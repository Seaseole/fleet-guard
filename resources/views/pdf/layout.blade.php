<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Document' }}</title>
    <style>
        /* The print subset: DOMPDF understands tables and block layout, not flex or grid, so the
           document is laid out the way a printed form has always been laid out. No web fonts: a
           browser-only font would silently fall back and change every measurement on the page. */
        @page { margin: 18mm 16mm 20mm 16mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", "Helvetica", Arial, sans-serif;
            font-size: 9.5pt;
            color: #1b1b1b;
            line-height: 1.4;
            margin: 0;
        }
        h1 { font-size: 16pt; margin: 0 0 2mm 0; letter-spacing: 0.02em; }
        .muted { color: #5c5c5c; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 8mm; }
        .head td { vertical-align: top; padding: 0; }
        .doc-kind { text-transform: uppercase; letter-spacing: 0.14em; font-size: 8pt; color: #5c5c5c; }
        .doc-number { font-size: 13pt; font-weight: bold; }
        .party { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
        .party td { vertical-align: top; padding: 0 6mm 0 0; }
        .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.1em; color: #5c5c5c; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        table.lines th {
            text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.08em;
            color: #5c5c5c; border-bottom: 0.6pt solid #1b1b1b; padding: 0 2mm 1.5mm 0;
        }
        table.lines td { padding: 2mm 2mm 2mm 0; border-bottom: 0.4pt solid #d8d8d8; vertical-align: top; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.totals { width: 62mm; margin-left: auto; border-collapse: collapse; }
        table.totals td { padding: 1mm 0; }
        table.totals td.num { text-align: right; white-space: nowrap; }
        table.totals tr.grand td { border-top: 0.6pt solid #1b1b1b; font-weight: bold; padding-top: 2mm; }
        .note { margin-top: 6mm; font-size: 8pt; color: #5c5c5c; }
        .status-void { color: #8a1c1c; font-weight: bold; text-transform: uppercase; letter-spacing: 0.1em; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #7a7a7a; }
        .watermark { position: fixed; top: 90mm; left: 0; right: 0; text-align: center; font-size: 40pt; color: #ededed; }
    </style>
</head>
<body>
    @if (! empty($watermark))
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <table class="head">
        <tr>
            <td style="width: 55%">
                <div style="font-size: 12pt; font-weight: bold;">{{ $company->name }}</div>
                @if ($company->registration_number)
                    <div class="small muted">Reg. {{ $company->registration_number }}</div>
                @endif
                @if ($company->vat_number)
                    <div class="small muted">VAT no. {{ $company->vat_number }}</div>
                @endif
                <div class="small muted">{{ $company->country_code }} · {{ $company->base_currency_code }}</div>
            </td>
            <td class="right">
                <div class="doc-kind">{{ $kind }}</div>
                <div class="doc-number">{{ $number }}</div>
                <div class="small muted">{{ $issuedOn }}</div>
                @if (! empty($dueOn))
                    <div class="small muted">Due {{ $dueOn }}</div>
                @endif
            </td>
        </tr>
    </table>

    @yield('body')

    <div class="footer">
        {{ $company->name }} · {{ $kind }} {{ $number }}
    </div>
</body>
</html>
