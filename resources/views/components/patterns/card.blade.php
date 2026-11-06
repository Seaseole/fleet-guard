@props([
    'title' => null,
    'description' => null,
    'padding' => 'md',
    'as' => 'div',
])

@php
    $paddings = ['none' => '', 'sm' => 'p-4', 'md' => 'p-5', 'lg' => 'p-7'];
@endphp

<{{ $as }}
    {{ $attributes->class([
        'rounded-fg-md border border-fg-line bg-fg-surface shadow-fg-1',
        $paddings[$padding] ?? $paddings['md'],
    ]) }}
>
    @if($title || isset($header))
        <header class="{{ $padding === 'none' ? '' : 'mb-4' }} flex items-start justify-between gap-3">
            <div class="grid gap-1">
                @if($title)
                    <x-ui.heading :size="$as === 'section' ? 'base' : 'xs'">{{ $title }}</x-ui.heading>
                @endif

                @if($description)
                    <x-ui.subheading>{{ $description }}</x-ui.subheading>
                @endif
            </div>

            {{ $header ?? '' }}
        </header>
    @endif

    {{ $slot }}

    @isset($footer)
        <footer class="mt-5 border-t border-fg-line pt-4">
            {{ $footer }}
        </footer>
    @endisset
</{{ $as }}>
