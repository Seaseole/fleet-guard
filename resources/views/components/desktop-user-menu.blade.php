@props([
    'name' => null,
    'email' => null,
    'initials' => '?',
    'settingsHref' => null,
    'logoutHref' => null,
    'collapsed' => false,
    'collapsible' => false,
    'position' => 'top',
])

{{--
    Presentational: the shell passes the signed-in seat's name, email and initials and the two
    routes the menu links to. The logout stays a real POST form — the CSRF and the guard belong to
    the framework, not to this file's knowledge of anyone.
--}}
<x-ui.dropdown
    name="account"
    :position="$position"
    align="end"
    width="56"
    :label="__('Account menu')"
    aria-label="{{ __('Account menu') }}"
    :collapsible="$collapsible"
    trigger-class="flex w-full cursor-pointer items-center gap-2.5 rounded-fg-md p-1.5 touch:p-2 text-start transition duration-(--t-fast) hover:bg-fg-surface-sunk"
    data-test="sidebar-menu-button"
>
    <x-slot:trigger>
        <x-ui.avatar :name="$name" :initials="$initials" size="sm" />

        @unless($collapsed)
            <span
                class="min-w-0 flex-1"
                @if($collapsible) x-show="! collapsed" x-cloak @endif
            >
                <span class="block truncate text-sm text-fg-ink">{{ $name }}</span>
                <span class="block truncate text-xs text-fg-ink-muted">{{ $email }}</span>
            </span>

            <span @if($collapsible) x-show="! collapsed" x-cloak @endif>
                <x-ui.icon
                    name="chevron-down"
                    class="shrink-0 text-fg-ink-muted"
                />
            </span>
        @endunless
    </x-slot:trigger>

    <x-ui.menu>
        <li role="none" class="flex items-center gap-2.5 px-2.5 py-2">
            <x-ui.avatar :name="$name" :initials="$initials" size="md" />

            <span class="min-w-0 grid flex-1 leading-tight">
                <x-ui.heading size="xs" class="truncate text-sm">{{ $name }}</x-ui.heading>
                <x-ui.text class="truncate">{{ $email }}</x-ui.text>
            </span>
        </li>

        <x-ui.menu.separator />

        @if($settingsHref !== null)
            <x-ui.menu.item :href="$settingsHref" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </x-ui.menu.item>

            <x-ui.menu.separator />
        @endif

        @if($logoutHref !== null)
            <form method="POST" action="{{ $logoutHref }}" class="w-full">
                @csrf
                <x-ui.menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    tone="danger"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </x-ui.menu.item>
            </form>
        @endif
    </x-ui.menu>
</x-ui.dropdown>
