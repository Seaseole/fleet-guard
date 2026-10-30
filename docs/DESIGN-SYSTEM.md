# Fleet Guard — Design System

The visual and interaction contract for every screen. Token values here are the source of truth; they compile to CSS custom properties through Tailwind v4 `@theme` in `resources/css/app.css`. If a screen needs a value that is not a token, that is a design-system gap to fix here, not a one-off class in a component.

**Component strategy (decided 2026-10-26):** `livewire/flux` has been **uninstalled**. The starter kit's auth and settings screens were re-authored on our own Tailwind v4 primitives, so **`resources/views/components/ui/*` is the single component system of record for every surface** — no Flux, no mixing, no licence dependency. Consequence: we own these primitives, their focus states, their ARIA and their keyboard behaviour. Adding a component means adding it here and in `DESIGN.md` §20.2's inventory, not pulling in a library. (`DESIGN.md` Q6 and R10 are closed by removal rather than by licence review.)

---

## 1. Principles

1. **Action first.** The screen answers "what needs me now" before it answers "what happened". Alerts, overdue items and pending approvals outrank charts.
2. **Numbers are the interface.** This is a ledger and a dispatch tool. Figures get tabular numerals, right alignment, units, and a drill-down target. A number you cannot click into is decoration.
3. **Restraint over density.** One accent, warm neutrals, generous whitespace, minimal chrome. Weight and colour are used to mean things — never to decorate.
4. **Every action acknowledges.** Loading state, success confirmation, inline errors, confirmation for destructive work, undo where it is cheap.
5. **State is always legible without colour.** Every status badge pairs a colour with a shape/icon and a word — 8 % of drivers are colour-blind and a red dot on a maintenance list is not a decision.
6. **Permission shapes the page, not the truth.** A dispatcher and the Owner see the same layout with different fields, never two different apps.

---

## 2. Colour

### 2.1 Neutrals (warm, low-chroma — avoids the cold grey default)

| Token | Light | Dark | Use |
|---|---|---|---|
| `--fg-canvas` | `#F7F7F4` | `#0D0F0E` | Page background |
| `--fg-surface` | `#FFFFFF` | `#151816` | Cards, sheets, modals |
| `--fg-surface-raised` | `#FFFFFF` + shadow-2 | `#1B1F1D` | Popovers, sticky table headers |
| `--fg-surface-sunk` | `#F0F1EC` | `#101312` | Filter bars, code/ID blocks |
| `--fg-ink` | `#121413` | `#E9EBE7` | Primary text |
| `--fg-ink-secondary` | `#4C524E` | `#A9B1AC` | Secondary text |
| `--fg-ink-muted` | `#6B726D` | `#8B938E` | Labels, meta — **AA-checked at 4.60:1 / 4.94:1 on light surfaces and 6.10:1 / 5.67:1 on dark surfaces** |
| `--fg-line` | `#E2E4DE` | `#2A2F2C` | Hairlines, table rules |
| `--fg-line-strong` | `#C9CCC4` | `#3A403C` | Input borders, dividers that carry meaning |

### 2.2 Accent — "corridor teal", used once per view

| Token | Light | Dark | Notes |
|---|---|---|---|
| `--fg-accent` | `#0F766E` | `#2DBBAE` | Primary buttons, active nav, focus ring, links |
| `--fg-accent-hover` | `#0B5F59` | `#45D0C2` | |
| `--fg-accent-soft` | `#E2F1EF` | `#102A28` | Selected row background, active chip |
| `--fg-on-accent` | `#FFFFFF` | `#04211E` | Text on accent — 5.47:1 light, 7.11:1 dark |

Teal, not indigo/violet, and **one accent** — if every action is highlighted none is. Anything that is not the primary action on a screen is a neutral button.

### 2.3 Semantic colours (state, never mood)

