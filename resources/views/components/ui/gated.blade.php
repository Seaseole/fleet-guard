@props([
    'gate' => null,
    'masked' => null,
    'label' => null,
])

{{--
    The §9.4 rendering rule in one place: a sensitive field is asked through a named gate, and the
    closed answer is a placeholder rather than an empty cell. `@can` expressions scattered around
    columns are what make "who can see this number?" unanswerable, so a screen uses this component
    instead of writing the check.

    `masked` is what the caller already holds — §9.5's `*_masked` column, not a decryption.
--}}
@can($gate)
    {{ $slot }}
@else
    <span
        class="inline-flex items-center gap-1.5 text-fg-ink-muted"
        title="{{ $label ? __(':field is hidden for your role.', ['field' => $label]) : __('Hidden for your role.') }}"
    >
        <x-ui.icon name="lock-closed" size="3" />

        <span class="font-mono text-xs">{{ $masked ?? '—' }}</span>
    </span>
@endcan
