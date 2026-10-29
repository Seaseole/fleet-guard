@props(['orientation' => 'horizontal'])

<hr
    {{ $attributes->class([
        'border-0 bg-fg-line',
        'h-px w-full' => $orientation === 'horizontal',
        'w-px self-stretch' => $orientation === 'vertical',
    ])->merge(['role' => 'separator']) }}
/>
