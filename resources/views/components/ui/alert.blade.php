@props(['tone' => 'info', 'title' => null])

@php
    $tones = [
        'info' => ['class' => 'border-info/30 bg-info-soft text-info', 'icon' => 'information-circle'],
        'success' => ['class' => 'border-success/30 bg-success-soft text-success', 'icon' => 'check-circle'],
        'warning' => ['class' => 'border-warning/30 bg-warning-soft text-warning', 'icon' => 'exclamation-triangle'],
        'danger' => ['class' => 'border-danger/30 bg-danger-soft text-danger', 'icon' => 'x-circle'],
    ];

    // A danger alert reports a failure the reader must not miss, so it interrupts; everything else is status.
    $isDanger = $tone === 'danger';
    $tone = $tones[$tone] ?? $tones['info'];
@endphp

<div role="{{ $isDanger ? 'alert' : 'status' }}" {{ $attributes->class(['flex items-start gap-3 rounded-fg-md border px-4 py-3 text-sm', $tone['class']]) }}>
    <x-ui.icon :name="$tone['icon']" class="mt-0.5" />

    <div class="grid gap-0.5">
        @if($title)
            <span class="font-medium text-fg-ink">{{ $title }}</span>
        @endif

        <div class="text-fg-ink-secondary">{{ $slot }}</div>
    </div>
</div>
