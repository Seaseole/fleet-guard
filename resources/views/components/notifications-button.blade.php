@props(['href' => null, 'count' => 0])

{{--
    Presentational: the shell decides whether the alerts register exists and how many deliveries
    are unread; this paints the bell. The count is *this person's unread deliveries of live
    alerts* (slice 8), not the register's open findings — the label says "unread" for that reason.
--}}
@if($href !== null)
    <a
        href="{{ $href }}"
        wire:navigate
        class="relative grid size-9 touch:size-11 shrink-0 place-items-center rounded-fg-sm text-fg-ink-secondary transition duration-(--t-fast) hover:bg-fg-surface-sunk hover:text-fg-ink"
        aria-label="{{ __('Alerts') }}"
        {{ $attributes }}
    >
        <x-ui.icon name="bell-alert" size="5" />

        @if($count > 0)
            <span class="absolute end-1.5 top-1.5 size-2 rounded-full bg-danger" aria-hidden="true"></span>
            <span class="sr-only">{{ __(':count unread alerts', ['count' => $count]) }}</span>
        @endif
    </a>
@endif
