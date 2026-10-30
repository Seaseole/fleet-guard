{{-- Quick create, permission-filtered the same way the sidebar is. --}}
@php
    $actions = collect([
        ['label' => __('Register document'), 'route' => 'documents.register', 'permission' => 'documents.upload', 'icon' => 'folder'],
        ['label' => __('New journal'), 'route' => 'accounting.journal.create', 'permission' => 'ledger.journal.manual', 'icon' => 'calculator'],
        ['label' => __('New job'), 'route' => 'jobs.create', 'permission' => 'jobs.create', 'icon' => 'clipboard-document-list'],
        ['label' => __('Dispatch trip'), 'route' => 'trips.create', 'permission' => 'trips.create', 'icon' => 'map'],
        ['label' => __('Record fuel'), 'route' => 'fuel.index', 'permission' => 'fuel.record', 'icon' => 'circle-stack'],
        ['label' => __('New work order'), 'route' => 'maintenance.index', 'permission' => 'work_orders.create', 'icon' => 'wrench'],
        ['label' => __('New invoice'), 'route' => 'invoices.create', 'permission' => 'invoices.create', 'icon' => 'receipt-percent'],
    ])->filter(fn (array $a) => Route::has($a['route']) && Gate::allows($a['permission']))->all();
@endphp

@if($actions !== [])
    <x-ui.dropdown
        name="create"
        position="bottom"
        align="end"
        width="56"
        :label="__('Create')"
        aria-label="{{ __('Create') }}"
        trigger-class="inline-flex h-9 items-center gap-1.5 rounded-fg-md px-2.5 text-sm text-fg-ink-secondary transition duration-(--t-fast) hover:bg-fg-surface-sunk hover:text-fg-ink"
    >
        <x-slot:trigger>
            <x-ui.icon name="plus" size="5" />
            <span class="hidden md:inline">{{ __('Create') }}</span>
        </x-slot:trigger>

        <x-ui.menu>
            @foreach($actions as $action)
                <x-ui.menu.item :href="route($action['route'])" :icon="$action['icon']" wire:navigate>
                    {{ $action['label'] }}
                </x-ui.menu.item>
            @endforeach
        </x-ui.menu>
    </x-ui.dropdown>
@endif
