# Fleet Guard — the Owner's home, redesigned

Artifact for the `/awwwards` pass on `resources/views/pages/dashboard/⚡dashboard.blade.php`
(route `dashboard`, DESIGN-SYSTEM.md the first screen). This file is the Brief, Concept and
System the award workflow asks for, written into the project so later passes stay consistent.
It is **not** a second design contract: `docs/DESIGN-SYSTEM.md` stays the source of truth for
tokens, components and accessibility, and `DESIGN.md` stays the source of truth for what the
figures mean. Where this file and those two disagree, those two win.

## Phase 0 — Brief (inferred, not asked)

| | |
|---|---|
| Subject | Fleet Guard, the Owner's own cross-border freight operation (Botswana, SADC corridors). |
| Audience | The Owner seat, every working day. Secondary seats (Operations Manager, Accountant, Workshop Manager) see the same layout with different fields. |
| The screen's job | Answer "what needs me now" in three seconds, then "where do I work", then "what happened". |
| Assets | Real rows in `fleet_guard_dev`; no imagery was supplied and none is invented. |
| Language | English, sentence case, `P 1 234,56` money. |
| Constraints | Livewire page component + `resources/views/components/ui/*` only; Tailwind v4 tokens; no new JS dependency (GSAP/Lenis are deliberately not installed in an operations app); `wire:navigate` transitions stay at 120 ms opacity; every figure read through the door that owns it — no second source of truth; mask, never delete, for a closed permission. |
| Stack | The app's own: Laravel 13, Livewire 3, Alpine, Tailwind v4, Chart.js for the trend band. Ported idea from the workflow: the QA loop (screenshots at three widths, reduced motion, keyboard, hover, axe) — not its loader, hero video or WebGL, which an operations dashboard has no business shipping. |

**Assumptions written down** (nothing here is a claim about the business I could not read in the code):
the Owner is one person who both dispatches and closes the month; the daily question is cash held by
drivers and costs the ledger has not been told about; the yard office laptop is 1280-1440 px wide and
the phone is the driver's kiosk, not this screen's.

## Phase 1 — Concept, three territories

| | Idea | Signature | Original | Fit | Feasible | Portable |
|---|---|---|---|---|---|---|
| **A — The corridor** | The home reads as one route: yard → gate → border post → destination, with the fleet as marks along it. | A rail that draws itself to the trips actually on the road, the border marker where a truck sits `at_border`. | 3 | 4 | 3 (positions come from Phase 7; The projection is simulator data) | 3 — any TMS can draw a line |
| **B — The dip gauge** | Every figure on this screen is a measurement against a threshold the company set: days-to-expiry against `expiry_warning_days`, backup age against `stale_after_days`, cleared drivers against those on the books. | A threshold meter whose limit tick is the setting, not a guess. | 3 | 4 | 5 — all the numbers already exist | 3 |
| **C — The seam** | This system exists because an operational fact and a ledger fact must be reconciled. Between them sit exactly four seams: **earned and not billed, spent and not posted, received and not applied, advanced and not settled**. The home page is those four seams, measured, in the order you close them. | The hero is the money sitting on the first seam — earnings on delivered work that has no invoice yet — and the rail under it shows the other three with the figure each door owns. | 5 | 5 | 5 — every figure is an indexed sum on a column the modules already write | **1** — the argument is this product's, not a genre's |

**Chosen: C, wearing B's instruments.** One line: *the Owner's home is the list of things the truck
knows that the book does not yet.* The corridor survives only as the pipeline band's order (enquiry →
quoted → planned → on the road → proof back), because already put those five in work order and
a shuffled grid of equal tiles threw that order away.

### Concept Card

```
Idea:        The home page shows the four seams between the operation and the ledger, and what is
             sitting on each one today.
Metaphor:    A handover desk in a freight yard — paperwork passes from the truck's book to the
             statutory one, and anything still on the counter is the thing you are owed.
Signature:   The hero figure is money that exists on delivered work and has no invoice. Under it, a
             four-part seam rail where each half of the pair (what the module says, what the ledger
             says) is read through its own door, so agreement is shown, not asserted.
Emotion:     Standing at the counter with the load manifest: nothing is on fire, and nothing is
             uncounted.
Motion verb: "hand over" — a figure settles into place from 4 px below with its caption, and the seam
             rail's divider draws left-to-right once, on the one clock (the page paint).
Screenshot:  The masthead line, the Display-30 figure under "Earned, not billed", and the four seams
             in a row with their drill-down chevrons.
Anchor:      `chargeables.status = ready` × `base_amount`; `trips.ledger_posted_at is null` ×
             `direct_cost_base`; the trial balance's `2150` and `1160` lines. All four are columns the
             modules already write.
```

