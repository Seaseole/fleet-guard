@props(['title', 'description' => null, 'stamped' => false, 'count' => null])

{{--
    The nameplate — the top of a room. It replaces the 2026-11-05 `x-layout.masthead`, and the difference is
    the register, not the shape: a view's title is signage, set in the condensed display face at 600, and
    everything under it is machine text in mono. A masthead announces an article; a nameplate says which
    instrument you are looking at and when it was last read.

    Three lines, always in this order: the name, what the screen counts, and the company clock the figures
    were taken against. The stamp is built here rather than at every call site for the same reason the shell
    gathers the unread count — seven copies of one phrase drift the moment the format changes.

    The action rides the right of the name row so it never wraps under the title, and the hairline below is
    the seam between "what this screen is" and "what is on it".
--}}
@php
    $stampText = $stamped
        ? __(':stamp :zone', [
            'stamp' => \Illuminate\Support\Carbon::now(\App\Domain\Kernel\Tenancy\CurrentCompany::timezone())->format('d M Y H:i'),
            'zone' => \Illuminate\Support\Str::of(\App\Domain\Kernel\Tenancy\CurrentCompany::timezone())->explode('/')->last(),
        ])
        : null;
@endphp

<header {{ $attributes->class('fg-page-header grid gap-1.5') }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <x-ui.heading size="xl" level="1">{{ $title }}</x-ui.heading>

            @if($count !== null)
                {{-- Decorative beside a real reading, and the reading is the text: the count is the
                     screen's own answer, so it stays in the accessible name row rather than hidden. --}}
                <span class="fg-numeric text-sm text-fg-ink-muted">{{ $count }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            @if($stampText !== null)
                <p class="fg-identifier text-xs leading-4 text-fg-ink-muted">{{ $stampText }}</p>
            @endif

            @isset($action)
                {{ $action }}
            @endisset
        </div>
    </div>

    @if($description !== null)
        <p class="text-sm leading-5 text-fg-ink-secondary">{{ $description }}</p>
    @endif

    @isset($meta)
        <div class="flex flex-wrap items-center gap-2 pt-1">
            {{ $meta }}
        </div>
    @endisset
</header>
