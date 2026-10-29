@php
    // `rail` marks the desktop sidebar, whose collapse state is Alpine-only, so its
    // labels and section headings must toggle at runtime instead of at compile time.
    $inRail = $rail ?? false;
    $sections = collect(config('navigation'))
        ->map(fn (array $section) => array_merge($section, [
            'items' => collect($section['items'])
                ->filter(fn (array $item) => ! isset($item['permission']) || Gate::allows($item['permission']))
                ->filter(fn (array $item) => Route::has($item['route']))
                ->all(),
        ]))
        ->filter(fn (array $section) => $section['items'] !== [])
        ->all();
@endphp

@if($inline ?? false)
    <div class="flex items-center gap-1">
        @foreach(collect($sections)->flatMap(fn ($s) => $s['items']) as $item)
            <x-ui.navlist.item
                :href="route($item['route'])"
                :current="request()->routeIs($item['route'])"
                class="whitespace-nowrap"
                wire:navigate
            >
                {{ $item['label'] }}
            </x-ui.navlist.item>
        @endforeach
    </div>
@else
    <div class="grid gap-4">
        @foreach($sections as $section)
            <div class="grid gap-0.5">
                @unless($collapsed ?? false)
                    <p
                        class="px-2.5 pb-1 pt-2 text-xs font-medium text-fg-ink-muted"
                        @if($inRail) x-show="! collapsed" x-cloak @endif
                    >{{ $section['label'] }}</p>
                @endunless

                @foreach($section['items'] as $item)
                    <x-ui.navlist.item
                        :href="route($item['route'])"
                        :icon="$item['icon']"
                        :current="request()->routeIs($item['route'])"
                        :collapsible-label="$inRail"
                        :title="$item['label']"
                        wire:navigate
                    >
                        {{ $item['label'] }}
                    </x-ui.navlist.item>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
