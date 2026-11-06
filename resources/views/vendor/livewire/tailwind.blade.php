{{--
    Tokenised pagination for every register in the app.

    Livewire's WithPagination sets Paginator::$defaultView to "livewire::tailwind", so this file — not the
    framework's — is what the 25 `->links()` call sites render. It overrides the package view because the
    package's own copy sits in vendor/ and is outside the Tailwind scan declared in resources/css/app.css:
    its classes reached the markup with no matching CSS, which is why the control arrived visually unstyled.
    It was also cold-grey on a warm-canvas app and marked the current page with a one-shade difference
    that read as a disabled button rather than as "you are here" (action first).

    The Livewire contract that must survive: wire:click carrying the paginator's page name (cutover, fuel and
    maintenance each hold two paginators on one screen), the per-page wire:key, and scroll-into-view.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    // One shape for every control in the bar, so a disabled step and an available one hold the same box.
    // Mobile: 44px (size-11) touch target; Desktop: 32px (size-8)
    $step = 'inline-flex size-11 shrink-0 sm:size-8 items-center justify-center rounded-fg-md border border-fg-line-strong text-sm text-fg-ink-secondary transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-sunk hover:text-fg-ink disabled:pointer-events-none disabled:opacity-45';
@endphp

@if ($paginator->hasPages())
    <nav
        role="navigation"
        aria-label="{{ __('Pagination navigation') }}"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-fg-line pt-3"
    >
        <p class="text-xs text-fg-ink-muted">
            {!! __('Showing :from–:to of :total', [
                'from' => '<span class="fg-numeric">'.$paginator->firstItem().'</span>',
                'to' => '<span class="fg-numeric">'.$paginator->lastItem().'</span>',
                'total' => '<span class="fg-numeric">'.$paginator->total().'</span>',
            ]) !!}
            <span aria-hidden="true"> · </span>
            {!! __('Page :page of :pages', [
                'page' => '<span class="fg-numeric">'.$paginator->currentPage().'</span>',
                'pages' => '<span class="fg-numeric">'.$paginator->lastPage().'</span>',
            ]) !!}
        </p>

        <div class="flex items-center gap-1.5">
            <button
                type="button"
                wire:click="previousPage('{{ $paginator->getPageName() }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @if ($paginator->onFirstPage()) disabled @endif
                aria-label="{{ __('Previous page') }}"
                class="{{ $step }}"
            >
                <x-ui.icon name="chevron-left" class="rtl:rotate-180" />
            </button>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-hidden="true" class="px-0.5 text-sm text-fg-ink-muted">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                            @if ($page == $paginator->currentPage())
                                <span
                                    aria-current="page"
                                    class="inline-flex size-11 shrink-0 sm:size-8 items-center justify-center rounded-fg-md border border-accent bg-accent-soft fg-numeric text-sm font-medium text-fg-ink"
                                >{{ $page }}</span>
                            @else
                                <button
                                    type="button"
                                    wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                    class="inline-flex size-11 shrink-0 sm:size-8 items-center justify-center rounded-fg-md border border-transparent text-sm fg-numeric text-fg-ink-secondary transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-sunk hover:text-fg-ink"
                                >{{ $page }}</button>
                            @endif
                        </span>
                    @endforeach
                @endif
            @endforeach

            <button
                type="button"
                wire:click="nextPage('{{ $paginator->getPageName() }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @if (! $paginator->hasMorePages()) disabled @endif
                aria-label="{{ __('Next page') }}"
                class="{{ $step }}"
            >
                <x-ui.icon name="chevron-right" class="rtl:rotate-180" />
            </button>
        </div>
    </nav>
@endif
