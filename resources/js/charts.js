/*
 * Chart rendering for the dashboard's trend and comparison series. Proportions left the canvas in the
 * 2026-11 redesign — `x-patterns.proportion-bar` draws them server-side with the shares as text.
 *
 * Every figure arrives already formatted. The payload is built server-side by App\Domain\Reporting\Series\Series,
 * whose `formatted` strings come from the app's own two money and quantity doors, so a chart's tooltip and its
 * table alternative cannot disagree about what `P 1 284 000,00` means, and no currency rule is re-derived here.
 * The only arithmetic in this file is `value / axis.scale` for an axis tick.
 *
 * Loaded by a dynamic import from app.js only when the DOM holds a `[data-fg-chart]` canvas, so no other page in
 * the app pays for the library.
 */
import { BarController, BarElement, CategoryScale, Chart, Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js';

// Two controllers, because two is the set the canvas doors are allowed to emit (asserted in ChartSeriesTest): an
// unregistered `type` is not a build error here, it is a chart that quietly never appears in the browser.
// Proportion moved off the canvas in the 2026-11 redesign (docs/REDESIGN-CONCEPT.md §4): a stacked bar reads
// its shares as text, so `x-patterns.proportion-bar` draws it server-side and the ring is gone.
Chart.register(BarController, BarElement, CategoryScale, Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip);

const FALLBACK = '#61666d';

/** Canvas → {chart, spec}, so a theme flip recolours in place instead of replaying the entrance animation. */
const mounted = new Map();

const hatched = new Map();

/**
 * Live token values, read from the document instead of baked in: head.blade.php resolves personal choice, the
 * company default and the device scheme, so a hard-coded hex would be correct in one theme only.
 */
const readPalette = () => {
    const styles = getComputedStyle(document.documentElement);
    const token = (name) => styles.getPropertyValue(name).trim() || FALLBACK;

    return {
        ink: token('--color-fg-ink'),
        inkSecondary: token('--color-fg-ink-secondary'),
        inkMuted: token('--color-fg-ink-muted'),
        line: token('--color-fg-line'),
        surface: token('--color-fg-surface-raised'),
        mono: styles.getPropertyValue('--font-mono').trim() || 'monospace',
        reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    };
};

/**
 * A series distinguished by texture as well as colour (docs/DESIGN-SYSTEM.md "distinguished by line style
 * and label placement as well as colour"): this app's financial semantics put meaning on red, so colour alone is
 * not a difference every seat can read.
 */
const hatch = (colour, palette) => {
    if (!hatched.has(colour)) {
        const tile = document.createElement('canvas');
        tile.width = 8;
        tile.height = 8;

        const context = tile.getContext('2d');
        context.fillStyle = colour;
        context.fillRect(0, 0, 8, 8);
        context.strokeStyle = palette.surface;
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(-2, 6);
        context.lineTo(6, -2);
        context.moveTo(2, 10);
        context.lineTo(10, 2);
        context.stroke();

        hatched.set(colour, context.createPattern(tile, 'repeat') ?? colour);
    }

    return hatched.get(colour);
};

/**
 * An axis tick, built from the parameters the door sent (docs/DESIGN-SYSTEM.md the grouping and decimal separator, the column's
 * `scale`, its prefix or suffix) rather than from anything guessed in JS.
 */
const scaleTick = (value, axis) => {
    const decimals = axis.decimals ?? 0;
    const sign = value < 0 ? '−' : '';
    const [whole, fraction = ''] = Math.abs(value / (axis.scale ?? 1)).toFixed(decimals).split('.');
    const digits = whole.replace(/\B(?=(\d{3})+(?!\d))/g, axis.grouping ?? ' ');

    return `${sign}${axis.prefix ?? ''}${digits}${fraction ? (axis.decimal ?? ',') + fraction : ''}${axis.suffix ?? ''}`;
};

/**
 * Value labels written into the plot (docs/UX-UI-PLAN.md "direct value labels on small datasets"). A
 * handful of bars should be readable without a hover, and the printed page has no hover at all.
 */
const valueLabels = {
    id: 'fgValueLabels',
    afterDatasetsDraw(chart) {
        const limit = chart.options.plugins.fgValueLabels?.max ?? 0;

        if (!limit) {
            return;
        }

        const context = chart.ctx;

        context.save();
        context.font = `500 11px ${chart.$palette?.mono ?? 'monospace'}`;
        context.fillStyle = chart.$palette?.ink ?? FALLBACK;
        context.textAlign = 'center';
        context.textBaseline = 'middle';

        chart.data.datasets.forEach((dataset, datasetIndex) => {
            const points = chart.getDatasetMeta(datasetIndex).data;
            const strings = dataset.formatted ?? [];

            if (points.length > limit) {
                return;
            }

            // On a bar with a line drawn over it (litres and cost), only the bars get labels: two sets of numbers
            // at the same x collide, and the second series is read off its own axis.
            if (chart.config.type === 'bar' && datasetIndex > 0) {
                return;
            }

            // Above the bar, or beside it when the bars run horizontally.
            const horizontal = chart.options.indexAxis === 'y';

            context.textAlign = horizontal ? 'left' : 'center';

            points.forEach((point, index) => {
                if (!strings[index]) {
                    return;
                }

                if (horizontal) {
                    context.fillText(strings[index], point.x + 6, point.y);
                } else {
                    context.fillText(strings[index], point.x, point.y - 12);
                }
            });
        });

        context.restore();
    },
};

Chart.register(valueLabels);

const specFor = (canvas) => {
    const island = document.getElementById(`fg-chart-data-${canvas.dataset.fgChart}`);

    if (!island) {
        return null;
    }

    try {
        return JSON.parse(island.textContent);
    } catch {
        return null;
    }
};

/** Colour the datasets and the axes from the current tokens. Safe to re-run on a theme flip. */
const paint = (chart, spec, palette) => {
    const styles = getComputedStyle(document.documentElement);
    const resolve = (name) => styles.getPropertyValue(name).trim() || FALLBACK;

    /** One colour per point where the door sent a list (a bucketed bar), one per series otherwise. */
    const oneOrList = (value) => (Array.isArray(value) ? value : [value]);

    const solids = (source) => oneOrList(source.colour).map(resolve);

    const fills = (source) => {
        const colours = solids(source);
        const patterns = oneOrList(source.pattern);

        return colours.map((colour, index) => (patterns[index % patterns.length] === 'stripe' ? hatch(colour, palette) : colour));
    };

    chart.data.datasets.forEach((dataset, index) => {
        const source = spec.datasets[index];

        dataset.borderColor = Array.isArray(source.colour) ? solids(source) : solids(source)[0];
        dataset.pointBorderColor = solids(source)[0];
        dataset.pointBackgroundColor = solids(source)[0];
        // Two series that differ by line style before they differ by hue.
        dataset.borderDash = source.pattern === 'dash' ? [6, 3] : [];

        // No fill under a line (docs/DESIGN-SYSTEM.md "Charts get no fill gradients and no 3D"); a bar is solid, or striped when
        // it is the slice carrying the warning.
        dataset.backgroundColor = spec.type === 'bar' ? fills(source) : 'transparent';
        dataset.fill = false;
    });

    Object.values(chart.options.scales).forEach((scale) => {
        const axis = spec.axes.find((candidate) => candidate.id === scale.$axisId);

        scale.ticks.color = palette.inkMuted;
        scale.grid.color = scale.type === 'category' ? 'transparent' : palette.line;

        if (scale.title) {
            scale.title.color = palette.inkMuted;
        }

        if (axis) {
            scale.ticks.callback = (value) => scaleTick(value, axis);
        }
    });

    chart.options.plugins.legend.labels.color = palette.inkSecondary;
    chart.options.plugins.legend.labels.font = { family: palette.mono, size: 11 };
    chart.options.plugins.tooltip.backgroundColor = palette.surface;
    chart.options.plugins.tooltip.borderColor = palette.line;
    chart.options.plugins.tooltip.titleColor = palette.ink;
    chart.options.plugins.tooltip.bodyColor = palette.ink;
    chart.options.plugins.tooltip.titleFont = { family: palette.mono, size: 11 };
    chart.options.plugins.tooltip.bodyFont = { family: palette.mono, size: 12 };

    chart.$palette = palette;
};

/**
 * Tooltips are keyboard-reachable. A canvas is one picture, so there is no bar to tab
 * into, and the yard office phone has no mouse: the arrows walk the series instead.
 */
const bindKeyboard = (canvas, chart) => {
    canvas.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            chart.setActiveElements([]);
            chart.tooltip.setActiveElements([], { x: 0, y: 0 });
            chart.update('none');

            return;
        }

        const last = chart.data.labels.length - 1;
        const current = chart.getActiveElements().at(0)?.index ?? -1;
        const moves = { ArrowRight: Math.min(last, current + 1), ArrowLeft: Math.max(0, current - 1), Home: 0, End: last };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();

        const point = chart.getDatasetMeta(0).data[moves[event.key]];

        if (!point) {
            return;
        }

        const { x, y } = point.tooltipPosition();
        chart.setActiveElements([{ datasetIndex: 0, index: moves[event.key] }]);
        chart.tooltip.setActiveElements([{ datasetIndex: 0, index: moves[event.key] }], { x, y });
        chart.update('none');
    });
};

