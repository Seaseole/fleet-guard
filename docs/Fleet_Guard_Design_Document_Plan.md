# Fleet Guard — Design Document Plan

## Context

You need a new Laravel application to run a Botswana trucking business across SADC: 21+ trucks/trailers/equipment, one owner today, staff accounts later, BWP base currency, Africa/Gaborone. The attached brief asks for **one deliverable right now: a design document** — module map, ERD, money/currency strategy, ledger design, permission matrix, state machines, domain events, package list with reasons, folder structure, build order, risks, open questions — and only after you approve it does Phase 0 start. `C:\laragon\www\Fleet Guard` is currently empty and is not a git repository, so this plan is about what that document must decide, not about existing code.

This plan exists so you can approve the **substance** before I spend a writing session transcribing it into `DESIGN.md`. Everything below is a decision I want your eyes on; the document itself will expand each into full schema sketches, a Mermaid ERD and tables.

## Decisions you have already locked (from this session)

| # | Decision | Consequence for the design doc |
|---|---|---|
| 1 | **Production target: Laravel Cloud** | No Windows production. Managed queues + worker clusters + scheduled tasks exist. PDF must use a no-Chrome driver. DB backup/restore is Cloud-managed, not a `mysqldump` cron. |
| 2 | **Full accounting system in-app** (statutory books, not just profit reporting) | The double-entry ledger is the financial system of record: chart of accounts, period close, trial balance, P&L, balance sheet, cash flow, manual journals, reconciliation. Phase 5 grows from ~3 weeks to ~2–3 months and needs your accountant's sign-off on the COA before it is seeded. |
| 3 | **Trip-scoped kiosk tokens for drivers** | Drivers get no password/MFA account. A per-trip, expiring token on any phone captures POD photo + signature + odometer + fuel readings, writing through the same Actions as the office UI. Office staff get Fortify accounts with mandatory TOTP. |
| 4 | **`tests/` may exist locally but stays gitignored** | Local-only Pest/PHPUnit suites guard the ledger, numbering, FX and allocation invariants; `pint` + `larastan` still run. The doc keeps DB constraints and `ledger:verify`-style self-checks as production safety nets, but refactors stay cheap. |

## Stack facts I verified (not assumed)

- **Laravel 13** — released 2026-03-17, requires PHP 8.3+, supports 8.3–8.5, bug fixes to Q3 2027. Relevant first-class attributes: `#[Middleware]`, `#[Authorize]`, `#[Tries]`, `#[Backoff]`, `#[Timeout]`; plus `Queue::route(Job::class, connection:, queue:)` for central queue routing, `Cache::touch()`, and the renamed `PreventRequestForgery` middleware. Native vector/AI features need Postgres+pgvector — irrelevant here, excluded.
- **Livewire 4** — single-file components (class + template + JS in one `.blade.php`) are the new default, with islands, optimistic UI, drag-and-drop, and `wire:navigate` for SPA nav.
- **Laravel 13 Livewire starter kit** = Livewire 4 + Tailwind + Flux UI, **Fortify-backed**. 2FA (TOTP) ships **enabled by default** (`Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`), public registration is disabled by removing `Features::registration()`, and `/user/confirm-password` (re-auth before sensitive actions) ships as a route. A WorkOS AuthKit variant exists and would give passkeys — noted, not chosen.
- **Laravel Cloud** — PHP 8.2–8.5 runtimes; **Laravel MySQL** (managed MySQL, 5 GB–1 TB, auto-upsize) or Serverless Postgres; Laravel Valkey for cache; **managed queues** with dedicated workers, autoscaling to zero and a built-in failed-job retry dashboard; **worker clusters** for long-running processes; **scheduled tasks continue while the environment sleeps**; object storage available; preview environments per PR; automated daily DB backups with 1–30 day retention that **restore to a new cluster and are not exposed as raw `mysqldump` files**; DB users can be **read-only or read-write only**.
- **Local dev box** — PHP 8.4.27, Composer 2.10.3, **MariaDB 11.8.2**, Redis 8.8, Node 26.12.1, Git 2.55.
- **Package compatibility (checked against Packagist today)** — `spatie/laravel-permission` 8.3.2 (PHP ^8.3, L ^12|^13) ✅ · `owen-it/laravel-auditing` 14.0.6 (illuminate ^11|^12|^13) ✅ · `spatie/laravel-pdf` 2.14.0 — **v2 has a DOMPDF driver that needs no external binary/Node/Docker** ✅ (the Cloud answer) · `spatie/simple-excel` 3.11.0 (wraps `openspout` 5.12.2) ✅ · `brick/money` 0.15.2 (suggests `ext-bcmath`/`ext-gmp`) ✅ · `spatie/laravel-model-states` 2.14.2 and `spatie/laravel-activitylog` 5.1.3 both require **PHP ^8.4** · `spatie/laravel-backup` 10.3.3 released tag declares **Laravel ^12 only** ⚠️ · `laravel/reverb` 1.12.0 (docs assume Supervisor/Unix tuning) · `laravel/horizon` needs POSIX signals — **not usable on Windows, and unnecessary on Cloud** because Cloud ships a queue dashboard.

