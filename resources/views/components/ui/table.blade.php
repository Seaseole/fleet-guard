@props(['dense' => true, 'caption' => null])

{{--
    The scroll container is focusable and named by the table's own caption, so a keyboard seat that lands
    here can reach the sideways overflow and knows what it is scrolling. Without
    the tabindex the overflowing columns are reachable only by mouse drag; app.css:180 already paints the
    focus ring for any [tabindex], so nothing here repeats it.
--}}
<div
    @if($caption) role="region" tabindex="0" aria-label="{{ $caption }}" @endif
    class="overflow-x-auto rounded-fg-lg border border-fg-line bg-fg-surface"
>
    <table
        {{ $attributes->class(['w-full border-collapse text-sm', 'text-dense' => $dense]) }}
    >
        @if($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        {{--
            The header is a slot, not part of `$slot`: without this the `<x-slot name="head">` every
            list screen passes was silently discarded and no table in the app printed its columns.
        --}}
        @isset($head)
            <thead>{{ $head }}</thead>
        @endisset

        {{--
            `fg-stagger` is first-paint only — app.css gates it behind a flag app.js raises for ~900 ms around
            an arrival and lets it fall — so putting it here gives every register in the app an ordered arrival
            and gives a seat who types three characters into a search field nothing at all. That distinction is
            the whole reason the class is on the component rather than on the pages: a register is re-rendered
            by the hour and a page is arrived at twice.

            Rows rather than the table as a block, because a register is read top-down and the eye lands on the
            first row while the rest are still coming in; the block would have to finish before it could be
            read. `tr` takes the transform, and `border-collapse` is untouched by it.
        --}}
        {{--
            Row hover is a documented state of this component and the pages write their own `<tr>`s, so the
            wash is declared here once: the same argument that made `x-ui.select` replace 64 hand-copied
            selects. `--fg-surface-hover` is the token whose stated job is a hover wash, and a row that
            navigates answering with it is the register's own version of the nav item's treatment.
        --}}
        <tbody class="fg-stagger [&>tr]:transition-colors [&>tr]:duration-(--t-fast) [&>tr]:ease-(--ease-standard) [&>tr:hover]:bg-fg-surface-hover">
            {{ $slot }}
        </tbody>
    </table>
</div>
