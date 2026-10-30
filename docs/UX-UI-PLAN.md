# Fleet Guard — application-wide UI/UX plan

Written 2026-10-30, after Phase 2 closed (slices 2a–2g). Source of truth for *what the UI promises* stays
[`DESIGN-SYSTEM.md`](DESIGN-SYSTEM.md) and [`DESIGN.md`](../DESIGN.md); this document is the **gap between
those promises and the code that is on disk**, and the order in which to close it.

Method: the `ui-ux-pro-max` rule set (priority ladder: accessibility → touch/interaction → performance →
style → layout → type/color → motion → forms → navigation → data) applied to three evidence passes over
the primitives (`resources/css/app.css`, `resources/views/components/ui/*`), the ~30 page components, and
the two non-desktop surfaces (driver kiosk, print). The skill's `search.py` could not be run — Python is
not installed on this box (only the Microsoft Store stub) — so the rule set was taken from the skill's
inlined Quick Reference rather than the CSV search. Every finding below carries a file reference, and
**every finding was re-checked by hand**: the audit first claimed that domain refusals were written into
error bags nobody rendered, and that was wrong — all 28 `addError()` call sites have a render path
(`@error('bag')` or `x-ui.input` reading `$errors->first($name)` itself, [input.blade.php:17](../resources/views/components/ui/input.blade.php#L17)).
That claim was dropped rather than planned around.

---

## 0. Where the design system already holds

Say it once so the plan is not read as a redesign. The token layer is real and disciplined, and the
product-level rules are mostly kept:

- One `@theme` token set, light and dark, with documented contrast ratios per value
  ([app.css:19-49](../resources/css/app.css#L19), `:70-104` for dark). **No component contains a raw hex.**
- Radii, shadows, motion durations and easings are tokens (`--radius-fg-*`, `--shadow-fg-*`,
  `--t-fast/--t-base/--t-slow`, `--ease-standard/--ease-emphasis`).
- A global `:focus-visible` outline exists on every interactive element
  ([app.css:120-122](../resources/css/app.css#L120)); `prefers-reduced-motion` kills the shimmer (`:140-179`).
- 59 vendored Heroicons, name-validated (an unknown name throws), `aria-hidden`, **zero emoji used as
  icons** ([icon.blade.php:11-307](../resources/views/components/ui/icon.blade.php#L11)).
- Status is never colour alone: `x-ui.badge` forces an icon per tone
  ([badge.blade.php:2-3, 12-21](../resources/views/components/ui/badge.blade.php#L2)), and all 77 badge uses
  carry text.
- Tabular figures exist as `.fg-numeric` / `.fg-identifier` (mono + `tabular-nums`)
  ([app.css:125-133](../resources/css/app.css#L125)).
- Forms: `x-ui.input` gives label, `aria-invalid`, `aria-describedby`, `role="alert"` error, and the numeric
  right-alignment. The toast group is `aria-live="polite"`.
- One `h1` per app screen across 24 screens; a skip-link and `main#main` landmark exist
  ([sidebar.blade.php:50-53](../resources/views/layouts/app/sidebar.blade.php#L50)); `wire:navigate` on nav.
- Tenancy/permission refusal is enforced server-side and asserted by the render sweeps (`gps.view` gets 403,
  `crossborder.manage` gets no button, margins never reach a dispatcher's HTML).

So the work below is **not** "make it look professional" — it is making the shipped UI match what
`DESIGN-SYSTEM.md` already commits to, and fixing the few places where a rule is being broken *by default*
rather than by choice.

---

## 1. P0 — broken promises, wrong affordances, or a control that misleads

Ordered by what can cost the company money or a user's trust, not by effort.

### P0-1 · Every button is primary until told otherwise

`x-ui.button` defaults `variant => 'primary'` ([button.blade.php:3](../resources/views/components/ui/button.blade.php#L3)),
and the contract says **"Never two `primary` buttons in one view"**
([DESIGN-SYSTEM.md:106](DESIGN-SYSTEM.md)). Across the 38 page components there are **173 `x-ui.button`
uses, 36 of which pass no `variant` at all** — so they render primary by omission. Worst offenders:

| screen | `x-ui.button` uses | with an explicit `variant=` | silently primary |
|---|---|---|---|
| `operations/⚡detail` | 32 | 22 | **10** |
| `operations/⚡jobs` | 13 | 9 | 4 |
| `fleet/⚡index` | 12 | 10 | 2 |
| `fleet/⚡vehicle` | 6 | 3 | 3 |
| `operations/⚡routes`, `alerts/⚡alerts`, `operations/⚡dispatch`, `people/⚡index` | 8–9 each | 6–7 each | 2 each |

The visible consequence is **not** "ten teal buttons at once" — I checked, and most of those ten are the single
affirmative submit of their own form, in branches that are mutually exclusive by trip state
(`canDepart()` and `canComplete()` cannot both be true, so *Record the departure* and *Complete the trip* never
render together), and the page-level *Put on hold* / *Cancel or abort* / *Close* / *Re-open* row is explicitly
`variant="ghost"` ([detail:1335-1347](../resources/views/pages/operations/⚡detail.blade.php#L1335)) — correctly
subordinate. The alert register is likewise correct: its two default-primary buttons are the submits of two
different dialogs (`:422`, `:452`), each with a ghost *Cancel* beside it, and its filter chips are
`variant="ghost"` with `aria-pressed` ([alerts:285](../resources/views/pages/alerts/⚡alerts.blade.php#L285)).

What the omission does cause is **peer decisions carrying weights nobody chose**. Two verified instances on the
trip page: in the "Say why" dialog *Hold* is default-primary while *Stop the trip* beside it was hand-set to
outline ([detail:1908-1912](../resources/views/pages/operations/⚡detail.blade.php#L1908)) — a reversible action
and a terminal one, asymmetrically dressed, and neither dressed as the terminal decision §5 asks `danger` for;
and the POD row pairs *Accept the delivery* (default primary) with *Send back* (explicit outline)
([detail:1845-1852](../resources/views/pages/operations/⚡detail.blade.php#L1845)). The third instance is
page-level duplication: `Register a gate` ([crossborder:199](../resources/views/pages/crossborder/⚡index.blade.php#L199))
and that dialog's submit (`:343`) both default to primary, so the view does render two filled buttons at once.

So the defect is that **`primary` is an accident of the component's default rather than a decision someone
made** — which is why it reads as inconsistent in a handful of containers rather than wrong in 36.

**Landed 2026-10-30.** The default is now `null` and resolves by job: `type="submit"` → `primary`, anything
else → `outline`. Nine demotions to `outline` resolved the containers where two submits genuinely share a
screen (fleet register and people register status panels, the vehicle page's status and assignment forms, the
rate form on the corridor page, the password form on settings/security); the trip page's departure and
completion forms each declare `{{-- ui-scope: … (reason) --}}`, because they are disjoint §10.2 states and a
textual pass cannot know that. Enforced twice, deliberately: `scripts/lint-ui.php` rule U1 on the source, and
`assertOneFilledAction()` in `scripts/render-operations.php`, which counts what a real render of a real state
puts on screen — the check that found two screens (`/jobs`, `/trips/{trip}`) the static pass was about to
wave through, and in doing so caught its own first attempt counting the app logo as a button.

**Change:** flip the default to `outline`, then decide each container: the one affirmative action of a form or
dialog gets `variant="primary"`; terminal decisions get `danger`/`danger-solid`; nothing else is filled.
~40 call sites, most mechanical. The lint in §5 measures it **per container** (a form, a dialog, or the page
around them), not per file — one page legitimately holds several forms, each with its own affirmative action.

**Accept:** `scripts/lint-ui.php` rule (see §5) — at most one `variant="primary"` per page component; the
sweeps assert that a refused action still shows its refusal.

### P0-2 · Two screens announce tabs they do not implement

`fleet/⚡index:707-716` and `people/⚡index:494-499` declare `role="tablist"` and `role="tab"` with
`aria-selected`, but there is **no `role="tabpanel"`, no `aria-controls`, no matching `id`, and no arrow-key
handling**. For a screen-reader user that is worse than plain buttons: the AT announces a widget whose keyboard
contract (Left/Right to move between tabs, tab panel labelled by its tab) the page then fails to honour.

**Change:** either complete the pattern (panel + `aria-controls` + `id` + `@keydown.arrow-*` on the list, and
`tabindex="-1"` on inactive tabs) or demote both to a segmented `x-ui.radio` group — which already exists and
already carries `role="radiogroup"` ([radio/group.blade.php:11](../resources/views/components/ui/radio/group.blade.php#L11)).
Recommendation: demote. The "tab" here is a register switch that changes a URL parameter
(`#[Url] public string $tab`), which is exactly what a radiogroup or a link set models, and it is fewer moving
parts to keep honest.

**Landed 2026-10-30.** Demoted — to links rather than the radiogroup, because these are URL-bound views: a
`<nav aria-label>` whose items are `<a href="?tab=…">` with `aria-current="true"` on the active one, carrying
`search` and `status` across so switching register keeps the filter. A radiogroup would have had to keep that
in the component and lose the shareable URL the property already gave the page. `setTab()` went with the
buttons, and `mount()` now normalises an unknown `?tab=`: the URL was already the source of truth, so a
hand-typed `?tab=anything` used to fall between the `if` branches and render a heading with no table at all —
a live bug the tab markup was hiding. Rule **U4** in `scripts/lint-ui.php` refuses `role="tab"` without a
`tabpanel` and `aria-controls`, so the pattern cannot be half-claimed again.

### P0-3 · The driver's signature is drawn in the wrong place on a phone

`kiosk/⚡capture` sizes the canvas at `width="600" height="200"` but displays it `class="w-full"`
([capture.blade.php:277-289](../resources/views/pages/kiosk/⚡capture.blade.php#L277)) and plots strokes from
`offsetX/offsetY`, which are **CSS pixels**. On a 390 px phone the element is ~2.5× narrower than its bitmap,
so the pen lands up to ~60 % away from the finger and the signature the Owner sees is not the one the receiver
made. This is the POD — the artefact that settles a disputed delivery and the reason
[DESIGN.md §9.6](../DESIGN.md) exists.

**Change:** map through `getBoundingClientRect()` (`x = (e.clientX - rect.left) * (canvas.width / rect.width)`)
and set the bitmap from `rect.width * devicePixelRatio` on mount and on resize. Add `hitSlop`-style padding so
the canvas is at least 44 px of tappable height, and keep the existing `touch-none`.

### P0-4 · The kiosk misses its own contract on every number the contract gives

[DESIGN-SYSTEM.md:143](DESIGN-SYSTEM.md) promises the driver surface **44 px targets and ≤3 fields per screen**.
Measured on the only kiosk screen that exists:

- submit is the default `md` size = `h-10` = **40 px** ([button.blade.php:26](../resources/views/components/ui/button.blade.php#L26));
- inputs are `px-3 py-2 text-sm` ≈ 36 px;
- **6–7 controls on one screen** — receiver name, tonnes, exceptions textarea, "nothing wrong" checkbox, photo,
  signature, PIN ([capture.blade.php:232-302](../resources/views/pages/kiosk/⚡capture.blade.php#L232));
- every label, hint and error is `text-xs` = 12 px, including the ones the driver reads in daylight at
  Beitbridge ([input.blade.php:27,78,83](../resources/views/components/ui/input.blade.php#L27));
- the identity strip says "Trip … · N loads" and never shows the **registration**, which is the thing a driver
  checks before signing for a load;
- an expired or revoked token is `abort(404)` with no copy
  ([EnsureKioskToken.php:44-49](../app/Domain/Operations/Kiosk/EnsureKioskToken.php#L44)) — `closedBecause()`
  messages exist server-side but nothing driver-facing prints them;
- the 5/min rate limit has no 429 message, so a slow signal + a retry looks like a broken page;
- no offline/degraded message even though `client_uuid` retry-dedupe *is* implemented and verified
  ([verify-kiosk.php:534-572](../scripts/verify-kiosk.php#L534)) — the safety net exists, the reassurance does not;
- `viewport-fit=cover` and `env(safe-area-inset-*)` are absent, while `min-h-dvh` is present.

**Change:** treat the kiosk as its own design target, not a squeezed desktop page. Split capture into
**three steps** (who/what → photo+signature → PIN/review) with a step indicator, add a kiosk size scale
(`h-12`/16 px labels via a `kiosk` variant or a `--fg-kiosk-*` token set), show the registration and the load
count in the header, print the expiry reason, and surface "no signal? your drawing and these values are kept on
this phone until it posts" in the failure path. This is the only place in the app where the mobile rules in the
skill's checklist (safe areas, ≥44 pt, no precision taps, thumb-reachable primary action) actually bind.

### P0-5 · Nothing in the app can be sorted

`x-ui.table.th` implements `sortable`, `aria-sort` and a chevron
([th.blade.php:2-14](../resources/views/components/ui/table/th.blade.php#L2)) — and **zero** of the ~30 pages
pass it. Every register is a fixed `orderBy`. [DESIGN-SYSTEM.md:109](DESIGN-SYSTEM.md) says "Server-side sort +
pagination always", and §133 promises the sorted column remembered per user per table.

Concretely what a dispatcher cannot do: sort the dispatch board by departure time, sort the jobs register by
required-delivery date (the one column that turns a register into a worklist), sort the journal by amount, or
sort the crossing register by "what did it cost". This is the largest single missing affordance on the daily
surfaces.

**Change:** one `#[Url] public string $sort = 'created_at'` + `#[Url] public string $direction = 'desc'` per
register, validated against a per-screen allowlist (never a client-supplied column name), wired to
`th(sortable, direction)`, remembered per user per table in `user_settings`. Do it once as a pattern — the
ledger screen is the best first target because its amounts are the columns people actually re-sort — then
retrofit the five daily registers (jobs, dispatch, documents, alerts, journal).

### P0-6 · The command palette is advertised and does not exist

`config/navigation.php:4` says the sidebar *and the command palette* both read that file;
[DESIGN-SYSTEM.md:158](DESIGN-SYSTEM.md) specifies a palette;
[search-button.blade.php:4](../resources/views/components/search-button.blade.php#L4) dispatches
`open-palette` — and no component listens, no Ctrl/⌘+K binding exists anywhere, and the button is not even
rendered in either layout. So there is a dead control in the tree and a documented feature that is not
deliverable. Either build it (it is genuinely useful: 24 screens, deep-linkable by `#[Url]` filters already) or
delete the stub and correct both docs. Leaving it is the worst option: it is a promise the code contradicts.

---

## 2. P1 — the register experience is half-built

### P1-1 · Loading and pending feedback exist on 6 screens out of 38

`wire:loading` appears in **6 of 38 page files** — `alerts/⚡alerts`, `documents/⚡documents` (9 sites),
`documents/⚡register`, `security/⚡queues`, `security/⚡users` and `settings/two-factor/⚡recovery-codes`.
`x-ui.skeleton` is **used nowhere**, though it is built and carries `role="status" aria-busy="true"`.

The screens with no feedback at all are the ones whose writes are slowest, because they post to the ledger in a
transaction: trip cost capture, border charge capture, journal posting, dispatch, cutover validate/commit, POD
capture. The rule being broken is `progressive-loading` and `loading-buttons` (submit feedback within ~100 ms,
skeletons over 300 ms) — and in a ledger app, an unanswered "Save" is a double-posted cost. `RecordTripCost` is
idempotent only when the caller passes a `sourceType`/`sourceId`, so a double click on a manual cost really does
post twice. **This is the one item on this list with a financial consequence.**

**Change:** (a) every `<form wire:submit>` gets `wire:loading.disabled` on its submit and an inline state swap;
(b) the five slowest screens get a skeleton row via the existing `x-ui.skeleton`; (c) manual cost entry gains a
client-generated idempotency key, the same trick the kiosk already uses for PODs, so a double click cannot post
twice. That last part is a domain change, not a CSS change, and belongs in this plan precisely because the
missing UI state is what makes it reachable.

### P1-2 · Empty states are inconsistent on the busiest page

`x-ui.empty-state` is used in 24 places — and **none of the five tables on `operations/⚡detail`** (loads,
crossings, costs, chargeables, PODs) renders one, nor `crossborder`'s gate list (`:287` bare `x-ui.text`), nor
`security/queues:93` (bare `colspan` text), nor `fleet/vehicle:559` (assignment history has no empty branch at
all). The trip page is where a dispatcher lives; five blank table bodies with no explanation reads as
"the data is gone", not "nothing yet".

**Change:** empty state per table with the action that would fill it ("no charges captured at a gate yet — plan
a crossing in the Border gates section"), which is also the navigation aid the page lacks (see P2-2).

### P1-3 · Cursor pagination is specified for the two tables that need it most and implemented nowhere

`grep simplePaginate|cursorPaginate` across `resources` and `app` returns **0**, while
[DESIGN-SYSTEM.md:124](DESIGN-SYSTEM.md) requires cursor pagination for GPS positions and audit trails, and
[DESIGN.md §14.3](../DESIGN.md) repeats it. Phase 2g's `gps_positions` is 60 k rows/day by the design's own
arithmetic; the live map reads the one-row-per-truck projection (correct, fast) and caps a trip's track at 200
fixes ([LiveMap.php](../app/Domain/Telematics/Queries/LiveMap.php)) — but there is no way to page past the cap,
and Phase 7's playback and Phase 9's audit browser will hit this immediately.

**Change:** adopt `cursorPaginate()` for `gps_positions`, `audits` and `document_versions` reads as those
screens arrive; the 200-fix cap becomes a cursor with a "show earlier" action. Do not convert the 13
`paginate()` registers — offset pagination is honest for a few hundred rows and `links()` already works.

### P1-4 · 64 hand-rolled `<select>` elements, no select primitive

There is no `x-ui.select`; 64 raw `<select class="w-full rounded-fg-sm border border-fg-line-strong …">` copies
across 18 files (the trip page alone has 11). Each copy re-declines the focus, error and disabled treatment that
`x-ui.input` owns, and any token change (say, the line colour) needs 64 edits.

**Change:** add `x-ui.select` mirroring `x-ui.input`'s props (`label`, `name`, `error`, `hint`, `options`), then
convert file by file, asserting markup in the sweeps as each screen is touched. Cheap, mechanical, and it is what
makes P0-1 and P0-5 fixable without drift.

**Landed 2026-10-30.** `resources/views/components/ui/select.blade.php` takes the same props as `x-ui.input`, plus
`options`/`placeholder` for the choice list, and keeps the same error contract: `aria-invalid` on the control and
the message in the `aria-describedby` slot the hint would otherwise own, so a failed select explains itself instead
of only turning a different colour. All 64 raw blocks are gone (18 files; the trip page alone had 11), each losing
its copied class string and gaining the hover, focus and disabled treatment it never had — **none of the 64 carried
`aria-invalid`**, which is the measurable version of "a rejected choice looked exactly like a valid one". `name`
is derived from the `wire:model` property where the original markup had only an `id`, and a one-off walk of the
pages confirmed all 67 `<label for>` targets still resolve. That shared-`$errors` behaviour is the half that cost
something: 22 hand-written `@error('<same field>')` blocks were printing the sentence a second time under a control
that now reports it properly, so they went, and `render-operations` counts the rendered message (`once is the field,
twice is the page repeating it`) so the dedup cannot rot back. Rule **U2** in `scripts/lint-ui.php` now fails the
build on a raw `<select>` in a page, so the primitive is the only way to get one. Proof: the four render sweeps
re-run green (a 64-site template change touches almost every screen), plus a throwaway probe that GETs every
parameterless route as the Owner — 29 screens rendered, 14 redirected (auth-flow pages bouncing an already-signed-in
seat, and the §9.1 re-auth gates), 0 responses at 403 or above 500.

---

## 3. P2 — layout, navigation and information structure

### P2-1 · The widest screens have no responsive behaviour at all

Breakpoint usage across the pages: **68 `sm:`, 7 `md:`, 12 `lg:`, 6 `xl:`, 0 `2xl:`**. Fifteen page files use no
responsive prefix at all — and most of those are the narrow single-column `auth/*` and `settings/*` forms where
that is correct rather than a gap. The ones that genuinely need it are `operations/⚡dispatch` (the board is the
widest screen in the app), `kiosk/⚡capture`, `documents/⚡register` and `security/⚡accept-invitation`.
Separately, every table is wrapped in
`overflow-x-auto` ([table.blade.php:3](../resources/views/components/ui/table.blade.php#L3)) and **35 cells are
pinned `whitespace-nowrap`** (25 through `x-ui.table.td`'s `nowrap` prop, 10 written literally), so wide
registers scroll sideways on a 1280 px laptop by default; `dispatch:476` adds its
own wrapper with a `sticky left-0` truck column — the only nested scroll region and the only sticky element in
the app. And `layouts/app/header.blade.php:17` hides the nav entirely below `lg:` with no hamburger
(that variant is not the default shell, but it is shipped).

**Change (small, not a redesign):** decide the app's real floor — this is a yard office on laptops, so
**1024 px is the contract, 768 px should degrade gracefully, and the phone case is the kiosk only**. Then: give
the dispatch board and the trip page `md:`/`xl:` column behaviour; on tables below `lg:` drop the least important
columns rather than letting `nowrap` widen them (a `hide_below` prop on `table.td` does this once, for all 31
tables); and make `overflow-x-auto` announce itself (edge fade + `tabindex="0"` role=region) so a scrollable
table is keyboard-reachable, which is an accessibility rule, not a visual one.

### P2-2 · No breadcrumbs, no anchor nav on 1 900-line pages

[DESIGN-SYSTEM.md:151](DESIGN-SYSTEM.md): breadcrumbs above any page deeper than two levels. **None exist
anywhere** (the only grep hit for "breadcrumb" is a comment about a GPS polyline). `operations/⚡detail` is
1 923 lines with nine `subheading` sections stacked
([detail.blade.php:1142,1184,1213,1286,1365,1547,1635,1738,1813](../resources/views/pages/operations/⚡detail.blade.php#L1142))
and no anchor or sticky navigation; `fleet/⚡vehicle` is the same shape. Going "back" is one plain link
(`detail:1126`).

**Change:** a `x-ui.breadcrumb` fed from the route's own metadata (extend `config/navigation.php` with parent
info — one place, both consumers, consistent with how the menu already works), plus a right-hand section index
on the two long detail pages that scroll-spies the existing `subheading`s. Sections should keep their order —
the current order is deliberate (facts → trail → loading → move it on → gates → money → billing → access → POD)
and reads like the sequence a trip happens in; the problem is navigation *within* it, not the order.

### P2-3 · Shared links reproduce lists but not the thing you were looking at

`#[Url]` is used well on 16 screens — filters, tabs, the week on the board, the batch on cutover — and page-reset
is handled correctly in every `updated()` hook. What cannot be shared: the journal's expanded entry (`openEntry`
is not `#[Url]`), any open dialog (jobs' quote/decision, fleet's editor, alerts' mute/resolve reason, documents'
versioning), `dispatch`'s selected trip, and `documents`' `page` (which hand-rolls `$this->page = 1` at
`:62-67` instead of `resetPage()`).

Why it matters in *this* app: the workflows are hand-offs. An Owner wants to see the exact batch the accountant
was refused; the Operations Manager wants the trip the dispatcher held. Also,
[documents:412](../resources/views/pages/documents/⚡documents.blade.php#L412) is the only paginator calling
`appends(request()->query())`, so on the other screens the second page silently drops the active filter — a
bug-shaped inconsistency, not a style choice.

**Change:** promote the two or three states per screen that carry meaning (open entry, selected trip, dialog
with its subject id) into `#[Url]`; make `x-ui.table`'s pagination append the query string once, centrally.

### P2-4 · Undo is half-built and never used

[toast/group.blade.php:31-34](../resources/views/components/ui/toast/group.blade.php#L31) can render an action,
but only as **`toast.action.href` — an anchor**, and no screen ever passes one. So "Undo" is not a matter of
passing a parameter: a real undo has to call back into the component, which means extending the toast contract
with a Livewire target (`wire:click` + method name) or accepting a link back to the screen instead of a
one-tap revert. Worth deciding deliberately, because the app already has many reversible-by-Action decisions
(hold/release, reopen, mute, deactivate, revoke) where undo is both the humane affordance and the shortest path
— and `undo-support` is an explicit rule. Note also that Resolve is terminal (`alerts_resolved_is_final`), so
that one must *not* get an Undo — the component work should not quietly imply otherwise.

Two successes are also silent: the 2FA enable flow dispatches an event but says nothing
([two-factor-setup-modal:82-105](../resources/views/components/settings/two-factor-setup-modal.blade.php#L82)),
and accepting an invitation uses `session()->flash` while everything else uses the toast — two feedback
mechanisms for one job.

---

## 4. P3 — data display, and the surfaces that are not screens

### P3-1 · Eleven of the app's 31 tables have no caption, and money is styled two different ways

`x-ui.table` renders a `sr-only` `<caption>` only when passed `:caption`; **20 of the 31 do, 11 don't** — all
five tables on `operations/⚡detail`, both on `crossborder/⚡index`, both on `telematics/⚡live`, plus `jobs` and
`queues`. (A caption is the accessible name of the table; without one a screen-reader user reaches a grid of
numbers and has to infer what they are.) Two more tables are raw `<table>` markup outside the component:
`dispatch:478` remembers its own `<caption class="sr-only">`, `journal:239`'s nested lines table does not.
Separately there are two competing numeric mechanisms: `x-ui.table.td`'s `numeric`/`mono` props (used by 10
screens, which produce `.fg-numeric`) versus raw `class="text-right font-mono text-xs"` (used by 11 more), so
the *same* Amount column reads differently on the ledger screen and the bank screen. Two headers are literally
`__(' ')` blank cells. Fix by convention: caption always, one numeric path, and let
`scripts/lint-ui.php` refuse a `x-ui.table` without a caption.

**Landed 2026-10-30.** All 11 caption-less `<x-ui.table>`s now name themselves, plus the journal nested table's
raw `<caption>`; the two raw tables stay raw because `x-ui.table` wraps in a bordered scroll box and a box inside
a table cell reads as a second register. The numeric path is `x-ui.table.td`'s props only — 69 cell tags rewritten
to `numeric` / `identifier` / `nowrap`, and 13 in-cell monospace spans and divs moved to the `.fg-identifier`
token. Two corrections to the evidence above, both measured after the work: the blank headers were **14, not two**
(each one heading the row-action column, now labelled `Actions`, which four registers already did), and the old
prop pair was worse than "two mechanisms" — `numeric` produced the alignment *without* `.fg-numeric`, so 24 cells
were right-aligned with proportional digits and did not line up on the decimal point. `numeric` now means the
token (monospace + tabular + right), `mono` is gone, and `identifier` is the left-aligned half the stylesheet
already defined and nothing used. Enforced by **U3** (caption) and **U5** (no `text-right`/`font-mono`/`whitespace-nowrap`
in a cell's class, for the component and for a raw `<td>`), both proven to fail by a probe page rather than trusted.
Because a word-prop that the component stops declaring renders as an HTML attribute and styles nothing — invisible
to any source pass — `render-operations` also asserts the track table's figures actually resolve to `fg-numeric`,
its caption is in the markup, and no prop leaked.

### P3-2 · The two `wire:poll` regions are silent for assistive tech

The live map refreshes positions every `poll_seconds`
([live.blade.php:176](../resources/views/pages/telematics/⚡live.blade.php#L176)) and the queue screen every
60 s; neither has `aria-live`/`aria-busy`, so a screen-reader user gets no indication the numbers moved. The
toast group already shows the pattern. Add `wire:poll.visible` (don't poll a hidden tab — a cheap performance
rule too) and an `aria-live="polite"` region that announces a change count, not every fix.

### P3-3 · Chart and data-viz rules are unexercised — and will all land at once

There are no charts yet (the dashboard is metric cards; the live map is one hand-computed SVG). Phase 8
reporting will introduce trend/comparison/proportion charts under
[DESIGN.md §11](../DESIGN.md) with Chart.js through `spatie/laravel-pdf`. Front-load the rules from the skill's
§10 so Phase 8 does not discover them: every chart ships with a **table alternative** (WCAG — a chart alone is
not screen-reader readable), legend always visible, tooltips keyboard-reachable, gridlines at `fg-line`
(low contrast) not `fg-ink`, direct value labels on small datasets, red/green never the only difference
(`pattern-texture` — relevant because this app's financial semantics *do* use red for danger), and
`prefers-reduced-motion` respected on the entrance animation. Dark mode needs checking separately: the token
set is tested for text contrast, not for six-series chart palettes.

### P3-4 · Print is specified in detail and has no implementation

`resources/views/print/` does not exist; there is no `@media print` block, no `@page { margin: 12mm }`, no
A4 rule, and `spatie/laravel-pdf`/dompdf is not in `composer.json`.
[DESIGN-SYSTEM.md:177](DESIGN-SYSTEM.md) is unusually specific (plain CSS subset, no Tailwind arbitrary values,
no webfonts needing the network, no flex-heavy layout, table-based totals, verified against the DOMPDF driver
in Phase 4, "what you print is what you get").

This is unbuilt scope rather than debt — but it is the item most likely to be discovered late, because a
print stylesheet cannot be reviewed on screen and DOMPDF fails on exactly the CSS Tailwind emits. **Plan it as
its own work package before Phase 4's invoice screen is written**, with a verification step in the project's
existing idiom: a `scripts/render-print.php` that renders each template through the DOMPDF driver to a PDF and
asserts the bytes exist and the page count is sane, plus a human read of one invoice and one statement.

---

## 5. How this stays fixed: three machine checks

The repository has no committed test suite ([DESIGN.md §14.6](../DESIGN.md)), so the quality gate is
`lint-architecture` + `lint-section-refs` + `pint` + the in-process `verify-*`/`render-*` scripts. UI rules
need to join that set, or they will drift exactly as P0-1 shows (a component default silently contradicting a
documented rule across 40 call sites).

Propose `scripts/lint-ui.php`, same shape as the existing linters, checking statically:

1. **One primary per view** — at most one explicit `variant="primary"` per page component file.
2. **No ARIA half-patterns** — a file using `role="tab"` must also use `role="tabpanel"` and `aria-controls`;
   a raw `<select>` is refused (use `x-ui.select`); an `x-ui.table` without `:caption` is refused.
3. **Refusal and feedback are visible** — every `addError('bag', …)` has a matching `@error('bag')` *or* an
   input with that `name` (this check is what would have caught the P0 finding I had to withdraw — and it is
   worth having precisely because it can prove a screen clean, not only dirty); every `wire:submit` form's
   submit button carries `wire:loading.disabled`; no `text-[…)` arbitrary font sizes; no raw hex in
   `resources/views/**`.

Add to the render sweeps (they already assert money never reaching a dispatcher's HTML — the same technique
covers UI): sort param round-trips, exactly one primary button in the rendered DOM, a caption per table, the
confirm dialog present before a destructive action, the kiosk at ≥44 px targets at a 390 px viewport, and the
live map's `aria-live` region.

And schedule the thing that has never been run: **a browser pass at three viewports** — 1440 (yard desktop),
1024 (laptop floor), 390 (driver phone, kiosk only) — one per work package below, recorded in
`DESIGN.md §20`, since the in-process sweeps deliberately cannot see layout, focus rings, or a signature drawn
under a thumb.

---

## 6. Sequencing

Deliberately mapped onto the phases in [DESIGN.md §15.2](../DESIGN.md) so this never competes with the product
plan, and so each package lands *before* the phase that would multiply its cost.

| # | Package | Contents | Do it | Why then |
|---|---|---|---|---|
| A | **Hierarchy honesty** | P0-1 button default, P0-2 tabs demoted, P3-1 one numeric path + captions, P1-4 `x-ui.select` | **Now, before Phase 3** | Every Phase 3 screen (fuel, maintenance, parts, work orders) adds buttons, tables and selects. Fixing the defaults first means Phase 3 inherits correctness instead of 60 more copies. |
| B | **Refusal & feedback completeness** | P1-1 submit states + skeletons + cost idempotency key, P1-2 empty states, P2-4 undo + one feedback channel | Phase 3, alongside it | Fuel variance and work-order approval are the flows where an unanswered button costs money. |
| C | **Registers become worklists** | P0-5 sorting (+per-user memory), P2-2 breadcrumbs + section index, P2-3 deep-link the meaningful state, P1-3 cursor pagination for the GPS/audit reads | Phase 3–4, screen by screen | Sorting wants the data shapes Phase 3 introduces (per-truck fuel series, work-order due dates). Retrofitting one screen at a time is cheaper than a sweep after eight more exist. |
| D | **The driver's phone** | P0-3 signature geometry, P0-4 whole kiosk contract (3 steps, 44 px, 16 px labels, registration shown, expiry copy, 429 copy, offline message, safe areas) | **Phase 3, as the kiosk's odometer and fuel screens are built anyway** | §9.6 says all four kiosk screens write through the same Action as the office UI. Three of the four are still absent — build the *first* one to the contract and the rest follow, rather than shipping four 40 px-button screens. |
| E | **Print** | P3-4 templates + print CSS + DOMPDF verification script | **Before Phase 4's invoice** | A statement designed on screen and printed through DOMPDF is rewritten twice. Sequence it ahead of the invoicing UI, not after. |
| F | **Charts** | P3-3 rules applied at first chart | Phase 8 | Nothing to do early except adopt the table-alternative habit in the metric cards, which are already there. |
| G | **Governance** | `scripts/lint-ui.php` + the sweep assertions + the three-viewport browser pass | **Start with A, keep adding rules** | Without this the plan decays; the checks are what make P0-1 a fact rather than an intention. |
| — | Decision needed | P0-6 command palette: build or delete | Ask now | It is documented, referenced in `config/navigation.php`, and does not exist. Either costs little; pretending costs a phase. |

**Explicit non-goals.** No new font pair (IBM Plex Sans/Mono is chosen and self-hostable), no new palette or
brand exploration, no restyle of the token set, no framework change (no Filament/Nova — rejected in
[DESIGN.md §12.2](../DESIGN.md)), no Reverb/websocket live map (deferred to v1.1 with a stated reason), and no
responsive treatment of the office app *as a phone app* — the phone surface is the kiosk, and pretending
otherwise would spend the effort in the wrong place.

---

## 7. Pre-delivery checklist for this codebase

Adapted from the skill's checklist to a Livewire/Blade desktop tool with one phone surface; the kiosk items are
marked Ⓚ.

- Exactly one `variant="primary"` per view; destructive actions `danger` and spatially separated.
- Every table: `:caption`, `scope="col"` headers, one numeric treatment, sort control where the register is a
  worklist, `aria-sort` reflecting it, and no `nowrap`-driven sideways scroll at 1280 px.
- Every form: label per field, error under the field it belongs to (or beside the action that raised it),
  `role="alert"` on the message, submit disabled with visible state, refusal copy from the Action shown.
- Every list: an empty state that names the action that would fill it; a skeleton where a wait exceeds ~300 ms;
  filters and page in the URL; `links()` appending the query.
- Every async region: `aria-live="polite"` where content changes unannounced, `wire:poll.visible`.
- Focus ring visible on every control (global rule exists — verify a component never overrides it); tab order
  matches visual order; no half-implemented ARIA widget.
- Status never colour-only (badge icons stay on); contrast re-checked in dark mode per change, not per theme.
- Motion only `transform`/`opacity`, `--t-fast`/`--t-base` tokens, reduced-motion honoured (already global).
- Ⓚ Touch targets ≥44 px, ≤3 fields per screen, primary action thumb-reachable, safe areas respected,
  registration visible, expired/rate-limited/offline states all have words, signature geometry verified on a
  real phone — not in a responsive emulator.
- Print: A4, `@page { margin: 12mm }`, no network webfont, no flex-heavy layout, totals in a table, rendered
  through the DOMPDF driver and eyeballed.
- No raw hex, no arbitrary `text-[…]`, no duplicated class strings that belong in a component.

---

## 8. What I need from you before starting

1. **Command palette (P0-6):** build it or delete the stub and correct `DESIGN-SYSTEM.md` §8 +
   `config/navigation.php`?
2. **Viewport contract (P2-1):** confirm 1024 px is the office floor (my assumption: a yard office on laptops;
   a dispatcher on a phone uses the kiosk link, not the board). If a dispatcher *should* be able to run the
   board on a phone, that is a different and much larger piece of work and it changes item C.
3. **Tab demotion (P0-2):** segmented control or complete the tab pattern? I recommend demoting.
4. **Cost idempotency key (P1-1c):** this touches `RecordTripCost`, not just the view. Approving it means
   manual trip costs get a generated `source_type`/`source_id` pair, which is a domain decision with a §6.6
   consequence (a retried post becomes a duplicate rather than a second cost). I believe it is right; it is
   your ledger.
5. **Sequence:** A→B→D next (hierarchy, feedback, the driver's phone) with the lint growing alongside, or
   finish Phase 3's domain slices first and take the UI pass after? The argument for doing A now is that A is
   cheap and Phase 3 multiplies everything in it.
