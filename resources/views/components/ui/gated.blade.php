@props([
    'can' => false,
    'masked' => null,
    'label' => null,
])

{{--
    The rendering rule in one place: a sensitive field is asked through a named gate, and the
    closed answer is a placeholder rather than an empty cell. `@can` expressions scattered around
    columns are what make "who can see this number?" unanswerable, so a screen uses this component
    instead of writing the check — and the check itself is the caller's, not the primitive's:
    authorization lives in the page, the policy behind it, never in `ui/`.

    `masked` is what the caller already holds — the `*_masked` column, not a decryption.
--}}
@if ($can)
    {{ $slot }}
@else
    <span
        class="inline-flex items-center gap-1.5 text-fg-ink-muted"
        title="{{ $label ? __(':field is hidden for your role.', ['field' => $label]) : __('Hidden for your role.') }}"
    >
        <x-ui.icon name="lock-closed" size="3" />

        <span class="font-mono text-xs">{{ $masked ?? '—' }}</span>
    </span>
@endif
