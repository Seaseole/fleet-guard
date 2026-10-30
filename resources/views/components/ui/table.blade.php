@props(['dense' => true, 'caption' => null])

<div class="overflow-x-auto rounded-fg-lg border border-fg-line bg-fg-surface">
    <table
        {{ $attributes->class(['w-full border-collapse text-sm', 'text-[0.8125rem]' => $dense]) }}
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

        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
