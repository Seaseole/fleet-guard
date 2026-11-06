@props(['actions' => []])

{{--
    Presentational quick-create: the shell filters the matrix by permission and hands over the
    survivors as `actions` (label, href, icon). The permission list itself lives in the shell,
    beside the same Gate the sidebar menu is filtered by — one place to ask "what may this seat
    start", not two.
--}}
@if($actions !== [])
    <x-ui.dropdown
        name="create"
        position="bottom"
        align="end"
        width="56"
        :label="__('Create')"
        aria-label="{{ __('Create') }}"
        trigger-class="inline-flex h-9 touch:h-11 shrink-0 items-center gap-1.5 rounded-fg-md bg-accent px-2.5 touch:px-3 text-sm font-medium text-on-accent transition duration-(--t-fast) hover:bg-accent-hover active:scale-[0.98]"
    >
        <x-slot:trigger>
            <x-ui.icon name="plus" size="5" />
            <span class="hidden md:inline">{{ __('Create') }}</span>
        </x-slot:trigger>

        <x-ui.menu>
            @foreach($actions as $action)
                <x-ui.menu.item :href="$action['href']" :icon="$action['icon']" wire:navigate>
                    {{ $action['label'] }}
                </x-ui.menu.item>
            @endforeach
        </x-ui.menu>
    </x-ui.dropdown>
@endif