## Phase 2 — Art direction (inside the existing system, not beside it)

**Palette.** Nothing new. `--fg-canvas/surface/surface-sunk/ink/ink-secondary/ink-muted/line/
line-strong`, semantic `success/warning/danger/info`, and corridor teal `--fg-accent` **once per view**
: the accent stays on the primary action and the active nav item. The seam rail therefore carries
no new colour — its state colour comes from the existing tones, and a seam with nothing on it is
`--fg-ink-muted` with the word that says so (colour is never the only carrier).

**Type.** The scale, and the redesign is mostly a *hierarchy* fix rather than a re-skin: today 26 cards
each render a 30/34 semibold figure, so the page has 26 things shouting at the same volume. The new
order is one Display figure (30), four section Titles (20), figures in the seam/pipeline bands at 20 in
`IBM Plex Mono` tabular, body 14, meta 13/12. Sizes only from the documented ladder 12→13→14→20→30.

**Grid and rhythm.** The shell's 1400 px content max. Bands are separated by a `Title` + a hairline,
not by another card: the current page is a stack of equal rounded rectangles, which is the one pattern
that makes every figure look equally important. Rhythm varies on purpose — one wide hero row, one
four-part rail, one five-stage pipeline, a three-up instrument strip, then the charts at full width.

**Shape.** One radius rule as documented: `--radius-fg-md` for controls and cards, `--radius-fg-sm` for
chips. No new geometry, no blanket pills.

**Elevation.** `--shadow-fg-1` on interactive surfaces only; the section bands are flat on the canvas so
depth means "you can act on this".

**Motion language.** `--t-fast` for hover/focus, `--t-base` for the settle of a band, `--t-slow` for
nothing on this page (no modals here). Easing `--ease-standard` for reveals, `--ease-emphasis` for the
one divider draw. Transform and opacity only. Under `prefers-reduced-motion: reduce` the global rule in
`resources/css/app.css` collapses every transition; the settle is authored so its *final* state is the
readable one, so nothing is hidden when motion is off (the same rule the design states for content).

**Idle state.** A quiet operations screen may stay still at rest — written down here as the decision the
workflow asks for: hovers, focus, the chevrons and the charts carry the life; the only continuous motion
is the *alert* case. When a self-check has found drift (`reports:tie-to-ledger` raised), the seam rail's
status word breathes 1 → 0.55 over 2.4 s. It marks a real condition, it always has a word beside it, and
it stops under reduced motion. A decorative wobble on a clean desk would be worse than no motion, so a
clean desk is still.

**Composition plan (desktop ≥ 1280, each section a different layout family).**

```
1  masthead      one line, left: company · zone · mono stamp   right: the day's sentence
2  hero + desk   [ Display figure + delta + drill ]  [ the five rows that outrank everything ]
3  seam rail     four paired figures on one baseline, each labelled with the door it came from
4  pipeline      five stages in work order, counts as steps, hold reasons as the sub-line
5  instruments   three threshold meters (papers 12 of window 60 · backup age 3 of 30 · cleared 8 of 11)
6  trends        the existing chart band, unchanged doors, window control stays with the charts
7  where to work the existing navlist, subordinate on purpose
```

Mobile/phone (390 px) is composed, not squished: the hero and the seam rail stack one-up, the pipeline
becomes a vertical ladder, the charts keep their width, and touch targets stay at the `touch:` metrics
(lint U6). The floor for a register is 1024 px (UX-UI-PLAN ); this screen is the one place an
Owner may genuinely be on a phone, so it must read there without the kiosk's 44 px everywhere.

