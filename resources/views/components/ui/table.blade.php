@props(['dense' => true, 'caption' => null])

<div class="overflow-x-auto rounded-fg-lg border border-fg-line bg-fg-surface">
    <table
        {{ $attributes->class(['w-full border-collapse text-sm', 'text-[0.8125rem]' => $dense]) }}
    >
        @if($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        {{ $slot }}
    </table>
</div>
