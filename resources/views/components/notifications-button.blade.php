{{--
    The bell counts things needing action (DESIGN-SYSTEM.md §7) — which since slice 8 means
    *this person's unread deliveries of alerts that are still live*, through the one accessor the
    topbar is allowed to use ({@see \App\Models\User::unreadAlertsCount()}). It is not the register's
    count: the screen beside it says how many findings are open, and the two numbers answer different
    questions, so the label says "unread" rather than borrowing the register's word.
--}}
@if(Route::has('alerts.index'))
    @php $unread = auth()->user()?->unread_alerts_count ?? 0; @endphp

    <a
        href="{{ route('alerts.index') }}"
        wire:navigate
        class="relative grid size-9 place-items-center rounded-fg-sm text-fg-ink-secondary transition duration-(--t-fast) hover:bg-fg-surface-sunk hover:text-fg-ink"
        aria-label="{{ __('Alerts') }}"
        {{ $attributes }}
    >
        <x-ui.icon name="bell-alert" size="5" />

        @if($unread > 0)
            <span class="absolute end-1.5 top-1.5 size-2 rounded-full bg-danger" aria-hidden="true"></span>
            <span class="sr-only">{{ __(':count unread alerts', ['count' => $unread]) }}</span>
        @endif
    </a>
@endif