## What the design document will contain, and what each section decides

### 1. Module map and dependencies
12 modules (Fleet, Operations, Fuel, Maintenance, Invoicing, Accounting, People, CrossBorder, Telematics, Reporting, Documents, Security) on top of a **shared Kernel** (Ledger, Numbering, Money, Documents, Audit, Tenancy, State machines). Dependency direction written as a rule, not a suggestion: `UI → Livewire components → Domain Actions → Models`; `Kernel` is called by everyone and calls no module; modules never post to the ledger except through `LedgerService`; cross-module *notification* goes through events, cross-module *invariants* are direct synchronous calls. A Mermaid graph of module→module edges accompanies it.

### 2. Entity relationship diagram (Mermaid)
Full `erDiagram` with ~60 main tables and their keys: `companies`, `users`, `customers`, `suppliers`, `routes`, `route_rates`, `trucks`, `trailers`, `assets`, `vehicle_assignments`, `drivers`, `employees`, `jobs`, `trips`, `trip_legs`, `loads`, `proof_of_deliveries`, `trip_access_tokens`, `fuel_tanks`, `fuel_purchases`, `fuel_issues`, `bulk_tanks`, `service_schedules`, `work_orders`, `work_order_lines`, `tyres`, `tyre_positions`, `parts`, `stock_movements`, `inspections`, `breakdowns`, `invoices`, `invoice_lines`, `chargeables`, `payments`, `payment_allocations`, `credit_notes`, `gl_accounts`, `gl_journal_entries`, `gl_journal_lines`, `gl_periods`, `number_sequences`, `loans`, `loan_installments`, `bills`, `bank_accounts`, `border_crossings`, `border_costs`, `permits`, `weighbridge_tickets`, `agent_cash_advances`, `exchange_rates`, `currencies`, `documents`, `document_versions`, `audits`, `security_events`, `gps_positions`, `geofences`, `alerts`. Keys and cardinality shown; the long tail gets a per-module detail diagram.

### 3. Money and currency strategy
- Integer minor units everywhere via `brick/money`, `unsignedBigInteger` columns + explicit `direction` columns, never `decimal` for money, never float, and money serialises as **strings** in JSON.
- The FX column block is applied verbatim to every transactional table: `currency_code`, `amount` (minor units of that currency), `fx_rate decimal(19,8)` **always meaning "1 foreign unit = X base"**, `fx_rate_source`, `fx_rate_date`, `fx_rate_ref`, `base_currency_code`, `base_amount`. **`base_amount` is authoritative and frozen at write time**; nothing joins back to a live rate.
- One direction only, because a rate that "sometimes means the inverse" is how FX bugs are born. Editing the base figure back-solves the rate and records `fx_rate_source = 'manual_override'`.
- `currencies` is a data table (code, minor_units, rounding_mode, symbol), not an enum, because ZAR/ZMW/MZN/USD all appear on these corridors.
- Rounding: `ROUND_HALF_UP`, explicit in one `RoundingPolicy` class; documented per operation (allocation, VAT, per-100km).
- Realised FX posts a gain/loss line at payment allocation; **unrealised revaluation is deferred** and only appears in the doc as a phase-5+ option.

