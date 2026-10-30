{{--
    Two numeric paths, and only two: `numeric` is a figure that has to line up on its decimal point (money,
    distance, tonnes) and `identifier` is a code that has to be read character by character (a reference, a
    ULID, an ISO code). Both come from the stylesheet's own `.fg-numeric` / `.fg-identifier` token classes,
    so the alignment and the tabular figures are one decision made once — not a class string copied per
    screen. There is deliberately no `mono`: a right-aligned column of amounts without tabular figures does
    not align, which is the bug the old `numeric`/`mono` pair let screens choose.
--}}
@props(['numeric' => false, 'identifier' => false, 'nowrap' => false])

<td
    {{ $attributes->class([
        'border-b border-fg-line px-3 py-2 align-middle text-fg-ink',
        'fg-numeric' => $numeric,
        'fg-identifier' => $identifier,
        'whitespace-nowrap' => $nowrap,
    ]) }}
>{{ $slot }}</td>
