{{-- 'split' variant: brand panel beside the form. Copy stays factual — no invented marketing claims. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-dvh bg-fg-canvas text-fg-ink lg:grid lg:grid-cols-2">
        <div class="hidden flex-col justify-between border-e border-fg-line bg-fg-surface p-10 lg:flex">
            <x-app-logo :href="route('home')" />

            <div class="grid gap-3">
                <x-ui.heading size="lg">{{ __('Fleet, haulage and cross-border operations') }}</x-ui.heading>
                <x-ui.text class="max-w-sm">
                    {{ __('One place for trips, fuel, workshop, invoicing, accounts and the documents that keep trucks moving between borders.') }}
                </x-ui.text>
            </div>

            <x-ui.text color="muted" size="xs">
                {{ config('app.name') }} · {{ __('Base currency') }}: BWP · {{ __('Timezone') }}: Africa/Gaborone
            </x-ui.text>
        </div>

        <div class="flex min-h-dvh flex-col justify-center px-4 py-10">
            <div class="mx-auto w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    <x-app-logo :href="route('home')" />
                </div>

                {{ $slot }}
            </div>
        </div>

        <x-ui.toast.group />
        <x-flash-toasts />
    </body>
</html>
