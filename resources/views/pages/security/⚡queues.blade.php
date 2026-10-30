<?php

use App\Domain\Security\Actions\RetryFailedJob;
use App\Domain\Security\QueueHealth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Queue health')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        Gate::authorize('queues.view');
    }

    #[Computed]
    public function queues(): array
    {
        return app(QueueHealth::class)->summary();
    }

    #[Computed]
    public function failures()
    {
        return app(QueueHealth::class)->failures()->orderByDesc('failed_at')->orderByDesc('id')
            ->paginate(20, ['uuid', 'queue', 'connection', 'failed_at']);
    }

    public function retry(string $uuid, RetryFailedJob $retry): void
    {
        $retry($uuid);
        $this->resetPage();
        unset($this->queues, $this->failures);
        $this->dispatch('toast', message: __('Job returned to the queue. A worker still needs to process it.'), kind: 'success');
    }
}; ?>

<section class="space-y-6" wire:poll.30s>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <x-ui.heading level="1">{{ __('Queue health') }}</x-ui.heading>
            <x-ui.text>{{ __('Deployment-wide background work. Refreshes every 30 seconds.') }}</x-ui.text>
        </div>
        <x-ui.button variant="outline" wire:click="$refresh">{{ __('Refresh') }}</x-ui.button>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->queues as $queue)
            <x-ui.card wire:key="queue-{{ $queue['name'] }}">
                <div class="flex items-center justify-between gap-3">
                    <x-ui.heading level="2">{{ $queue['name'] }}</x-ui.heading>
                    @php
                        $stale = ($queue['age'] ?? 0) > config('fleet.queue_health.max_wait_seconds');
                        $tone = $queue['unavailable'] || $queue['failed'] > 0 ? 'danger' : ($stale ? 'warning' : 'success');
                        $status = $queue['unavailable'] ? __('Unavailable') : ($queue['failed'] > 0 ? __('Needs attention') : ($stale ? __('Backed up') : __('Healthy')));
                    @endphp
                    <x-ui.badge :tone="$tone">{{ $status }}</x-ui.badge>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    @foreach (['pending' => 'Ready', 'delayed' => 'Delayed', 'reserved' => 'Processing', 'failed' => 'Failed'] as $key => $label)
                        <div><dt class="text-fg-ink-muted">{{ __($label) }}</dt><dd class="mt-1 font-mono text-xl">{{ $queue[$key] ?? '—' }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-4 text-sm text-fg-ink-secondary">
                    {{ __('Oldest ready job') }}:
                    {{ $queue['unavailable'] ? __('Unknown — connection unavailable') : ($queue['age'] === null ? __('No ready jobs') : now()->subSeconds($queue['age'])->diffForHumans()) }}
                </p>
            </x-ui.card>
        @endforeach
    </div>

    <div class="space-y-3">
        <x-ui.heading level="2">{{ __('Failed jobs') }}</x-ui.heading>
        <x-ui.text>{{ __('Fix the cause before retrying. Use the reference to locate server logs; payloads and exception traces are never shown here.') }}</x-ui.text>
        <x-ui.table :caption="__('Queue jobs that failed, with the reference to look up in the server logs')">
            <thead><tr><x-ui.table.th>{{ __('Reference') }}</x-ui.table.th><x-ui.table.th>{{ __('Queue') }}</x-ui.table.th><x-ui.table.th>{{ __('Failed at (UTC)') }}</x-ui.table.th><x-ui.table.th>{{ __('Action') }}</x-ui.table.th></tr></thead>
            <tbody>
            @forelse ($this->failures as $job)
                <tr wire:key="failed-{{ $job->uuid }}">
                    <x-ui.table.td identifier>{{ $job->uuid }}</x-ui.table.td>
                    <x-ui.table.td>{{ $job->queue }}</x-ui.table.td>
                    <x-ui.table.td>{{ $job->failed_at }}</x-ui.table.td>
                    <x-ui.table.td>
                        @can('queues.retry')
                            <x-ui.button variant="outline" size="sm" wire:click="retry('{{ $job->uuid }}')" wire:confirm="Retry this job? Confirm that its underlying failure has been fixed." wire:loading.attr="disabled" :aria-label="__('Retry job :id', ['id' => $job->uuid])">{{ __('Retry') }}</x-ui.button>
                        @endcan
                    </x-ui.table.td>
                </tr>
            @empty
                <tr><x-ui.table.td colspan="4">{{ __('No failed jobs on the monitored queues.') }}</x-ui.table.td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
        {{ $this->failures->links() }}
    </div>
</section>
