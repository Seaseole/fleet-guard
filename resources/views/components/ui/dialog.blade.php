{{--
    Native <dialog>: browser-managed focus trap and Escape. Open it from anywhere
    with x-on:click="$dispatch('open-<name>')", close with 'close-<name>'.
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg'])

@php
    $widths = [
        'sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg',
        'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '4xl' => 'max-w-4xl',
    ];
@endphp

<dialog
    wire:ignore.self
    x-data
    x-on:open-{{ $name }}.window="if (! $el.open) $el.showModal()"
    x-on:close-{{ $name }}.window="if ($el.open) $el.close()"
    x-on:close="! $el.open && $dispatch('dialog-closed')"
    {{ $attributes->class([
        'm-auto w-full rounded-fg-lg border border-fg-line bg-fg-surface p-0 text-fg-ink shadow-fg-3 backdrop:bg-fg-ink/45 backdrop:backdrop-blur-[2px]',
        $widths[$maxWidth] ?? $widths['lg'],
    ])->merge($title ? ['aria-labelledby' => 'dialog-'.$name.'-title'] : []) }}
>
    <div class="grid gap-0">
        @if($title)
            <header class="flex items-center justify-between gap-4 px-5 pt-5">
                <x-ui.heading size="xs" id="dialog-{{ $name }}-title">{{ $title }}</x-ui.heading>

                <button
                    type="button"
                    x-on:click="$dispatch('close-{{ $name }}')"
                    class="grid size-8 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk hover:text-fg-ink"
                    aria-label="{{ __('Close') }}"
                >
                    <x-ui.icon name="x-mark" />
                </button>
            </header>
        @endif

        <div class="px-5 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex items-center justify-end gap-2 border-t border-fg-line px-5 py-4">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</dialog>