| Token | Light | Dark | Meaning |
|---|---|---|---|
| `--fg-success` | `#067647` | `#4CC38A` | Delivered, settled, balanced, stock OK |
| `--fg-warning` | `#B54708` | `#F79009` | Due soon, variance, expiring in ≤30 days |
| `--fg-danger` | `#B42318` | `#F97066` | Overdue, expired, out of balance, blocked, overloaded |
| `--fg-info` | `#175CD3` | `#7CA9F9` | In progress, neutral notices |
| `--fg-neutral-badge` | `#5A615C` | `#98A29B` | Draft, archived, unknown |

Each has a `-soft` background variant (`#FFFAEB`, `#FFF1F0`, `#ECFDF3`, `#EFF6FF` light / desaturated deep variants dark). Solid danger controls use `--fg-on-danger` — `#FFFFFF` on the light danger (6.57:1) and `#0D0F0E` on the dark danger (6.90:1) — so the foreground is never assumed to be white in both themes.

### 2.4 Data-visualisation ramp

Sequential (single-hue teal, 5 steps) for magnitude; divergent (`danger → neutral → success`) for variance and profit/loss; categorical max 4 series, distinguished **by line style and label placement as well as colour**. Charts get no fill gradients and no 3D.

---

## 3. Typography

| Role | Font | Size / line-height | Weight | Where |
|---|---|---|---|---|
| Display | `IBM Plex Sans` (self-hosted, woff2) | 30 / 34, `letter-spacing: -0.015em` | 600 | Page titles, dashboard hero figures |
| Title | IBM Plex Sans | 20 / 26, -0.011em | 600 | Card and section headers |
| Body | IBM Plex Sans | 14 / 20 | 400 | Everything readable |
| Body small | IBM Plex Sans | 13 / 18 | 400 | Table cells, dense lists |
| Label | IBM Plex Sans | 12 / 16, `+0.02em` | 500, `text-case: none` | Field labels, table headers — **not** uppercase micro-type |
| Numeric | `IBM Plex Mono`, `font-variant-numeric: tabular-nums` | 13–14 / 18 | 500 | Money, odometer, litres, tonnes, hours |
| Identifier | IBM Plex Mono | 12 / 16 | 400 | Registration plates, ULIDs, document numbers, invoice refs |

Rationale for Plex over Inter: tabular monospace numerals are the backbone of a ledger app, one family pair covers UI + figures + machine identifiers, and it is free, self-hostable and not the default AI pick. Sizes step 12→13→14→20→30 with no intermediate values. `font-display: swap`, subset latin + latin-ext.

---

## 4. Spacing, radii, elevation, motion

**Spacing** (4 px base): `2, 4, 8, 12, 16, 24, 32, 48, 64`. Card padding 20; section gap 24; page gutter 24 (16 on phone); form field vertical gap 16, group gap 24; table row height 40 (comfortable) / 32 (dense, the default on fleet and ledger grids).

**Radii:** `--r-sm 4` inputs, chips · `--r-md 8` cards, menus, buttons · `--r-lg 14` modals, sheets · `--r-full` only for status dots and avatars. No blanket pill geometry.

**Elevation** (never more than 3 layers in one view):

```
--sh-1  0 1px 1px rgba(17,20,18,.04), 0 1px 2px rgba(17,20,18,.06)   /* cards, sticky headers */
--sh-2  0 2px 4px rgba(17,20,18,.06), 0 8px 16px rgba(17,20,18,.08)  /* popovers, dropdowns */
--sh-3  0 12px 32px rgba(17,20,18,.14), 0 4px 8px rgba(17,20,18,.08) /* modals, command palette */
```
Dark mode uses the same structure with `rgba(0,0,0,.5)` and adds a 1px `--fg-line` rim, because shadows disappear on near-black.

**Motion:** durations `--t-fast 120ms` (hover, focus) · `--t-base 180ms` (panels, drawers) · `--t-slow 240ms` (modals, page transitions). Easing `cubic-bezier(.2,0,.1,1)` standard, `cubic-bezier(.2,.8,.2,1)` emphasis. Only `transform` and `opacity`, hardware-accelerated, on the compositor. `wire:navigate` transitions at 120 ms opacity only — never a slide on a data app. **`prefers-reduced-motion: reduce` disables all transitions and the skeleton shimmer.**