const baseOptions = (spec, palette) => ({
    responsive: true,
    // Height comes from the wrapper, never from a width query (docs/UX-UI-PLAN.md the U6).
    maintainAspectRatio: false,
    // Which way the bars run is the door's call, not this file's: five bucket names read better as rows.
    indexAxis: spec.indexAxis ?? 'x',
    // The reduced-motion media block in app.css cannot reach inside a canvas, so the entrance asks here.
    animation: palette.reducedMotion ? false : { duration: 180, easing: 'easeOutQuad' },
    interaction: { mode: 'nearest', intersect: false },
    layout: { padding: { top: 14, right: 8, bottom: 0, left: 0 } },
    plugins: {
        // Always visible: a legend you have to discover is not a legend.
        legend: {
            display: true,
            position: 'top',
            align: 'start',
            labels: { usePointStyle: true, boxWidth: 8, color: palette.inkSecondary, font: { family: palette.mono, size: 11 } },
        },
        tooltip: {
            enabled: true,
            borderWidth: 1,
            callbacks: {
                label: (item) => {
                    const figure = (item.dataset.formatted ?? [])[item.dataIndex] ?? item.parsed?.y ?? item.parsed;

                    return ` ${item.dataset.label}: ${figure}`;
                },
            },
        },
        // Direct labels for the small pictures a seat reads at a glance: a bucketed bar.
        // A six-month line is twelve numbers, which is a table — and the table is already on the page below it.
        fgValueLabels: { max: spec.type === 'bar' ? 12 : 0 },
    },
    scales: {},
});

