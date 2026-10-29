@props(['title' => null, 'description' => null, 'icon' => 'hashtag'])

{{--
    Three distinct empty states, deliberately (DESIGN-SYSTEM.md §5): first-run,
    filtered-no-results and no-permission read differently and act differently.
--}}
<div {{ $attributes->class('grid place-items-center gap-3 px-6 py-14 text-center') }}>
    <span class="grid size-11 place-items-center rounded-full border border-fg-line bg-fg-surface-sunk text-fg-ink-muted">
        <x-ui.icon :name="$icon" size="5" />
    </span>

    @if($title)
        <x-ui.heading size="xs">{{ $title }}</x-ui.heading>
    @endif

    @if($description)
        <x-ui.text class="max-w-sm">{{ $description }}</x-ui.text>
    @endif

    @if(! $slot->isEmpty())
        <div class="mt-1">{{ $slot }}</div>
    @endif
</div>