---

## 5. Component inventory

Each component lives at `resources/views/components/ui/{name}.blade.php` with a documented prop set and every state below implemented — no screen free-styling it.

| Component | Required states | Rules |
|---|---|---|
| `button` | default, hover, active, focus-visible, loading, disabled, destructive, as-link | Sizes `sm/md/lg`. `sm` is icon-only with a tooltip. Destructive uses `--fg-danger` as text/border, filled only on the confirm step. Minimum 32 px tall (44 px on kiosk). Never two `primary` buttons in one view — and **a button that states no variant follows its job, not a default style**: `type="submit"` is the affirmative action of its form so it is filled, everything else is quiet. An unstated variant was `primary` for every button until 2026-10-30, which is how a page with three forms got three filled actions; the resolution lives in `resources/views/components/ui/button.blade.php` and is enforced twice — `scripts/lint-ui.php` (U1, per source container) and the `assertOneFilledAction` count in `scripts/render-operations.php`, which is the only one that can see a real state's screen. |
| `input` / `textarea` / `select` | rest, hover, focus, filled, error, disabled, prefix/suffix | 100 % width in forms, label always above and associated via `for`, helper text slot, error replaces helper in `--fg-danger` with an icon. Money inputs right-align monospace and show the currency code as a suffix; a foreign-currency field shows the BWP equivalent beneath as read-only derived text. |
| `date-field` / `date-range` | as input, plus preset menu | Presets: today, 7d, month-to-date, last month, quarter, YTD, custom. The range drives every report and the dashboard. |
| `table` | rest, row hover, selected, loading, empty, sorted, sticky header, dense/comfortable | Server-side sort + pagination always. Header carries the column's metric definition in a tooltip. Numeric columns right-aligned monospace. Row click navigates (`wire:navigate`); a keyboard-focusable "open" affordance always exists. Bulk selection bar replaces the top bar when rows are selected. |
| `filter-bar` | default, active-count, saved views | Above the table, never inside a drawer on desktop; shows a chip per active filter with a clear-×, plus "clear all"; state lives in the query string so a filtered list is shareable and survives `wire:navigate` back-navigation. |
| `badge` / `status-pill` | one per semantic colour + neutral | Icon + word always. Status colour comes from the state machine enum, never from an ad-hoc class. |
| `card` / `metric-card` | default, actionable, loading, empty, no-permission | Metric card = label, figure, delta vs comparison period, sparkline (optional), drill-down link. **No-permission state is a real state**: the card renders as "not available to your role", not as zero and not as hidden-and-unexplained. |
| `modal` / `drawer` | open, closing, busy | Focus trapped, restore focus on close, Escape closes unless it would lose data (then confirm). Drawers for detail-with-form (trip, work order) on ≥1280 px; full page below that. |
| `confirm-dialog` | default, destructive-with-typing | Destructive confirmations require the reason field (§10.1 `DESIGN.md`) and name the consequence ("This reverses invoice INV-2026-000118 and posts 2 journal lines"). |
| `toast` | success, warning, danger, info, with-action | Bottom-right, `aria-live="polite"`, 5 s default / 10 s when it has an **Undo** action, never more than 3 stacked. Every mutating action emits one; nothing mutates silently. |
| `empty-state` | first-run, filtered-no-results, no-permission | Distinct copy for each: "nothing here yet" vs "no trips match these filters" (with a clear-filters action) vs "your role cannot view fuel costs". |
| `skeleton` | list, table rows, card, chart | Matches the real layout's geometry so nothing jumps on arrival. Shimmer only when motion is allowed. |
| `error-state` | field, form, page, offline | A 500 or failed Livewire request shows a retry inline; ledger posting failures link to the posting-failures screen. |
| `tabs` / `stepper` | default, completed, error | Tabs persist in the URL. Steppers only for genuinely linear flows (billing run, period close, CSV import). |
| `command-palette` | open, searching, no-results | §8. |
| `map` | live, trip-playback, geofence-edit | Leaflet + OSM (§9). |
| `signature-pad` | draw, clear, confirm | Kiosk; exports PNG to the private document disk. |
| `file-drop` | idle, hover, uploading, scanning, done, rejected | Shows the per-type size limit and rejects by content-MIME, not extension. |
| `pagination` | page-based, cursor | Cursor for GPS positions and audit trails (append-only tables). |
| `avatar` / `user-menu` | initial-based, status dot | No generated illustrations; initials on `--fg-surface-sunk`. |
| `progress` / `export-progress` | determinate, indeterminate | Long exports report job state with a download link when ready; the user can navigate away. |
| `tooltip` / `help` | hover, focus | Focusable so metric definitions are keyboard-reachable. |

