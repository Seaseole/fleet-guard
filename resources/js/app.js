import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import './trends.js';

document.addEventListener('alpine:init', () => {
    // One timestamp shared by every tooltip in the app, so a seat sweeping the metric rail gets the second
    // bubble instantly. It cannot live in the component: `x-ui.tooltip` instances are separate Alpine scopes,
    // and the urgency the second one answers to was created by the first.
    window.Alpine?.store?.('fgTooltip', { last: 0 });

    window.Alpine?.store?.('fgToasts', {
        items: [],
        lastId: 0,
        push({ message, kind = 'info', duration = null, action = null }) {
            const ttl = duration ?? (action ? 10000 : 5000);
            const toast = { id: ++this.lastId, message, kind, action };

            // Keep at most three stacked.
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

// Chart.js is the only thing in the app that needs a rendering library, so it stays out of every page's module
// graph: the chunk is fetched when the DOM actually holds a canvas, and not otherwise. Three seams, because a
// chart can appear on the first paint, on a morph, or after a `wire:navigate` swap — the last of which is the
// hazard the theme bootstrap in partials/head.blade.php documents, for the same reason.
const mountCharts = () => {
    if (document.querySelector('[data-fg-chart]')) {
        import('./charts.js').then((module) => module.mountAll());
    }
};

// Select2 arrives the same way and for the same reason: jQuery and the widget are only worth their bytes to
// a page that actually shows a searchable choice. The kiosk keeps its native control, so a driver's tablet
// downloads neither.
const mountSelects = () => {
    if (document.querySelector('select[data-pharaonic="select2"]')) {
        import('./select2.js').then((module) => module.mount());
    }
};

Livewire.start();

mountCharts();
mountSelects();

// The stagger window. `html[data-fg-first-paint]` is what app.css gates `.fg-stagger` behind, and it is
// raised around an *arrival* — the first document and each `wire:navigate` swap — then dropped. It has to
// drop: a register's rows are re-rendered by every keystroke in its search field, and a list that
// re-staggers on each one is motion attached to typing, which is the case the design system rules out.
const armStagger = () => {
    document.documentElement.setAttribute('data-fg-first-paint', '');
    window.setTimeout(() => document.documentElement.removeAttribute('data-fg-first-paint'), 900);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', armStagger, { once: true });
} else {
    armStagger();
}

document.addEventListener('livewire:navigated', armStagger);

Livewire.hook('morph.added', mountCharts);
Livewire.hook('morph.added', mountSelects);
Livewire.hook('morph.updated', mountCharts);
Livewire.hook('morph.updated', mountSelects);
document.addEventListener('livewire:navigated', mountCharts);
document.addEventListener('livewire:navigated', mountSelects);
