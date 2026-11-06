@extends('pdf.layout')

@section('body')
    <div class="label" style="margin-bottom: 2mm;">{{ $data->window }}</div>

    <table class="lines">
        <thead>
            <tr>
                @foreach ($data->columns as $column)
                    <th @class(['num' => $column['numeric']])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data->rows as $row)
                <tr>
                    @foreach ($row as $cellIndex => $cell)
                        <td @class(['num' => $data->columns[$cellIndex]['numeric']])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($data->columns) }}" class="muted">Nothing answers this window.</td>
                </tr>
            @endforelse
            @if ($data->totals !== null)
                <tr>
                    @foreach ($data->totals as $cellIndex => $cell)
                        <td @class(['num' => $data->columns[$cellIndex]['numeric']]) style="font-weight: bold; border-top: 0.6pt solid #1b1b1b;">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @if ($data->note !== null)
        <div class="note">{{ $data->note }}</div>
    @endif

    <div class="footer">Report figures are the ledger's and the modules' own; the definitions behind every column live in the metric catalogue.</div>
@endsection