**Genericness test.** "Would a generic model land here?" A generic pass would return the grid of 26
identical KPI tiles that exists today — that is the templated answer, and it is what this page is
replacing. What a model cannot produce without the codebase: the four seams named as this system names
them (the two-seam rule, the handover, the unapplied cash, the driver advances), the
pipeline in work order, and a threshold meter whose limit is a value from `config/fleet.php` rather than
a stock gauge. Things I changed from the first reading: the hero is a *seam* figure rather than cash on
hand (the design answers "what needs me", and cash has no decision in it today); the money cards moved into
the seam rail so the page stops claiming four separate truths about the ledger; the backup and papers
cards became instruments so their threshold is visible instead of implied.

## Rules this pass will not break

1. No new number is invented here: every figure comes from a door that already answers it (`AgeingReport`,
   `LedgerStatements::trialBalance`, `Chargeable`, `Trip::direct_cost_base`, `Alert`, `BackupHealth`).
2. Mask, never delete: a closed permission renders the slot with the ability named (`x-ui.gated` state).
3. One primary action per view (lint U1) and `x-ui.*` controls only (U2); tables keep `:caption` (U3).
4. Figures are `numeric`/`identifier` treatments, never hand-copied `text-right font-mono` (U5).
5. Page query budget: this screen has a `scripts/perf-budget.php` ceiling; the new sums must be indexed
   aggregates, and the trial balance is walked once per paint rather than once per card (the.x
   `monthSheet` precedent).
6. `wire:navigate` opacity transition only. No slide on a data app.
7. Every cited rule is a real section: `////////`.

## QA log — pass 1 (2026-11-03, live on `fleet-guard.test/dashboard` as the Owner seat)

Measured through the in-app browser with axe-core 4.10.2 injected in-page, and against the app's own
gates. The dev book's state during this pass: 0 ready chargeables, 0 unposted trip costs, no unapplied
receipts, no `1160` balance (the intake rows are created and purged by the harness), one invoice
register at P 80 727,00 against a ledger `1150` of P 73 527,00.

