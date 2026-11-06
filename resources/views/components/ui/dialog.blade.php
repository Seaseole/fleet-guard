{{--
    Native <dialog>: browser-managed focus trap and Escape. Open it from anywhere
    with x-on:click="$dispatch('open-<name>')", close with 'close-<name>'.

    `variant="drawer"` pins the same native dialog to the inline end at full height behind a light scrim, so
    the board a dispatcher clicked stays readable while they answer for it. It is still one `<dialog>`: the
    focus trap, Escape and the inert background come from the browser rather than from a hand-rolled fixed
    panel that would have to re-implement all three.

    The drawer also bounds its own height instead of letting the browser scroll the dialog box. That matters
    for every `x-ui.select` inside it: the widget appends its panel to the nearest dialog, and a panel
    hanging past a scrollable dialog inflates that dialog's scroll area — which is where the pair of
    scrollbars under an open driver list came from. A bounded box gives the panel room to flip above instead.

    A click on a modal dialog's backdrop is delivered to the `<dialog>` element itself, so the centered
    variant answers "click outside to close" with `.self` on the element — the same native geometry as
    before, one handler richer. Escape still wins over it because both end in the same `close`.
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg', 'variant' => 'center'])

@php
    $widths = [
        'sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg',
        'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '4xl' => 'max-w-4xl',
    ];

    $isDrawer = $variant === 'drawer';
@endphp

<dialog
    wire:ignore.self
    x-data
    x-on:open-{{ $name }}.window="if (! $el.open) $el.showModal()"
    x-on:close-{{ $name }}.window="if ($el.open) $el.close()"
    x-on:close="! $el.open && $dispatch('dialog-closed')"
    @if (! $isDrawer) x-on:click.self="if ($el.open) $el.close()" @endif
    {{ $attributes->class([
        'border-fg-line bg-fg-surface p-0 text-fg-ink',
        $isDrawer
            // Full height against the inline end, so nothing inside has to overflow the dialog box.
            ? 'fg-drawer inset-y-0 m-0 ms-auto flex h-full max-h-full w-full max-w-[28rem] flex-col overflow-hidden border-y-0 border-e-0 border-s shadow-fg-3 backdrop:bg-black/20'
            : 'm-auto w-full rounded-fg-lg border shadow-fg-3 backdrop:bg-black/50 backdrop:backdrop-blur-[2px] '.($widths[$maxWidth] ?? $widths['lg']),
    ])->merge($title ? ['aria-labelledby' => 'dialog-'.$name.'-title'] : []) }}
>
    <div @class([
        'flex flex-col' => $isDrawer,
        'grid gap-0' => ! $isDrawer,
    ])>
        @if($title)
            <header @class([
                'flex items-center justify-between gap-4 px-5 pt-5',
                'shrink-0' => $isDrawer,
            ])>
                <x-ui.heading size="xs" id="dialog-{{ $name }}-title">{{ $title }}</x-ui.heading>

                <button
                    type="button"
                    x-on:click="$dispatch('close-{{ $name }}')"
                    class="grid size-8 touch:size-11 -me-1.5 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk hover:text-fg-ink"
                    aria-label="{{ __('Close') }}"
                >
                    <x-ui.icon name="x-mark" />
                </button>
            </header>
        @endif

        <div @class([
            'px-5 py-4',
            'min-h-0 flex-1 overflow-y-auto overscroll-contain' => $isDrawer,
        ])>
            {{ $slot }}
        </div>

        @isset($footer)
            <footer @class([
                'flex items-center justify-end gap-2 border-t border-fg-line px-5 py-4',
                'shrink-0' => $isDrawer,
            ])>
                {{ $footer }}
            </footer>
        @endisset
    </div>
</dialog>
