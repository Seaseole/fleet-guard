/*
 | The behaviour layer of x-patterns.trends-chart: the diverging cash columns. The server already
 | rendered the final frame (bars, zero rail, ticks, labels, readout); this adds the three things a
 | static picture cannot do: it grows in, it scrubs, and it re-measures when the period changes.
 |
 | Three rules the whole file obeys:
 |
 | 1. Colour is never decided here. A bar carries its series as a Tailwind class; the only properties
 |    set inline are the ones an animation needs mid-flight, and they are handed back to the class on
 |    completion, which is what keeps a theme flip working while anything is still moving.
 | 2. One clock. Every animation goes through anime.js; there is no rAF loop and no scroll listener.
 |    Under prefers-reduced-motion nothing runs and the server frame simply stays.
 | 3. The layout is the payload's layout. layout() mirrors CashTrendsPayload::layout() so the first
 |    frame and a redraw cannot disagree, and a height is a continuous division of the axis magnitude
 |    into pixels rather than a count of units: a small month is a short bar, not nothing.
 */

import { animate } from 'animejs';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const SVG_NS = 'http://www.w3.org/2000/svg';

const DAY = 86_400_000;

/** The smallest 1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6 or 8 times a power of ten that holds the taller side. */
function barTicks(max, steps = 3) {
    if (!(max > 0)) return [0];

    const base = 10 ** Math.floor(Math.log10(max));
    let top = base * 10;

    for (const m of [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) {
        if (base * m >= max) {
            top = base * m;
            break;
        }
    }

    return Array.from({ length: steps + 1 }, (_, i) => (top * i) / steps);
}

document.addEventListener('alpine:init', () => {
    window.Alpine?.data('fgTrends', () => ({
        payload: null,
        period: '',
        hidden: [],
        index: 0,
        hovered: null,
        root: null,

        start(root) {
            // Not called `init`: Alpine treats any `init()` on x-data as its own magic hook and
            // invokes it with no arguments, which is how the island element never arrives.
            const island = root.querySelector('script[type="application/json"]');

            if (!island) return;

            this.root = root;
            this.payload = JSON.parse(island.textContent);
            this.period = this.payload.periods[0].value;
            this.index = Math.max(0, this.current().data.length - 1);
            this.updateTotal();

            // The server drew at its nominal width; if the box it landed in is narrower (a phone, a
            // half-width band), re-lay out before the entrance, not on the first resize the visitor
            // never makes.
            const plot = this.q('[data-trends-plot]');

            if (plot && Math.abs(plot.clientWidth - this.payload.geometry.width) > 2) {
                this.redraw();
            }

            this.entrance();

            // The grid is drawn at the measured width; a real resize re-lays it out (a phone rotating
            // is the only case that reaches this, since the card never reflows mid-animation).
            let last = plot?.clientWidth;

            if (plot && 'ResizeObserver' in window) {
                const observer = new ResizeObserver(([entry]) => {
                    const width = Math.round(entry.contentRect.width);

                    if (last !== undefined && Math.abs(width - last) > 2) {
                        last = width;
                        this.redraw();
                    }
                });

                observer.observe(plot);
                this.cleanups(() => observer.disconnect());
            }
        },

        // Alpine's own teardown path, so a card swapped out by wire:navigate takes its observers with it
        // rather than holding a detached plot alive.
        cleanups(fn) {
            const scope = window.Alpine?.$data?.(this.root);

            if (typeof scope?.$cleanup === 'function') scope.$cleanup(fn);
        },

        q(selector) {
            return this.root?.querySelector(selector) ?? null;
        },

        qa(selector) {
            return [...(this.root?.querySelectorAll(selector) ?? [])];
        },

        current() {
            return this.payload?.periods.find((p) => p.value === this.period) ?? this.payload?.periods[0];
        },

        fmt(minor, decimals = 2) {
            const axis = this.payload.axis;
            const major = minor / axis.scale;
            const fixed = Math.abs(major).toFixed(decimals);
            const [int, frac] = fixed.split('.');
            const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, axis.grouping);

            return (major < 0 ? '-' : '') + axis.prefix + grouped + (frac ? axis.decimal + frac : '');
        },

        compact(minor) {
            const axis = this.payload.axis;
            const major = minor / axis.scale;

            if (major >= 1000) {
                const k = (major / 1000).toFixed(2).replace(/\.?0+$/, '');

                return axis.prefix + k + 'K';
            }

            return this.fmt(minor, 0);
        },

        // -- layout, mirroring CashTrendsPayload::layout() so the two cannot disagree --

        layout() {
            const g = this.payload.geometry;
            const data = this.visible();
            const plot = this.q('[data-trends-plot]');
            const width = plot?.clientWidth || g.width;
            const n = data.length;
            let max = 0;

            for (const bucket of data) max = Math.max(max, Math.max(0, bucket.in), Math.max(0, bucket.out));

            const ticks = barTicks(max, g.tickSteps);
            const top = Math.max(1, ticks[ticks.length - 1] || 1);
            const zeroY = g.height / 2;
            const usable = zeroY - g.inset;
            const slot = n > 0 ? width / n : width;
            const barWidth = n > 0 ? Math.max(g.barMin, Math.min(g.barMax, slot - g.slotGap)) : g.barMax;

            const columns = data.map((bucket, j) => ({
                x: slot * j + (slot - barWidth) / 2,
                inHeight: this.barHeight(bucket.in, top, usable, g),
                outHeight: this.barHeight(bucket.out, top, usable, g),
            }));

            return { ticks, top, zeroY, usable, slot, barWidth, columns, width, n };
        },

        /** A bucket that moved is never an invisible hairline; a bucket that did not draws no bar. */
        barHeight(minor, top, usable, g) {
            if (!(minor > 0)) return 0;

            return Math.min(usable, Math.max(g.minBar, (minor / top) * usable));
        },

        visible() {
            return this.current().data.map((bucket) => ({
                ...bucket,
                in: this.hidden.includes('in') ? 0 : bucket.in,
                out: this.hidden.includes('out') ? 0 : bucket.out,
            }));
        },

        redraw({ morph = false } = {}) {
            if (!this.payload) return;

            const l = this.layout();

            this.drawBars(l);
            this.drawTicks(l);
            this.drawXLabels(l);
            this.updateMarker(l);
            this.updateTotal();

            // aria on the slider
            const slider = this.q('[role="slider"]');

            if (slider) {
                slider.setAttribute('aria-valuemax', String(Math.max(1, l.n)));
                slider.setAttribute('aria-valuenow', String(Math.min(this.index + 1, Math.max(1, l.n))));
                slider.setAttribute('aria-valuetext', this.describe());
            }

            if (morph && !reducedMotion()) {
                this.qa('[data-trends-column]').forEach((el, j) => {
                    animate(el, { translateX: [8, 0], opacity: [0.4, 1], duration: 600, delay: j * 6, ease: 'outExpo' });
                });
            }
        },

        drawBars(l) {
            const host = this.q('[data-trends-columns]');

            if (!host) return;

            host.replaceChildren();

            const fills = { in: 'fill-accent', out: 'fill-fg-line-strong' };
            const radius = Math.min(2, l.barWidth / 2).toFixed(2);
            const data = this.visible();

            l.columns.forEach((column, j) => {
                const group = document.createElementNS(SVG_NS, 'g');
                group.setAttribute('data-trends-column', '');
                group.setAttribute('data-col', String(j));

                for (const series of ['in', 'out']) {
                    const height = series === 'in' ? column.inHeight : column.outHeight;

                    if (!(data[j]?.[series] > 0) || !(height > 0)) continue;

                    const rect = document.createElementNS(SVG_NS, 'rect');
                    rect.setAttribute('data-trends-bar', '');
                    rect.setAttribute('data-series', series);
                    rect.setAttribute('data-col', String(j));
                    rect.setAttribute('x', column.x.toFixed(2));
                    rect.setAttribute('y', (series === 'in' ? l.zeroY - height : l.zeroY).toFixed(2));
                    rect.setAttribute('width', l.barWidth.toFixed(2));
                    rect.setAttribute('height', height.toFixed(2));
                    rect.setAttribute('rx', radius);
                    rect.setAttribute('class', `${fills[series]} transition-[fill] duration-(--t-base) ease-(--ease-standard)`);
                    group.appendChild(rect);
                }

                host.appendChild(group);
            });
        },

        /**
         * One magnitude scale printed on both sides of the rail, so the distance from the zero reads the
         * same whichever way the money went. The labels live in the gutter, never over the plot.
         */
        drawTicks(l) {
            const host = this.q('[data-trends-axis]');

            if (!host) return;

            host.replaceChildren();

            const height = this.payload.geometry.height;

            l.ticks.forEach((tick) => {
                const offset = l.usable * (tick / l.top);

                this.appendTick(host, this.compact(tick), l.zeroY - offset - 7, height);

                if (tick > 0) {
                    this.appendTick(host, this.compact(tick), l.zeroY + offset - 7, height);
                }
            });
        },

        appendTick(host, text, top, height) {
            const span = document.createElement('span');
            span.setAttribute('data-trends-tick', '');
            span.className = 'absolute left-0 whitespace-nowrap text-xs leading-4 text-fg-ink-muted';
            span.style.top = `${Math.round(Math.min(Math.max(top, 0), height - 14))}px`;
            span.textContent = text;
            host.appendChild(span);
        },

        drawXLabels(l) {
            const host = this.q('[data-trends-xlabels]');

            if (!host) return;

            host.replaceChildren();

            const data = this.current().data;

            if (data.length === 0) return;

            // A label keeps its own stride before it collides with its neighbour, and a window wider
            // than the frame drops the labels in between rather than overprinting them.
            const every = Math.max(1, Math.ceil(data.length / Math.max(1, Math.floor(l.width / this.payload.geometry.labelStride))));
            const spansDays = (Date.parse(data[data.length - 1].end) - Date.parse(data[0].date)) < 60 * DAY;
            const multiYear = new Date(data[0].date).getUTCFullYear() !== new Date(data[data.length - 1].end).getUTCFullYear();

            data.forEach((bucket, j) => {
                if (j % every !== 0) return;

                const date = new Date(bucket.date);
                const text = spansDays
                    ? `${date.getUTCDate()} ${date.toLocaleDateString(undefined, { month: 'short', timeZone: 'UTC' })}`
                    : date.toLocaleDateString(undefined, {
                        month: 'short',
                        year: multiYear ? 'numeric' : undefined,
                        timeZone: 'UTC',
                    });

                const outer = document.createElement('span');
                outer.className = 'absolute top-0';
                outer.style.left = `${((l.columns[j]?.x ?? 0) + l.barWidth / 2).toFixed(2)}px`;

                const inner = document.createElement('span');
                inner.className = 'block -translate-x-1/2 whitespace-nowrap text-xs leading-4 text-fg-ink-muted';
                inner.textContent = text;

                outer.appendChild(inner);
                host.appendChild(outer);
            });
        },

        activeIndex() {
            const n = this.current().data.length;

            return this.hovered !== null ? Math.min(this.hovered, n - 1) : Math.min(this.index, n - 1);
        },

        updateMarker(l) {
            const wrap = this.q('[data-trends-marker-wrap]');
            const active = this.activeIndex();
            const column = l.columns[active];

            if (!wrap || !column) return;

            const markerX = column.x + l.barWidth / 2;

            wrap.setAttribute('transform', `translate(${markerX.toFixed(2)} 0)`);
            wrap.querySelector('line')?.setAttribute('y2', String(this.payload.geometry.height));

            this.updateReadout(active, markerX, l);
        },

        /**
         * The readout follows the taller side of the bucket it names and is clamped inside the plot, so a
         * phone never gets it hanging off the frame.
         */
        updateReadout(active, markerX, l) {
            const tip = this.q('[data-trends-tooltip]');
            const bucket = this.current().data[active];

            if (!tip || !bucket) return;

            const g = this.payload.geometry;
            const column = l.columns[active] ?? { inHeight: 0, outHeight: 0 };
            const tallerUp = column.inHeight >= column.outHeight;
            const rawTop = tallerUp ? l.zeroY - column.inHeight - g.tipHeight : l.zeroY + column.outHeight + 8;

            tip.style.top = `${Math.round(Math.max(4, Math.min(rawTop, g.height - g.tipHeight - 4)))}px`;

            const tipWidth = tip.offsetWidth || g.tipWidth;

            tip.style.right = '';
            tip.style.left = `${Math.round(Math.max(8, Math.min(markerX + 10, Math.max(8, l.width - tipWidth - 8))))}px`;

            const date = tip.querySelector('p');

            if (date) date.textContent = this.spanText(bucket);

            tip.querySelectorAll('[data-trends-tip-value]').forEach((el) => {
                const value = Number(bucket[el.dataset.key === 'out' ? 'out' : 'in'] ?? 0);

                el.dataset.value = String(value);
                el.textContent = this.fmt(value);
            });
        },

        spanText(bucket) {
            const start = new Date(bucket.date);
            const end = new Date(bucket.end);
            const days = Math.round((end - start) / DAY) + 1;

            if (days <= 1) return start.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

            if (start.getUTCDate() === 1 && days >= 28) return start.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });

            return `${start.toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' })} to ${end.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })}`;
        },

        updateTotal() {
            const el = this.q('[data-trends-total]');

            if (!el) return;

            const total = this.current().data.reduce((sum, b) => sum + b.in - b.out, 0);

            el.dataset.target = String(total);
            el.textContent = this.fmt(total);
        },

        /**
         * The rows under the picture follow the control that re-plotted the bars. They are rewritten in
         * place from the door's own pre-formatted strings, and every cell keeps the class Blade printed,
         * so numeric alignment is never a second decision made here.
         */
        renderTable() {
            const table = this.q('[data-trends-table]');
            const spec = this.payload?.tables?.[this.period];
            const body = table?.querySelector('tbody');

            if (!body || !spec) return;

            const templates = [...(body.querySelector('tr')?.children ?? [])].map((td) => td.className);
            const totalsRow = table.querySelector('[data-trends-totals]');
            const totalsTemplates = [...(totalsRow?.children ?? [])].map((td) => td.className);
            const firstHead = table.querySelector('thead th');
            const caption = table.querySelector('caption');

            if (firstHead && spec.columns[0]) firstHead.textContent = spec.columns[0].label;
            if (caption) caption.textContent = spec.caption;

            table.closest('[role="region"]')?.setAttribute('aria-label', spec.caption);

            const row = (cells, classes, className) => {
                const tr = document.createElement('tr');

                if (className) tr.className = className;

                cells.forEach((cell, i) => {
                    const td = document.createElement('td');
                    td.className = classes[i] ?? '';
                    td.textContent = cell;
                    tr.appendChild(td);
                });

                return tr;
            };

            const rows = spec.rows.map((cells) => row(cells, templates));

            if (spec.totals.length) {
                rows.push(row(spec.totals, totalsTemplates, 'bg-fg-surface-sunk'));
            }

            body.replaceChildren(...rows);
        },

        describe() {
            const bucket = this.current().data[this.activeIndex()];

            if (!bucket) return '';

            return `${this.spanText(bucket)}: ${this.payload.inflowLabel.toLowerCase()} ${this.fmt(bucket.in)}, ${this.payload.outflowLabel.toLowerCase()} ${this.fmt(bucket.out)}`;
        },

        setPeriod(value) {
            if (value === this.period) return;

            const target = this.payload.periods.find((p) => p.value === value);

            if (!target) return;

            this.period = value;
            this.hovered = null;
            this.index = Math.max(0, target.data.length - 1);
            this.redraw({ morph: true });
            this.renderTable();
        },

        toggleSeries(key, el) {
            if (this.hidden.includes(key)) {
                this.hidden = this.hidden.filter((s) => s !== key);
                this.redraw();
                return;
            }

            if (['in', 'out'].filter((s) => !this.hidden.includes(s)).length <= 1) {
                // the last series shown stays: its item shakes instead
                if (!reducedMotion()) animate(el, { translateX: [0, -4, 4, -3, 3, 0], duration: 400, ease: 'outQuad' });
                return;
            }

            this.hidden = [...this.hidden, key];
            this.redraw();
        },

        columnAt(event) {
            const plot = this.q('[data-trends-plot]');
            const { slot, n } = this.layout();

            if (!plot || n < 1 || slot <= 0) return 0;

            const x = event.clientX - plot.getBoundingClientRect().left;

            return Math.min(n - 1, Math.max(0, Math.floor(x / slot)));
        },

        preview(event) {
            this.hovered = event === null ? null : this.columnAt(event);
            this.updateMarker(this.layout());

            const slider = this.q('[role="slider"]');
            slider?.setAttribute('aria-valuetext', this.describe());
        },

        pick(event) {
            this.index = this.columnAt(event);
            this.hovered = null;
            this.redraw();
        },

        onKey(event) {
            const n = this.current().data.length;
            const page = Math.max(1, Math.round(n / 8));
            const next = {
                ArrowRight: this.index + 1,
                ArrowUp: this.index + 1,
                ArrowLeft: this.index - 1,
                ArrowDown: this.index - 1,
                PageUp: this.index + page,
                PageDown: this.index - page,
                Home: 0,
                End: n - 1,
            }[event.key];

            if (next === undefined || n === 0) return;

            event.preventDefault();
            this.hovered = null;
            this.index = Math.min(Math.max(next, 0), n - 1);
            this.redraw();
        },

        // The columns grow away from the zero rail, the total counts up while they do, then the marker
        // runs down and the readout pops in with its amounts.

        entrance() {
            if (reducedMotion()) return;

            const plot = this.q('[data-trends-plot]');

            if (!plot) return;

            let started = false;

            const observer = new IntersectionObserver((entries) => {
                if (!entries.some((e) => e.isIntersecting) || started) return;

                started = true;
                observer.disconnect();
                this.playEntrance();
            });

            observer.observe(plot);
            this.cleanups(() => observer.disconnect());
        },

        playEntrance() {
            const bars = this.qa('[data-trends-bar]');
            const totalEl = this.q('[data-trends-total]');
            const tip = this.q('[data-trends-tooltip]');
            // The grow happens on the inner line, never on the wrapping <g>. That group is positioned by
            // its `transform` attribute, and an inline transform written here would shadow the attribute for
            // as long as it stayed — which is how the rail stopped following the scrub after its own entrance.
            const marker = this.q('[data-trends-marker-wrap]')?.querySelector('[data-trends-marker]');

            if (!bars.length) return;

            const counter = { total: 0, tip: 0 };
            const target = Number(totalEl?.dataset.target ?? 0);
            const tipValues = this.qa('[data-trends-tip-value]');

            // The origin is the rail, so a bar grows away from the zero it is measured from rather than
            // dropping in from the top of the frame.
            bars.forEach((bar) => {
                bar.style.opacity = '0';
                bar.style.transformBox = 'fill-box';
                bar.style.transformOrigin = bar.dataset.series === 'in' ? 'bottom center' : 'top center';
            });

            if (totalEl) totalEl.textContent = this.fmt(0);
            tipValues.forEach((el) => (el.textContent = this.fmt(0)));

            const count = () => {
                if (totalEl) totalEl.textContent = this.fmt(target * counter.total);
                tipValues.forEach((el) => (el.textContent = this.fmt(Number(el.dataset.value) * counter.tip)));
            };

            animate(bars, {
                opacity: [0, 1],
                scaleY: [0.02, 1],
                duration: 520,
                ease: 'outExpo',
                delay: (_, i) => 90 + i * 14,
                onComplete: (a) => {
                    a.targets.forEach((bar) => {
                        bar.style.opacity = '';
                        bar.style.transformBox = '';
                        bar.style.transformOrigin = '';
                    });
                },
            });

            animate(counter, {
                total: [0, 1],
                duration: 1500,
                ease: 'outExpo',
                onUpdate: count,
                delay: 180,
            });

            const shownAt = 900;

            if (marker) {
                marker.style.transformBox = 'fill-box';
                marker.style.transformOrigin = 'top';
                animate(marker, {
                    scaleY: [0, 1],
                    duration: 700,
                    ease: 'outExpo',
                    delay: shownAt,
                    onComplete: () => {
                        // anime.js leaves the settled value as an inline transform; the line carries no
                        // transform attribute to fight, but leaving it would still outlive the entrance.
                        marker.style.transform = '';
                        marker.style.transformBox = '';
                        marker.style.transformOrigin = '';
                    },
                });
            }

            if (tip) {
                animate(tip, {
                    opacity: [0, 1],
                    translateY: [6, 0],
                    scale: [0.94, 1],
                    duration: 500,
                    ease: 'outBack(1.6)',
                    delay: shownAt + 250,
                });
            }

            animate(counter, {
                tip: [0, 1],
                duration: 900,
                ease: 'outExpo',
                onUpdate: count,
                delay: shownAt + 250,
                onComplete: () => {
                    if (totalEl) totalEl.textContent = this.fmt(target);
                    tipValues.forEach((el) => (el.textContent = this.fmt(Number(el.dataset.value))));
                },
            });
        },

        // Legend emphasis: hovering one series fades the other's bars, class-driven so theme and
        // reduced motion are untouched.
        emphasize(key) {
            this.qa('[data-trends-bar]').forEach((bar) => {
                bar.style.opacity = bar.dataset.series !== key ? '0.35' : '';
            });
        },

        release() {
            this.qa('[data-trends-bar]').forEach((bar) => (bar.style.opacity = ''));
        },
    }));
});