---

## 6. Page patterns

**List.** Title + primary action (right) → filter bar → table → pagination. One primary action only. Bulk actions appear in the selection bar. Default sort is most-recent-meaningful-date `desc`; the sorted column is remembered per user per table.

**Filter.** Filters are always visible on ≥1024 px and never more than six primary fields; the rest live behind "more filters" as an explicitly labelled expansion. Active filters chip above the table.

**Detail.** Header with identity (plate / number / status badge / last-updated) → 2–4 KPI figures → tabbed body (Timeline, Documents, Costs, Assignments…) → right-hand action rail on desktop, sticky action bar on phone. The **vehicle and trip timelines are the spine of the app**: one vertical stream of events (odometer, fuel, work orders, assignments, documents, postings) with type icons and actor names, filterable by type.

**Create / edit.** Single column, 640 px max, grouped fieldsets with section titles, inline validation on blur, save-and-close plus save-and-add-another where bulk entry is real (fuel, parts), unsaved-changes guard, and a sticky footer on phones. Money and rate fields show the derived consequence immediately (litres × price → cost; foreign × rate → BWP).

**Approval / sensitive.** Any action that changes a posted number, closes a period, voids an issued invoice or edits bank details triggers re-auth (`/user/confirm-password`) and a named reason, and produces an audit row visible to the Owner.

**Kiosk layout.** One column, 44 px targets, 16 px gutters, three fields per screen maximum, big numeric keypad-friendly inputs, no hover-only affordances, offline retry banner, and a "you are recording for trip T-07 · B1234ABC" identity strip so a shared phone always knows whose trip it is.

---

## 7. Application shell

- **Sidebar:** 256 px expanded / 72 px icon-rail collapsed (state persisted), fixed on desktop, off-canvas drawer below 1024 px. Sections from one config array, permission-filtered server-side — the menu you see *is* what you can do. Active item gets a 2 px `--fg-accent` left bar plus `--fg-accent-soft`, never a filled block. Counts render as red-dot alerts on sections needing action (dispatch conflicts, overdue service, posting failures) — never as raw unread counts.
- **Topbar:** 56 px. Global search (opens the command palette), notification bell with an unread dot and a dropdown of `Alert` rows, quick-create (permission-filtered), theme toggle (system default, light, dark — persisted, no flash), user menu (settings, sessions, MFA, sign out).
- **Content:** max-width 1400 px for forms and detail pages, full width for tables; a breadcrumb sits above page titles on anything deeper than two levels.
- **Theme:** `data-theme` on `<html>`; tokens flip at the custom-property layer so no component contains a theme conditional. Colour scheme declared with `color-scheme: light dark` so native form controls and scrollbars follow.

---

## 8. Command palette (Ctrl / ⌘ + K)

Search across: navigation, entities (trip no, plate, VIN, invoice number, customer, driver, work order, document), and permitted actions ("new trip", "close period", "run fuel variance"). Grouped results with the entity type and its state visible; recents when the query is empty; ↑/↓ to move, ⏎ to open, ⌘⏎ to open in a new tab, Esc to dismiss. Debounced at 150 ms, server-side over the search index, **permission-filtered before results leave the server** — the palette is an authorised read, not a side door. Focus is trapped and restored; it is fully keyboard-operable.

