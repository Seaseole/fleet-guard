---
name: Fleet Operations Dashboard
version: 1.0
source: Horizon logistics dashboard reference image (values estimated from a raster still, ±1–2px / ±2% colour)
audience: designers, developers, AI coding agents
font: Inter (tabular-nums on all figures)
icons: outline, 1.5px stroke (Lucide or Heroicons outline)
---

# DESIGN.md: Fleet Operations Dashboard

This file is the single source of truth for UI decisions. Follow it when generating or editing any view, component or style. If a rule here conflicts with an existing component, this file wins. Values are estimates from one image; verify against the source design before shipping.

## 1. Character

A calm, data-dense operations console. White cards on a pale gray canvas, one green brand colour, and colour reserved for status. Everything else is neutral so deltas, alerts and vehicle states are noticed first.

1. Neutral first; colour always means something.
2. Cards are the unit of layout.
3. Numbers lead: large tabular figures, small muted captions.
4. Soft structure: hairline borders, generous radius, almost no shadow.
5. Progressive density: KPIs, then trend + map, then operational lists.

## 2. Tokens

### Colour

```css
:root {
  /* neutrals */
  --bg-canvas: #F7F7F7;      /* app background, sidebar */
  --surface: #FFFFFF;        /* cards, inputs, active nav pill */
  --border: #EDEDED;         /* card and input hairlines */
  --border-strong: #E0E0E3;  /* hover borders, dividers */
  --text: #101113;
  --text-muted: #5B6068;
  --text-subtle: #9AA0A6;    /* section labels, axis ticks, placeholders */
  --ink: #0B0B0C;           /* hub pills, route track, count badges */

  /* brand + status */
  --green-700: #146B3A;
  --green-600: #1B8F3C;      /* primary button, active nav */
  --green-500: #22A447;      /* in transit, primary series */
  --green-100: #E6F6EA;
  --teal-500: #14B8A6;       /* pick-up, secondary series */
  --teal-100: #DDF5F1;
  --amber-500: #F5B301;      /* delayed */
  --amber-100: #FFF6D6;
  --amber-text: #A16207;
  --orange-500: #F97316;     /* stationary */
  --red-500: #E5484D;        /* alert, below target */
  --red-100: #FDECEC;
  --blue-500: #2F80ED;       /* minor chart segment */

  /* shape + elevation */
  --radius-sm: 6px;          /* kbd chip */
  --radius-md: 10px;         /* buttons, inputs, nav items */
  --radius-lg: 14px;         /* inner cards, tooltips */
  --radius-xl: 16px;         /* outer cards, map container (up to 20) */
  --shadow-card: 0 1px 2px rgba(16, 17, 19, .04);
  --shadow-popover: 0 8px 24px rgba(16, 17, 19, .12);
}

[data-theme='dark'] {
  --bg-canvas: #0F1012;
  --surface: #17181B;
  --border: #26282D;
  --text: #F3F4F6;
  --text-muted: #A1A6AE;
}
```

### Semantic status map (one colour per status, everywhere)

| Status | Colour | Used in |
|---|---|---|
| In transit / healthy | green-500 | markers, pills, series, KPI border |
| Pick-up | teal-500 | markers, legend |
| Stationary | orange-500 | markers, legend |
| Delayed | amber-500 (text: amber-text) | markers, pills, series, donut, legend |
| Problem / below target | red-500 | KPI border, alert dot, negative delta |

Never reuse a status colour for a different meaning, and never show status by colour alone: pair it with a label or icon.

### Typography

| Role | Size / line | Weight |
|---|---|---|
| Page title | 20 / 28 | 600 |
| Card title | 18 / 24 | 600 |
| KPI value | 32 / 36 | 600 |
| Secondary metric | 28 / 32 | 600 |
| Entity ID (TRC-001) | 16 / 24 | 600 |
| Nav, body, button | 14 / 20 | 500 / 400 |
| KPI label, legend | 14 / 20 | 400 (muted) |
| Caption, axis tick, pill | 12–13 / 16 | 400 / 500 |
| Section label | 11–12 / 16, uppercase, +0.06em | 500 (subtle) |

Minimum size for meaningful text: 12px. Truncate long names with an ellipsis and expose the full value in a tooltip.

### Spacing

4px base scale: 4, 8, 12, 16, 20, 24, 32. Card padding 20. Gap between cards 12. Gap between sibling elements inside a card 8–16.

