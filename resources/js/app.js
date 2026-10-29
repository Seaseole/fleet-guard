import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

document.addEventListener('alpine:init', () => {
    window.Alpine?.store?.('fgToasts', {
        items: [],
        lastId: 0,
        push({ message, kind = 'info', duration = null, action = null }) {
            const ttl = duration ?? (action ? 10000 : 5000);
            const toast = { id: ++this.lastId, message, kind, action };

            // Keep at most three stacked (docs/DESIGN-SYSTEM.md §5).
            this.items = [...this.items.slice(-2), toast];

            window.setTimeout(() => this.dismiss(toast.id), ttl);

            return toast.id;
        },
        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });
});

// Any Action or Livewire component can raise one with:
//   $this->dispatch('toast', message: 'Trip T-07 dispatched', kind: 'success');
document.addEventListener('livewire:init', () => {
    window.Livewire?.on?.('toast', (event) => {
        const { message, kind, action } = event ?? {};

        if (message) {
            window.Alpine.store('fgToasts').push({ message, kind, action });
        }
    });
});

// Escape hatch for plain JS callers (kiosk signature pad, map overlays).
window.fgToast = (message, kind = 'info') =>
    window.Alpine?.store?.('fgToasts')?.push({ message, kind });

Livewire.start();
