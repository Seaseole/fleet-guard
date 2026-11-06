@props(['href' => '#'])

<a
    href="{{ $href }}"
    {{ $attributes->class('text-sm text-accent-ink underline-offset-4 transition duration-(--t-fast) hover:underline hover:text-accent-hover') }}
>{{ $slot }}</a>