### 4. Ledger design (the hard-to-reverse one)
`gl_accounts` (tree, `code`/`type`/`normal_balance`/`is_postable`/`allowed_dimension_mask`), `gl_journal_entries` (ULID PK, `entry_no`, `posted_at` UTC instant vs `posted_on` business date, `period_id`, `status draft|posted|void`, `total_debit`/`total_credit` base, `source_type`/`source_id`/`source_event_id` with a **unique idempotency index**, `reverses_entry_id`, `netting_group`), `gl_journal_lines` (append-only bigint PK, `direction`, `amount_foreign`, `amount_base`, **typed dimension FK columns** — `truck_id`, `trailer_id`, `trip_id`, `job_id`, `customer_id`, `supplier_id`, `driver_id`, `employee_id`, `work_order_id`, `invoice_id`, `bill_id`, `fuel_issue_id`, `border_crossing_id`, `country_code`, `cost_centre_id` — plus a `dimension_extra` JSON for the long tail) and `gl_periods`.

Why typed columns and not `dimension_type`/`dimension_id`: a generic pair cannot hold truck *and* trip on one line (needed day one) and turns "profit per truck" into a pivot you will rewrite forty times; typed columns make it `GROUP BY truck_id` over a composite index. A dimension earns a column when a report needs it twice.

Balanced-or-it-fails, in layers — and note this is where the Cloud decision bit:
1. App-level immutability: models refuse `updating`/`deleting`; the only write path is `PostJournalEntry`.
2. DB `CHECK` on the entry row (`status='posted' → total_debit = total_credit > 0`) — MariaDB ≥10.2.1 and MySQL ≥8.0.16 both enforce these; Laravel 13's schema builder has **no `check()` or trigger helpers**, so migrations use `DB::statement`.
3. `BEFORE UPDATE/DELETE` triggers using `SIGNAL SQL STATEMENT` with a documented single-row "barrier" escape hatch, and a `BEFORE INSERT` trigger that rejects postings into a locked period (a CHECK cannot read another table).
4. **The fifth layer the architect recommended — an INSERT-only DB grant — is not available on Laravel Cloud** (users are read-only or read-write, and the default user cannot be edited). So immutability against a person with Database Manager credentials becomes an *operational* control: Cloud daily backups + binary logs (~8 days) + restore-to-new-cluster forensics, plus `ledger:verify` in the scheduler alarming on drift.
- Corrections are **reversing entries only** (`reverses_entry_id` + `netting_group`), posted at today's date with a reference to the original — never back-dated into a locked period.
- Trip profitability is a **write-time denormalised rollup on `trips`** (`revenue_base`, `fuel_cost_base`, `maintenance_cost_base`, `border_cost_base`, `driver_cost_base`, `direct_cost_base`, `contribution_base`) recalculated inside the posting transaction, with `trips:recost` recomputing from the ledger as the tie-out. No materialised daily-balances table until a report exceeds ~500 ms; at ~150 postings/day you will not get there for years.
- Because you chose full in-app accounting: **the ledger is the system of record for financial statements, operational tables drive per-truck/trip profitability screens, and `reports:tie-to-ledger` reconciles the two** so an unposted bill can never silently change a number the owner is acting on.
- `Documented as an ADR` because it is the least reversible thing in the project.

### 5. Invoice and document numbering
`number_sequences (company_id, scope, period_key, next_value, format)` with `SELECT … FOR UPDATE` allocation **inside the business transaction**. Rejected in the doc with reasons: `max+1`/`count+1` (lost updates), `auto_increment` as the visible number (InnoDB skips values, leaks sales volume), MariaDB native `SEQUENCE`/`NEXTVAL` (non-transactional — a rollback burns the number, creating exactly the gap you promised not to have; also MariaDB-only, and production is MySQL). Rules that make "gap-free" meaningful: **number at issue, not at creation** (drafts carry a ULID and `number IS NULL` until issued), void keeps its number and is constrained (no allocations, no credit notes, never delivered, same-day or Owner override with reason), no mail/PDF/HTTP inside the numbering transaction (`dispatch()->afterCommit()`), and a nightly `documents:number-integrity` alarm. `period_key` is per-scope config (`2026`, `FY26/27`, or continuous) so the fiscal-year question becomes a setting, not a migration.

### 6. Tenancy
Single company now, but five cheap commitments that make multi-company a UX change rather than a rewrite: `company_id NOT NULL DEFAULT 1` on every business table **as the leading column of every unique key and hot index**; discriminator scoping via a `BelongsToCompany` trait + global scope (no `stancl/tenancy`, no schema-per-tenant); **fail closed** — a query with no company context throws instead of returning unscoped rows, with an explicit `withoutCompanyScope()` for CLI and totals; company-held settings (`base_currency_code`, `timezone`, `fiscal_year_starts_on`, VAT number) instead of hard-coded `BWP`/`Gaborone`; policies always compare `$model->company_id === $user->company_id` even while redundant. Explicitly *not* designed: user-belongs-to-many-companies, company switching, inter-company billing, consolidated groups.

