{{--
    @deprecated — moved to x-patterns.card (docs/component-ui.md Phase 2.4: composites live in
    patterns/, ui/ keeps primitives). This shim keeps every existing call site rendering while
    pages migrate one at a time; delete it when the last `<x-ui.card>` is gone. The dependency
    rule allows ui/ → patterns/ only inside these shims, and only until then.
--}}
@props(['title' => null, 'description' => null, 'padding' => 'md', 'as' => 'div'])

<x-patterns.card :title="$title" :description="$description" :padding="$padding" :as="$as" {{ $attributes }}>
    @isset($header)
        <x-slot:header>{{ $header }}</x-slot:header>
    @endisset

    @isset($footer)
        <x-slot:footer>{{ $footer }}</x-slot:footer>
    @endisset

    {{ $slot }}
</x-patterns.card>