const mount = (canvas) => {
    const spec = specFor(canvas);

    if (!spec) {
        return;
    }

    // Idempotent, because a seat can change the window four times in one page and every re-render would otherwise
    // leak the previous instance into Chart.js's registry.
    Chart.getChart(canvas)?.destroy();

    const palette = readPalette();
    const chart = new Chart(canvas, {
        type: spec.type,
        data: {
            labels: spec.labels,
            datasets: spec.datasets.map((dataset) => ({
                label: dataset.label,
                data: dataset.data,
                formatted: dataset.formatted,
                type: dataset.type,
                yAxisID: dataset.axis ?? 'y',
                tension: 0,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                maxBarThickness: 34,
            })),
        },
        options: baseOptions(spec, palette),
    });

    if (spec.type !== 'doughnut') {
        spec.axes.forEach((axis) => {
            chart.options.scales[axis.id] = {
                $axisId: axis.id,
                type: 'linear',
                position: axis.position ?? 'left',
                beginAtZero: true,
                // Only one grid ever prints, so a dual-axis chart does not read as two overlapping rulers.
                grid: { color: palette.line, drawOnChartArea: axis.drawGrid !== false },
                border: { display: false },
                ticks: { color: palette.inkMuted, font: { family: palette.mono, size: 11 } },
                title: { display: Boolean(axis.label), text: axis.label ?? '', color: palette.inkMuted },
            };
        });

        // A horizontal bar puts its category on `y`, so the axis the labels live on follows the door's choice.
        const category = spec.indexAxis === 'y' ? 'y' : 'x';

        chart.options.scales[category] ??= {
            $axisId: category,
            type: 'category',
            offset: spec.type === 'bar',
            grid: { color: 'transparent', drawOnChartArea: false },
            border: { display: false },
            ticks: { color: palette.inkMuted, font: { family: palette.mono, size: 11 }, maxRotation: 0, autoSkip: true },
            title: { display: false, color: palette.inkMuted },
        };
    }

    paint(chart, spec, palette);
    chart.update('none');

    mounted.set(canvas, { chart, spec });
    bindKeyboard(canvas, chart);
};

/**
 * Each canvas mounts on its own. Without the guard one bad series took the whole band down with it and lost the
 * error as an unhandled rejection inside a dynamic import, where nothing prints it: a chart that silently never
 * appears is the worst outcome this screen can produce, because the seat reads an empty box as "no data".
 *
 * The table rows are server-rendered, so the figures survive a failed picture — the toast says which is which.
 */
export const mountAll = () => {
    document.querySelectorAll('[data-fg-chart]').forEach((canvas) => {
        try {
            mount(canvas);
        } catch (error) {
            console.error(`fg-chart: ${canvas.dataset.fgChart} did not mount`, error);
            window.fgToast?.('A chart on this page could not be drawn — its figures are in the table below it.', 'warning');
        }
    });
};

const restyle = () => {
    const palette = readPalette();

    mounted.forEach(({ chart, spec }) => {
        if (!chart.canvas?.isConnected) {
            return;
        }

        paint(chart, spec, palette);
        // 'none', or every theme flip would look like fresh data arriving.
        chart.update('none');
    });
};

// head.blade.php dispatches this from the one place all four theme paths already funnel through.
document.addEventListener('fg:theme', restyle);