### 7. Permission matrix by role
Roles: Owner, Operations Manager, Dispatcher, Workshop Manager, Stores Clerk, Accountant, Driver-facing Kiosk (token scope), Read-only Auditor. Matrix of module × `view / create / update / approve / void / close-period / export / download-documents`, enforced by **policies on every route, Livewire action and export** (spatie permissions drive coarse gates and nav; policies make the real decisions), with a **field-level layer** — `trip.margin`, `job.rate_cost`, `truck.purchase_price`, `employee.salary`, `bank_account.number` — behind named gates so a dispatcher sees operations but not margins. Encrypted sensitive columns (`masked` display column kept in clear for search/sorting, identifier encrypted with a **separate `DB_CRYPT_KEY` + `key_version` column** so `APP_KEY` rotation is a re-encrypt job, not a data-loss event) are listed per column in the doc, because you cannot add or remove encryption later without a re-encryption migration.

### 8. State machines
Native backed enums stored as `string(24)` + a first-party `CanTransition` trait (not `spatie/laravel-model-states`: per-state classes multiply files for ~15 states and transitions are enforced in Actions/Policies anyway). Each transition writes an `operational_events` row (actor, timestamp, **mandatory reason** for anything destructive) before the column changes. The doc contains transition tables with allowed actors for: **trip** (`draft → planned → assigned → loaded → departed → in_transit ⇄ at_border → arrived → pod_pending → delivered → completed → closed`, plus `on_hold`, `cancelled`, `aborted`, Owner-only `reopened`), **job**, **invoice** — split into three axes: lifecycle (`draft → issued → void`), derived settlement (`open | partially_paid | paid | refunded | written_off`), derived ageing buckets; **work order** (`draft → awaiting_approval → approved → in_progress ⇄ on_hold → completed → inspected → closed`, with Owner approval above a configurable cost threshold — the control that stops a fitter ordering tyres), **loan**, **document** (lifecycle vs derived validity, so "expired" is one definition instead of five), **fuel issue**, **border crossing** (`planned → entered → exited → costs_captured → agent_reconciled → closed`), **credit note**.

Three places the doc will tell you the obvious design is wrong:
- **`invoiced` is not a trip state.** One job can spawn several invoices and one invoice can bundle many trips; billing status is a derived `billing_state` off `chargeables`/`invoice_lines`.
- **A trip is not a job.** `Job (commercial: customer, rate, terms) → trip legs (vehicle + driver + dates) → POD per leg → chargeables → invoice per customer per billing run`. Retrofitting that split after the dispatch board exists is a rewrite.
- **Ledger posting is not a state.** Parts post when stock leaves, labour when the timesheet is approved, external bills when recorded; `closed` only freezes editability and triggers the capitalise-vs-expense decision. Gate posting on `closed` and your parts stock and your month are both wrong mid-period.

### 9. Domain events between modules
A producer → event → subscriber table with a sync/queued column and an idempotency key per row. The governing rule, stated because it is the difference between a trusted number and a reconciled one: **at 21 trucks, money moves synchronously inside the business transaction** (ledger postings, sequence allocation, trip rollup, stock decrement, state transitions, audit rows) and **only fan-out is queued** (PDF, email, exports, notification fan-out, GPS normalisation, geofence evaluation, scheduled reports). Rules carried into the doc: event-carried state transfer (the DTO carries `posted_on`, amount, currency, rate, dimension ids — subscribers never re-read mutable state to decide numbers); `posted_at` from the event's `occurred_at`, never job execution time, so a 02:00 retry cannot land in the wrong period; `ShouldBeUnique` on the event ULID plus the ledger's unique source index for duplicate delivery; a job that hits a locked period **fails loudly** into a visible "posting failures" screen rather than auto-posting into the open period; paired postings (invoice + VAT) go in **one entry with many lines**, and no event cascade two deep.

