@component('mail::message')
# You have been invited

{{ $invitedBy }} invited you to **{{ config('app.name') }}** as **{{ $roleLabel }}**.

@component('mail::button', ['url' => $acceptUrl])
Accept the invitation
@endcomponent

The link works once and expires {{ $expiresAt->format('j M Y, H:i') }}. If it stops working, ask the Owner to send a new one — there is no sign-up form on this system.

If you were not expecting this message, no action is needed and nothing has been created.
@endcomponent
