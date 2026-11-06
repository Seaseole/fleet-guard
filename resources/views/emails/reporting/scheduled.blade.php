@component('mail::message')
# {{ $reportTitle }}

{{ $companyName }} · {{ $window }}

@component('mail::panel')
@foreach ($tableLines as $line)
{{ $line }}
@endforeach
@endcomponent

@if ($truncatedBy !== '')
{{ $truncatedBy }}
@endif

@if ($note !== null)
<small>{{ $note }}</small>
@endif

The full sheet is attached as a PDF. This mail goes to every seat that holds the report's
export ability — revoke the ability and the mail stops with it; there is no second list to
update.
@endcomponent