## 3. Layout

| Region | Spec |
|---|---|
| Sidebar | 240px fixed, `--bg-canvas`, 16px padding, own scroll, pinned footer |
| Top bar | 64px; page title left, controls right |
| Main | fluid, 20–24px padding, 24-column grid, 12px gutters |

| Row | Cards | Column spans |
|---|---|---|
| 1 | Operations overview (4 KPI blocks) | 24 |
| 2 | Trend chart + Live map | 10 + 14 |
| 3 | Active fleet + Loading status + Order status | 10 + 7 + 7 |

Row 2 cards share height. Row 3 cards scroll internally; they never grow the page.

Breakpoints: 1280+ full · 1024–1279 icon-rail sidebar, KPIs 2x2 · 768–1023 single column, map first · under 768 bottom nav, KPI row scrolls horizontally, map full-bleed with a bottom sheet.

### Sidebar order

1. Logo tile (32px, green gradient, radius 10), wordmark, collapse button
2. Search input (36px) with a `⌘F` hint chip
3. MAIN MENU: Dashboard, Fleet Management (expandable), Shipments and Deliveries (expandable), Warehouse, Route Planning, Analytics
4. SUPPORT: Customers, Team and Roles, Help
5. Promo card
6. Settings, Dark mode toggle

## 4. Components

**Button.** Primary: green-600 fill, white text, 14/500, height 36–40, radius 10, optional 16px leading icon; hover green-700. Secondary: white, 1px border. Icon button: 40x40 white, 1px border, radius 10; notification variant has an 8px red dot with a 2px white ring. States: default, hover, pressed, focus-visible (2px green-600 ring, 2px offset), disabled (50% opacity), loading (spinner, label stays).

**Input / select.** White, 1px border, radius 10, leading icon, subtle placeholder. Select and date range add a trailing chevron and open a popover with presets (Today, 7 days, 30 days, Custom). Optional trailing `kbd` chip (gray-100, radius 6, 12px).

**Nav item.** Default: transparent, muted. Hover: black at 4%. Active: white pill, 1px border, green-600 text (500) and icon. Expandable: trailing chevron that rotates on open. Child item: indented 28px, 1px vertical guide, small dot, height 36. Count badge: 20px circle, ink fill, white 12/600.

**Card.** White, 1px border, radius 16–20, padding 20. Header: title left; controls and kebab menu right. 16px gap to content.

**KPI block.** 2px left accent border (green healthy, red below target). Row 1: 16px icon + muted label. Row 2: value (32/600 tabular) left, delta pill right. Row 3: caption left, comparison text ('vs yesterday') right, 13px muted. Delta pill: 12/500, padding 2x8, radius full; colour means good or bad, not direction (a drop in delivery time is green). Include a trend arrow and an accessible label.

**Status pill.** 12/500, padding 4x12, radius full, always with text. In transit: green-100 + green-700. Delayed: amber-100 + amber-text.

**Legend toggle.** Two-up segmented control, dot + label, 1px border, radius 10. Click shows or hides a series; at least one stays visible; hidden series dims to 40%.

**Line / area chart.** Ticks 13px subtle; active x tick bold and `--text`. Grid 1px `#EFEFEF`, horizontal and vertical. Series 2px; primary green-500 with gradient area (18% to 0%), secondary teal-500 with no area. Points: 8px hollow circles, solid on hover. Hover: dashed horizontal crosshair with an ink pill on the y-axis. Tooltip: white, radius 14, popover shadow, gray-50 header band with the time, rows of dot + label + right-aligned tabular value. Arrow keys step between points.

**Map.** Container radius 16 inside the card with a desaturated light basemap. Vehicle marker: 36px disc filled by status, 2px white ring, white arrow rotated to heading, white label chip above (13/600, radius 10). Hub marker: ink pill with building icon and white 13/500 text. Legend bar pinned bottom with four equal cells: In transit, Pick-up, Stationary, Delayed. Header search ('Search for fleet') pans to and highlights the match. Markers must differ by more than colour (arrow, icon or ring style).

**Active fleet row.** Inner card, 1px border, radius 14. Header: ID, bullet, ETA, status pill right. Dashed divider. Body: 56px thumbnail tile (gray-50, radius 10), origin code over city, route progress, destination code over city. Progress: 4px ink track for travelled portion, dashed light-gray remainder, 20px ink knob with play glyph.

