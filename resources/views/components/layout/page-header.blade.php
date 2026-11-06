{{--
    The top of every content page (docs/component-ui.md design reference): breadcrumb, one large H1,
    a one-line summary, and the primary action on the right. The page owns the data; this owns the
    rhythm — 24 px gutter, title 20/26, summary muted, action never wrapping under the title on lg.
--}}
@props(['title', 'description' => null, 'breadcrumbs' => []])

<header {{ $attributes->class('fg-page-header grid gap-1.5') }}>
    @if ($breadcrumbs !== [])
        <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-fg-ink-muted">
            @foreach ($breadcrumbs as $crumb)
                @if (! $loop->first)
                    <span aria-hidden="true">/</span>
                @endif

                @if ($loop->last)
                    <span aria-current="page" class="text-fg-ink-secondary">{{ $crumb['label'] }}</span>
                @else
                    <a href="{{ $crumb['href'] }}" wire:navigate class="transition duration-(--t-fast) underline-offset-4 hover:text-fg-ink hover:underline focus-visible:text-fg-ink">{{ $crumb['label'] }}</a>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="grid min-w-0 flex-1 basis-80 gap-1">
            <x-ui.heading level="1" size="xl">{{ $title }}</x-ui.heading>

            @if ($description !== null)
                <x-ui.subheading>{{ $description }}</x-ui.subheading>
            @endif
        </div>

        @isset($action)
            <div class="flex max-w-full flex-wrap items-center gap-2">
                {{ $action }}
            </div>
        @endisset
    </div>
</header>
