{{--
    docs/DESIGN-SYSTEM.md §5: one primary button per view; destructive is filled only on the confirm step.

    The default is `outline` on purpose. It used to be `primary`, which meant a button that said nothing
    about its weight came out as the loudest control on the screen — and a page with three forms then had
    three filled teal buttons competing (§5's "never two"). Leaving a variant unstated is now the quiet
    case: a button has to be told it is the affirmative action of its form, dialog or page.
--}}
@props([
    'variant' => null,
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'iconTrailing' => null,
    'loading' => false,
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-fg-md font-medium transition duration-(--t-fast) ease-(--ease-standard) select-none disabled:cursor-not-allowed disabled:opacity-55';

    $variants = [
        'primary' => 'bg-accent text-on-accent hover:bg-accent-hover active:translate-y-px',
        'outline' => 'border border-fg-line-strong bg-fg-surface text-fg-ink hover:bg-fg-surface-sunk',
        'ghost' => 'text-fg-ink-secondary hover:bg-fg-surface-sunk hover:text-fg-ink',
        'danger' => 'border border-danger/40 bg-transparent text-danger hover:bg-danger-soft',
        'danger-solid' => 'bg-danger text-on-danger hover:bg-danger-hover',
    ];

    /**
     * An unstated variant follows the button's job, not a fixed style.
     *
     * The default used to be `primary` whatever the button did, which is how a page with three forms ended up
     * with three filled teal buttons (§5 allows one). `type="submit"` is the affirmative action of a form by
     * definition, so that is the one case that earns the accent; everything else — navigation, row actions,
     * cancel buttons — starts quiet and has to be dressed up deliberately. `scripts/lint-ui.php` resolves
     * the same rule, so changing one without the other is a lint failure rather than a silent drift.
     */
    $variant ??= ($type === 'submit' && $href === null) ? 'primary' : 'outline';

    $sizes = [
        'sm' => 'h-8 px-2.5 text-xs',      /* icon-only with tooltip */
        'md' => 'h-10 px-3.5 text-sm',
        'lg' => 'h-11 px-5 text-sm',
    ];

    $tag = $href ? 'a' : 'button';
    $isDisabled = $disabled || $loading;
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if($tag === 'button' && $isDisabled) disabled @endif
    @if($tag === 'a' && $isDisabled) aria-disabled="true" tabindex="-1" @endif
    data-loading="{{ $loading ? 'true' : 'false' }}"
    {{ $attributes->class([$base, $variants[$variant] ?? $variants['outline'], $sizes[$size] ?? $sizes['md']]) }}
>
    @if($loading)
        <x-ui.icon name="loading" class="animate-spin" />
    @elseif($icon)
        <x-ui.icon :name="$icon" />
    @endif

    {{ $slot }}

    @if($iconTrailing)
        <x-ui.icon :name="$iconTrailing" />
    @endif
</{{ $tag }}>
