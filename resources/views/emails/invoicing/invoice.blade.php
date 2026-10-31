<x-mail::message>
# {{ $kindLabel }} {{ $documentNumber }}

Dear {{ $customerName }},

Please find attached {{ $kindLabel === 'Customer statement' ? 'your statement' : 'your tax invoice' }}
{{ $documentNumber }}, issued by {{ $companyName }}.

<x-mail::panel>
Total: **{{ $totalFormatted }}**  
Due: **{{ $dueOn }}**
</x-mail::panel>

The attached PDF is the document of record. If any line does not match your records, please contact us
before the due date so it can be corrected by credit note.

Thanks,<br>
{{ $companyName }}
</x-mail::message>
