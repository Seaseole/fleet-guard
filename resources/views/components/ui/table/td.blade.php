@props(['numeric' => false, 'mono' => false, 'nowrap' => false])

<td
    {{ $attributes->class([
        'border-b border-fg-line px-3 py-2 align-middle text-fg-ink',
        'text-right' => $numeric,
        'fg-numeric text-right' => $mono,
        'whitespace-nowrap' => $nowrap,
    ]) }}
>{{ $slot }}</td>
