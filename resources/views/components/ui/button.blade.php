{{--
    docs/DESIGN-SYSTEM.md one primary button per view; destructive is filled only on the confirm step.

    The default is `outline` on purpose. It used to be `primary`, which meant a button that said nothing
    about its weight came out as the loudest control on the screen — and a page with three forms then had
    three filled teal buttons competing (the "never two"). Leaving a variant unstated is now the quiet
    case: a button has to be told it is the affirmative action of its form, dialog or page.

    Names follow the shadcn contract (primary / secondary / outline / ghost / destructive / link). The
    three older spellings still in the pages — `default`, `danger`, `danger-solid`, plus the two that never
    existed here (`subtle`, `quiet`) — resolve through the same map rather than falling back to outline,
    which is how a `quiet` button on the bills page silently painted a border.
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
    /*
     * `active:scale-[0.97]` is on the base rather than on a variant, because a press is a press whatever the
     * button weighs. It used to live only on the filled pair, as `active:translate-y-px` — and since the
     * default variant is `outline`, that meant most of the app's buttons acknowledged a press with nothing at
     * all. The seat has no way to tell "the interface heard me" from "the handler is slow", and at 3 % scale
     * the difference is invisible until it is missing. Scale, not translate: scale is the property that also
     * carries the label and the icon with it, so the whole control compresses.
     *
     * `translate` and `scale` are named in the transitioned list alongside `transform` because Tailwind v4
     * compiles `scale-*` and `translate-*` to those own properties rather than into `transform:` — measured
     * from the built sheet, where `.-translate-y-1` emits `translate: var(--tw-translate-x) ...`. A list that
     * says only `transform` therefore transitions none of the press, and the button would snap instead of
     * compress. Bare `transition` would have covered it, but the explicit list is the one that already names
     * the six properties this control actually repaints.
     *
     * It is deliberately not gated behind `@media (hover: hover)` — press feedback is worth more on the kiosk
     * and the yard phone than it is on a laptop.
     */
    $base = 'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-fg-md font-medium transition-[color,background-color,border-color,box-shadow,opacity,transform,translate,scale] duration-(--t-fast) ease-(--ease-standard) select-none active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50';

    $filled = 'bg-primary text-primary-foreground shadow-fg-1 hover:bg-accent-hover';
    $destructive = 'bg-destructive text-destructive-foreground shadow-fg-1 hover:bg-danger-hover';

    $variants = [
        'primary' => $filled,
        'default' => $filled,                  /* shadcn's name for the same slot */
        'secondary' => 'bg-secondary text-secondary-foreground hover:bg-fg-surface-hover',
        'outline' => 'border border-input bg-background text-foreground shadow-fg-1 hover:bg-fg-surface-hover',
        'ghost' => 'text-fg-ink-secondary hover:bg-secondary hover:text-foreground',
        'link' => 'text-primary underline-offset-4 hover:underline',
        'danger' => 'border border-danger/40 bg-transparent text-danger hover:bg-danger-soft',
        'danger-solid' => $destructive,
        'destructive' => $destructive,
        'subtle' => 'bg-secondary text-secondary-foreground hover:bg-fg-surface-hover',
        'quiet' => 'text-fg-ink-secondary hover:bg-secondary hover:text-foreground',
    ];

    /**
     * An unstated variant follows the button's job, not a fixed style.
     *
     * The default used to be `primary` whatever the button did, which is how a page with three forms ended up
     * with three filled teal buttons (the design allows one). `type="submit"` is the affirmative action of a form by
     * definition, so that is the one case that earns the accent; everything else — navigation, row actions,
     * cancel buttons — starts quiet and has to be dressed up deliberately. `scripts/lint-ui.php` resolves
     * the same rule, so changing one without the other is a lint failure rather than a silent drift.
     */
    $variant ??= ($type === 'submit' && $href === null) ? 'primary' : 'outline';

    $sizes = [
        'xs' => 'h-7 touch:h-11 gap-1.5 rounded-fg-sm px-2 text-xs',   /* dense row actions */
        'sm' => 'h-8 touch:h-11 gap-1.5 px-3 text-xs',
        'md' => 'h-9 touch:h-11 px-4 text-sm',
        'lg' => 'h-11 px-6 text-sm',       /* 44px: the kiosk's touch minimum */
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