### 10. Package list, each with a reason
Adopted: `laravel/framework ^13` on PHP 8.4 · **Livewire 4 starter kit (Fortify-backed)** — gives 2FA, `/user/confirm-password` re-auth, rate-limited login and disabled registration without custom auth code · `spatie/laravel-permission` v8 · `owen-it/laravel-auditing` v14 (before/after values are literally the requirement) · `brick/money` + `ext-bcmath` (verify the extension on the Cloud PHP runtime; fallback is `ext-gmp`) · `spatie/laravel-pdf` v2 **with the DOMPDF driver as the only driver** (no Chrome binary on Cloud or on Windows dev; print CSS kept to a plain subset so both render the same) · `spatie/simple-excel` (streaming via openspout, queued for big ranges) · `spatie/laravel-medialibrary` **rejected** in favour of purpose-built `documents` + `document_versions` (versioning, expiry, type taxonomy, private disk, signed short-lived URLs, retention) · `spatie/laravel-model-states` **rejected** (native enums + guard) · `maatwebsite/excel` **rejected** (two Excel stacks for nothing) · `laravel/sanctum` for the later versioned API · `laravel/reverb` **deferred** — live positions use `wire:poll` at 60–120 s, with `ShouldBroadcast` events still emitted so enabling Reverb later is config plus one Echo block; Cloud worker clusters make that switch possible.
Backup strategy rewritten for Cloud: `spatie/laravel-backup` **not viable as the database backup path** (10.3.3's released tag declares Laravel ^12 only, and on Cloud backups are managed snapshots you cannot `mysqldump`). Instead: Cloud daily DB backups at 30-day retention + documented restore-to-new-cluster drill + a **monthly application-level encrypted export of ledger-critical tables to a separate off-site S3-compatible bucket** (no DB credentials needed), and `spatie/laravel-backup`-style file backup restricted to the documents disk. `backup_health` row so silence is loud.

### 11. Folder structure
`app/Domain/{Kernel,Fleet,Operations,Fuel,Maintenance,Invoicing,Accounting,People,CrossBorder,Telematics,Reporting,Security}/{Models,Actions,Policies,States,Events,Listeners,Data,Support}`, thin Livewire 4 single-file components under `resources/views/components/{module}/` (SFCs are Blade-resident, so UI modularity mirrors module names; rule: promote to `--mfc`/`--class` past ~250 lines), `routes/web/{module}.php`, `app/Http/Controllers/Api/V1` thin, `config/fleet.php`, prefixed migrations, `docs/adr/NNN-*.md`. The doc includes the full tree plus the `use`-statement grep that enforces dependency direction without a test suite.

### 12. Build order — your phases 0–9, with three structural fixes
Your order puts the **ledger in phase 5 while phases 3–4 must post to it** (fuel, parts, work orders, customs, invoices, receipts, advances, disbursements are all posting events), puts **cross-border after invoicing** although rechargeable duty/permits and agent floats are inputs to the invoice amount, and puts **hardening in phase 9** although audit/RBAC/MFA/documents/numbering are worthless retrofitted. Proposed sequence (each phase ends with the summary, manual verification checklist and seed data you asked for):

```
-1  Cloud environment + infrastructure decision execution (regions, MySQL cluster, Valkey, object storage, SMTP, DNS, backups, secrets)
 0  Foundations: starter kit + Fortify 2FA + RBAC + audit + security_events + tenancy +
    money/FX + number_sequences + documents skeleton + LEDGER SLICE (COA v1, entries, lines,
    immutability, periods, LedgerService::post) + read-only journal browser + queue/failed-job screen
0.5 Legacy data intake + opening balances + cutover rehearsal (this phase is missing from the brief
    and is the single most common reason fleet systems never go live)
 1  Master data: fleet, trailers, customers, suppliers, drivers, employees, routes, rates, parts, COA review
 2  Operations: jobs → dispatch board → trips → POD → chargeables + crossing records + GPS ingest/simulator
 3  Fuel + maintenance: consumption/variance, service schedules, work orders, parts, tyres, inspections, breakdowns
 4  Invoicing: draft→issue→send, VAT, deposits, receipts + allocation, credit notes, ageing
 5  Accounting: bills/AP, bank+cash reconciliation, period close, trial balance, P&L/BS/cash flow,
    loans + 13-week schedule roll-up, driver settlements, profitability by truck/route/customer/trip
 6  Cross-border completion: permits, agents, floats + reconciliation, FX, weighbridge archive
 7  Telematics completion: adapters, geofences, ETA, deviation alerts, live view
 8  Reporting: report library, queued PDF/Excel exports, scheduled email, dashboards deepened
 9  Hardening + release: restore drill, pen-review, load check, runbook, user guide, final README
```
Dashboard KPI cards ship **with** each phase rather than only in phase 8 (it is the screen the owner uses daily and it surfaces data-quality problems early); tyre management folds into maintenance as `tyre_positions` + tread readings rather than a sub-module; the cash-flow "forecast" is a static 13-week roll-up of known schedules, no scenarios.

### 13. Risks (top of the list, with mitigations)
1. **Dev/prod database divergence** — you develop on MariaDB 11.8, Cloud runs managed MySQL. Triggers, `CHECK`s and stored generated columns exist in both, but the dialects differ (MariaDB `SEQUENCE`, implicit grouping, utf8 collations, `utf8mb4_0900_ai_ci` only in MySQL 8). Mitigation: **align dev to MySQL 8** (Laragon can run it side by side) or accept a documented dual-engine CI run; the doc picks one and lists every DB-level feature against it.
2. **No GRANT-level ledger immutability on Cloud** (see §4) — mitigated by triggers + verify alarms + backup forensics.
3. **Backup/export constraint** — Cloud snapshots cannot be downloaded directly; a genuine off-site copy requires an application-level export job or a restore-then-dump drill. Mitigation and cost are spelled out rather than assumed.
4. **Full in-app accounting is now the biggest scope item** — ~2–3 months inside phase 5, gated on an accountant. Mitigation: ADR, COA workshop before phase 0 seeding, statutory boundary written as a rule (`People` computes gross/allowances/advances/net and posts one payroll journal; **no payslips, no PAYE/NSSF/BWRA filings**).
5. **Fiscal/regulatory unknowns that change schema**: BRBS/eTIMS VAT mechanics, whether tax-invoice sequences must be strictly gap-free per year, per-year vs financial-year restart, zero-rating of export/cross-border freight, document retention periods, SADC permit/roadworthiness regimes, driver-hour rules. Mitigation: one hour with your accountant before the COA is seeded and before numbering is frozen; unresolved ones become numbered open questions in the doc, not silent design choices.
6. **GPS provider dependency** — cost, coverage at remote borders, API churn. Mitigation: adapter + simulator is a first-class deliverable, `gps_positions` carries `provider` + unique `provider_message_id` for webhook dedupe, a 4-week local buffer, and **no business invariant depends on GPS being online** (fuel variance computes off odometer alone).
7. **Garbage-in data quality** — odometer trust, pump vs driver-reported litres, aux-tank honesty, missing POD photos. Mitigation: variance raises an alert with an acknowledgement workflow and never auto-corrects; opening/closing readings required at `departed`/`arrived` with plausibility bounds; a per-trip exceptions register so reports can exclude disputed data.
8. **Scope vs one developer** — 12 modules is realistically 6–10 months. Mitigation: the re-sequenced plan makes phases 0–5 the "run the business" v1, tags each phase, git from day one, ADRs as the institutional memory, and a named cut list (loans forecasting depth, scheduled reports limited to 2–3 you nominate, driver app out, passkeys deferred to the WorkOS option).
9. **A practical one**: `C:\laragon\www\Fleet Guard` has a space in the path and no git repo. Vite, Laragon vhosts and composer package names all hate it. Recommendation: create the project as `C:\laragon\www\fleet-guard` with `APP_NAME="Fleet Guard"`, and `git init` in phase −1. This is much cheaper to fix now than after phase 2.

### 14. Open questions the document will list (with a default if you don't answer)
Who closes the books today and in what software (still worth answering — it sets how much of phase 5 is a first-class UX vs an export) · BRBS/eTIMS gap-free numbering and void-reversal rules · financial year-end for numbering and period locking · VAT rate(s), registration number, zero-rating treatment for cross-border legs · parts inventory: perpetual stores with stock counts, or an issue register? · Flux UI licence tier for commercial production — default is **build our own Tailwind v4 primitives from `docs/DESIGN-SYSTEM.md`** and keep Flux only if the licence suits · GPS provider shortlist, cost per vehicle/month, webhook availability · insurance/finance partner data feeds · who the second approver is for period close · which 2–3 reports earn scheduled email.

## Deliverable files (created after you approve this plan)

| Path | Contents |
|---|---|
| `DESIGN.md` (repo root) | The whole design document: sections 1–14 above, with the Mermaid ERD, the full column-level ledger/FX sketches, the permission matrix table, all state-machine transition tables, the event table, the package table with reasons, the folder tree, the phase plan with per-phase done-definitions, risks and open questions. **No application code.** |
| `docs/adr/0001-double-entry-ledger-schema.md` | The ledger decision, alternatives rejected, reversibility. |
| `docs/adr/0002-gap-free-document-numbering.md` | Sequence table + row lock vs auto_increment vs MariaDB SEQUENCE. |
| `docs/adr/0003-single-tenant-with-company-id-everywhere.md` | Tenancy commitments and their ceiling. |
| `docs/adr/0004-money-and-multi-currency-frozen-base-amount.md` | `brick/money`, minor units, authoritative `base_amount`, rounding. |
| `docs/adr/0005-laravel-cloud-runtime-constraints.md` | Cloud: MySQL not MariaDB, managed queues, DOMPDF-only, backup/restore reality, no INSERT-only grants. |
| `docs/adr/0006-driver-kiosk-tokens-not-driver-accounts.md` | Field capture design and its API consequences. |
| `docs/DESIGN-SYSTEM.md` | Tokens (colour, type scale, spacing, radii, shadows, motion), light/dark, component inventory (buttons, inputs, tables, filters, badges, cards, modals, toasts, empty/error/skeleton states), list/filter/detail/create/edit patterns, WCAG AA contrast, keyboard support and the Ctrl-K command palette spec. |

Nothing in this step writes application code, scaffolds Laravel, runs `composer`, or creates the git repo — those are Phase 0 and gated on your approval of `DESIGN.md`.

## Verification

1. You read `DESIGN.md` against the 14 requirements in your brief; every requirement in the attachment maps to a named section (I will include a traceability table so an unanswered requirement is visible, not silently dropped).
2. Mermaid ERD renders in the repo's Markdown preview and in Qoder (diagrams are the easiest thing to get syntactically wrong, so the file is opened and checked rather than assumed).
3. Schema sketches are re-read for the specific things that bite later: every unique key leading with `company_id`, every money column typed as integer minor units with a currency code, every transactional table carrying the FX block, `posted_on` present on every ledger line, and no `route_id`/`tyre_id`/`permit_id` FK on journal lines (deliberately excluded).
4. The Cloud constraints are re-verified at `composer create-project` time in Phase −1 (extension list on the Cloud PHP runtime, MySQL major version, `spatie/laravel-backup` tag, Livewire 4 SFC filename convention, Flux licence) and any change is written back into the ADRs before Phase 0 code.
5. Each subsequent phase ends with the summary + manual verification checklist + seed-data set you asked for, and stops.

## Explicitly out of scope

Application code, scaffolding, tests, driver mobile app, statutory payroll (PAYE/NSSF/BWRA, payslips, filings), passkeys/WorkOS, WhatsApp notifications, multi-company UX, inter-company billing, unrealised FX revaluation, ML scenario forecasting, any paid map or GPS provider integration.

## Sources

- [Release Notes — Laravel 13.x](https://laravel.com/framework/docs/releases)
- [Laravel 13 Released (laravel-news)](https://laravel-news.com/laravel-13-released)
- [Laravel 13.x Starter Kits & Authentication (Fortify, 2FA, Flux UI, registration, password confirmation)](https://laravel.com/docs/13.x/starter-kits)
- [Livewire 4.x — Components (single-file components)](https://livewire.laravel.com/docs/4.x/components)
- [Laravel Cloud — Welcome / platform overview](https://laravel.com/cloud/docs)
- [Laravel Cloud — Runtimes (PHP versions, managed queues, worker clusters, scheduled tasks)](https://laravel.com/cloud/docs/runtimes.md)
- [Laravel Cloud — Laravel MySQL (backups, restore-to-new-cluster, users, binary logs)](https://laravel.com/cloud/docs/resources/databases/laravel-mysql.md)
- [spatie/laravel-pdf v2 introduction (DOMPDF driver)](https://spatie.be/docs/laravel-pdf/v2/introduction)
- [spatie/laravel-permission prerequisites / releases](https://spatie.be/docs/laravel-permission/v8/prerequisites)