---

## 9. Map (Telematics)

Leaflet with OpenStreetMap raster tiles, no paid key. Truck markers are rotated vehicle glyphs (heading-aware), colour by trip state, with a number badge for clustered fleets. Planned route as a dashed neutral line, actual as a solid accent line, deviations in `--fg-danger` with a distance annotation; geofences are translucent circles with a dashed border; borders and depots get distinct glyphs so a cluster at Tuli reads as a queue rather than a bug. Dark mode applies a CSS filter to the raster tiles (`brightness(.85) invert(.92) hue-rotate(180deg) saturate(.7) contrast(.9)`) rather than switching tile providers, so the palette stays coherent. Playback scrubber at the bottom; positions refresh with `wire:poll`, so the marker transition is a 240 ms translate that never animates on reduced motion.

---

## 10. Accessibility and print

- **WCAG AA as a floor:** body text ≥4.5:1, large/monospace figures ≥3:1, non-text (icons, focus rings, chart lines) ≥3:1. Every token pair in §2 is checked in both themes; a new colour requires the ratio written next to it.
- Focus is always visible: 2 px `--fg-accent` ring with a 2 px offset; no `outline: none` anywhere in the codebase.
- Full keyboard operation for tables (roving row focus), modals (trap + restore), palette, filters, date ranges, dispatch-board drag-and-drop **with a keyboard alternative** (select vehicle → assign).
- Live regions: toasts `aria-live="polite"`, background job progress announced on completion, skeleton wrappers marked `aria-busy`.
- Table semantics: `th scope="col"`, `aria-sort`, caption or visually-hidden description, no clickable cells that fake buttons.
- Targets ≥44 px on touch and kiosk; forms have `autocomplete`, `inputmode="decimal"` for numerics, and no placeholder-as-label.
- Motion respects `prefers-reduced-motion`; colour is never the only carrier of state.
- **Print** (`resources/views/print/*`): invoices, credit notes, statements, delivery notes, work orders, weighbridge tickets. A plain CSS subset only — no Tailwind arbitrary values, no webfonts that require the network, no flex-heavy layout, `@page { margin: 12mm }`, A4, table-based totals — because `spatie/laravel-pdf` renders through the **DOMPDF driver**, which needs no Chrome binary on Cloud or on the dev box. What you print is what you get; the print stylesheet is verified against that driver in Phase 4, not hoped for.

---

## 11. Numerals, money and units

| Kind | Format | Example |
|---|---|---|
| Money (base) | `P 1 234,56` — BWP symbol, space thousands, comma decimal, 2 dp, tabular | `P 128 400,00` |
| Money (foreign) | original amount + code, base equivalent beneath in muted secondary text | `R 45 200,00 ZAR` / `P 3 372,14` |
| Negative money | leading minus, `--fg-danger`, never red-only (the sign is the signal) | `−P 1 240,00` |
| Distance | 1 dp km, thousands spaced | `1 842,3 km` |
| Volume | 1 dp litres, 2 dp for pump precision | `412,5 l` |
| Mass | 2 dp tonnes | `33,20 t` |
| Hours | 1 dp | `1 284,0 h` |
| Rates | per-litre price 2 dp; FX 4 dp on screen (8 dp stored) | `P 21,84/l` · `1 ZAR = P 0,0745` |
| Efficiency | `l/100 km` 1 dp with expected value alongside | `32,4 / 30,0` |
| Dates | `06 Mar 2026`, `14:20` in Africa/Gaborone; ISO in tooltips and CSV | always the same formatter |
| Identifiers | mono, no reformatting | `B1234ABC`, `INV-2026-000118`, `T-07` |

One `Formatter` class produces all of this (§14.8 in `DESIGN.md`). A component never formats inline.