**Loading status tiles.** 2x2 grid divided by 1px lines. Top tiles: metric (28/600), caption and a mini bar chart (four teal bars with top cap line and fading gradient). Bottom tiles: metric and caption.

**Donut (order status).** 14px ring, 2 degree gaps, rounded caps, faint outer track. Centre: total (32/600) plus 13px label. Legend in three columns: dot, label (14/600), 'percent · count' in muted 12.

**Promo card.** Radius 16, mint gradient header (#BDEFD3 to #FFFFFF), 40px teal icon tile, title 15/600, body 13 muted, outline button.

**Toggle.** 36x20 track, 16px knob, green-600 when on, 150ms transition.

## 5. Interaction and motion

| Element | Trigger | Behaviour |
|---|---|---|
| Sidebar group | Click | Expands with 200ms height ease; state persists per user |
| Sidebar | Collapse button | 64px icon rail; labels become tooltips |
| Search | `⌘F` or `/` | Focus sidebar search; results grouped by vehicle, shipment, customer |
| Date range | Select preset | All widgets refetch together; skeletons while loading |
| Chart | Hover / focus | Crosshair, y pill and tooltip follow the nearest point |
| Map marker | Hover | Label chip lifts; preview card appears |
| Map marker | Click | Side panel: driver, load, ETA, route, last ping |
| Fleet row | Click | Opens shipment detail; knob reflects live progress |
| KPI block | Click | Drills into the filtered list for that metric |
| Kebab menu | Click | Export, refresh, configure widget |
| Dark mode | Toggle | Swaps token set; no layout shift |

Timing: 150–200ms ease-out for hover and expand; 300ms chart draw-in; marker positions interpolate over the update interval. Honour `prefers-reduced-motion`.

## 6. States

Every card implements all four: **loading** (skeleton matching the final shape), **empty** (icon, one-line explanation, action), **error** (message plus Retry), **stale** (muted 'Last updated' turns amber after the refresh interval). Use 24-hour time without AM/PM, for example 'Today, 14:32'.

## 7. Accessibility

- Text contrast 4.5:1 minimum, UI components 3:1. Do not use green-500 or amber-500 for body text on white.
- Visible 2px focus ring on every interactive element; tooltips open on keyboard focus.
- Every chart has a 'View as table' alternative.
- Real-time updates go through a polite, throttled live region.
- Touch targets at least 40px.

## 8. Do and don't

**Do**
- Use tokens only; no raw hex or arbitrary pixel values inside components.
- Give each status one colour across chart, map, pills and legend.
- Title widgets by what they show ('Live fleet map', not a repeated 'Delivery performance').
- Keep components presentational; fetch data in the page or container layer.
- Key shared caches by tenant.

**Don't**
- Don't convey status by colour alone.
- Don't add shadows to cards.
- Don't render hundreds of DOM markers; use a single GeoJSON source with clustering at low zoom.
- Don't use placeholder data (identical vehicle IDs) or truncate without a tooltip.
- Don't let row 3 content clip at the fold.

## 9. Implementation notes (Laravel + Livewire + Tailwind)

- Map tokens into the Tailwind v4 `@theme` so utilities like `bg-surface` and `text-muted` exist.
- Folders: `components/ui` (primitives), `components/layout` (shell), `components/patterns` (stat, fleet-row, chart-card, map-card). Livewire components own queries, filters and polling.
- Charts: ECharts, Chart.js (with an annotation plugin for the crosshair) or ApexCharts, wrapped once and themed through CSS variables.
- Maps: MapLibre GL or Leaflet with a light, low-saturation basemap.
- Real time: WebSockets (Laravel Reverb or Echo) for positions; `wire:poll` at 15–60s for KPIs; debounce search at 250ms.
- Definition of done: tokens only, four data states, keyboard and screen-reader support, responsive at 375 / 768 / 1280 / 1600, documented props, visual regression snapshots in both themes, axe checks passing.

## 10. Roadmap (not in the reference)

| Priority | Feature |
|---|---|
| P1 | Live GPS tracking with breadcrumbs and route replay; geofencing and zone alerts; alert centre; KPI drill-down |
| P2 | Predictive ETA; command palette (`⌘K`); saved views; customisable widgets; period comparison; driver scorecards; maintenance forecasting; fuel analytics |
| P3 | Proof of delivery; compliance and document tracker; scheduled reports and export; units and localisation; audit trail |