| What was measured | Result |
|---|---|
| Render | HTTP 200, no console messages at all, no failed requests. |
| axe, light | **0 violations** on the whole document. |
| axe, dark (via the shell's own Theme toggle, which is the real path) | **0 violations**. |
| axe, dark (via a synthetic `classList.add('dark')`) | 49 colour-contrast findings — all of them dark text tokens on a light background. **This was the probe's fault, not the page's**: flipping the class without the app's theme mechanism left the surfaces at their light values while the text read the dark tokens. Recorded because the wrong measurement is easy to reach from here, and because a QA claim about dark mode must say which door it used. |
| Horizontal overflow | none — `documentElement.scrollWidth` 693 = `clientWidth` 693 at the in-app surface's 708 px viewport. |
| Headings, landmarks | one `<h1>`, sections named by `aria-labelledby`, the two sr-only `h2`s carry the hero and the desk. |
| Keyboard | skip link is the first focus stop ✓; 139 focusable elements; the global `:focus-visible` outline rule is present in the built CSS. |
| Motion | `.fg-settle` computed on the hero and the seam cells with the staggered delays arriving (0 s, 0.04 s, 0.08 s); five `.fg-draw` rules = five pipeline stages; `prefers-reduced-motion` block present in `app-D1DIcVMc.css` and it names all three classes. |
| Hierarchy | exactly one `text-3xl` figure on the page (the hero) — the whole point of the pass. |
| Type tokens | hero 30 px / 600 / -0.45 px IBM Plex Sans; seam and strip figures 20 px / 500 IBM Plex Mono tabular. |
| Page cost | `scripts/perf-budget.php`: **dashboard 74 queries, 508 ms** against a 100 / 800 ceiling — down from the 82 the config comment records, because the statement is walked once and the counts are grouped. |
| App gates | lint-views, lint-ui (U1–U6), lint-architecture, lint-section-refs (4 723 citations), lint-table-cols, `pint --test` (880 files), phpstan 1 G, `render-operations` and `render-reporting` — all green. `security:audit --static` and `performance:audit --static` green. |

**Defects pass 1 found, and what was done about each.**

1. **The hero and the first seam were the same figure twice** — same label, same number, same sentence,
   two cells on one screen. Fixed by making the seam band itself the hero's home: four cells, the first at
   Display 30 and the other three at 20, in the order the seams are closed. The left column then held only
   the ledger strip, which has since moved to its own full-width band below the desk.
2. **Four identical empty captions.** "Nothing is sitting on this seam." repeated across the rail — the
   copy rule says never repeat a sentence across sections, and four zeros that read the same are four
   different facts. Each seam now says what is empty in its own words.
3. **`.fg-breathe` was on the drift *text*.** Fading a caption to 55 % drops its measured contrast under
   AA at the dimmest frame, and axe only missed it because it sampled the bright half of the cycle. The
   breath moved to the icon; the words stay at full contrast; the rule is now written in
   `DESIGN-SYSTEM.md` and in `app.css` so the next screen does not repeat it.
4. **Two render needles named the old bands** (`Jobs waiting on a decision`, the revenue card's caption).
   The captions were restored where they carry information — one sentence for the strip, "Posted through
   03 Nov, read from the ledger", which a gated seat does not get — and the operations needle was
   rewritten to the five stages **and strengthened**: it now checks the planned count and the on-road
   count against the registers inside each stage's own slice of the HTML, which the old assertion only
   claimed to do.

**Still open at the end of pass 1.**

- **No screenshots yet, and therefore no outside critic.** The in-app Browser surface reports
  `visibilityState=hidden`, so `take_screenshot` refuses with `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`; the
  paint timings in that surface are also meaningless (first-paint reported 93 748 ms, which is the tab's
  age, not the page's). Playwright is not a project dependency and the app sits behind MFA, so the
  skill's own `shoot.mjs` route would need both installed and a session minted — neither of which happens
  without the Owner. The structural and accessibility evidence above is real; the *visual* judgement is
  not yet made, and this screen should not be called award-grade until a critic has read its frames.
- **Every seam read zero.** Honest, and it proves the empty states — but it means the warning tone, the
  `settled` contrast, the long-caption wrapping and the drift marker's neighbour layout have only been
  reasoned about, not looked at. A review pass with a non-zero state is needed; applying the two
  `Validated` intake batches from the pass (driver advances on `1160`, the facilities) would put
  real money on two of the four seams and on the balance sheet at the same time.
- `accounting.periods` now measures 37 queries against its 26 ceiling in `scripts/perf-budget.php`. It
  was failing before this pass (the run that found three failures became the run that finds one) and it
  belongs to the screen, not to the overview — recorded here so it is not mistaken for this work.


## Pass 2 — what is still owed

The plan for this screen was: a production asset build, then a live browser pass as the Owner in light
and dark at 1440 / 1024 / 390 px, `prefers-reduced-motion` forced, a keyboard-tab walk, hover on every
primary target, `axe-core` in-page, console errors, overflow, and a throttled LCP/CLS reading; then a
fresh critic subagent scoring the screenshots against `references/quality-bar.md` with no access to this
file or the code, its fix list becoming the next pass; then the app's own gates.

Done in pass 1: the build, the live render, axe in both themes by the real theme door, console, overflow,
keyboard order, focus rule, motion computation, the type tokens, the page budget and every app gate.

Owed, with the reason each is blocked rather than skipped:

1. **Frames and an outside critic.** The in-app surface reports `visibilityState=hidden`, so screenshots
   refuse (`NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`) and paint timings in it are the tab's age, not the
   page's. Needs the in-app Browser panel left open and visible; Playwright would additionally need to be
   installed as a project dev-dependency and given a session that only the Owner's MFA can mint.
2. **The three viewport widths as the browser sees them** (1440 / 1024 / 390). The measurements above were
   taken at the surface's 708 px, which proves no overflow there and nothing else about `xl:` and `sm:`.
3. **A non-zero state.** Every seam read P 0,00 in this book, so the warning tone, the `settled` pairing,
   long-caption wrapping and the drift marker's neighbours have been reasoned about, not looked at. The
   two `Validated` intake batches (driver advances onto `1160`, the three facilities) would put
   real money on two seams and on the balance sheet at the same time — which is also the outstanding half
   of that slice's own browser pass.
4. **`reduced motion` forced**, not only present in CSS: one real render with the OS flag on, to prove no
   content is left hidden and the settle lands visible.
5. **Hover, focus and press on each new primary target** read as pixels (the seam cells, the pipeline
   stages, the instrument tiles), not as class lists.

