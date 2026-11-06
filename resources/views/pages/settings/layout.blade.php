<div class="flex min-w-0 items-start gap-6 max-md:flex-col">
    <div class="w-full shrink-0 md:w-56">
        <x-ui.navlist :heading="__('Your settings')">
            <x-ui.navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</x-ui.navlist.item>
            <x-ui.navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</x-ui.navlist.item>
            <x-ui.navlist.item :href="route('sessions.edit')" wire:navigate>{{ __('Sessions') }}</x-ui.navlist.item>
            <x-ui.navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</x-ui.navlist.item>
        </x-ui.navlist>

        @can('settings.view')
            <x-ui.navlist :heading="__('Application settings')" class="mt-5">
                <x-ui.navlist.item :href="route('application.edit')" wire:navigate>{{ __('Application') }}</x-ui.navlist.item>
            </x-ui.navlist>
        @endcan
    </div>

    <x-ui.separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch">
        <x-ui.heading>{{ $heading ?? '' }}</x-ui.heading>
        <x-ui.subheading>{{ $subheading ?? '' }}</x-ui.subheading>

        <div class="mt-5 w-full max-w-(--breakpoint-sm)">
            {{ $slot }}
        </div>
    </div>
</div>
