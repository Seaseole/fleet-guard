# Fleet Guard

Fleet, haulage and cross-border transport management for a Botswana trucking business operating across SADC. 21+ trucks and trailers, BWP base currency, Africa/Gaborone.

**Read [DESIGN.md](DESIGN.md) first.** It is the engineering contract: module map, ERD, money and ledger design, permission matrix, state machines, domain events, package list, build order, risks, and the phase-by-phase verification log. [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) is the UI contract. Decisions that are hard to reverse live in [docs/adr/](docs/adr).

## Stack

Laravel 13 · PHP 8.4 · Livewire 4 (single-file components) · Alpine.js · Tailwind CSS v4 · Vite · MySQL · `wire:navigate`

UI is our own component set in `resources/views/components/ui/*` against the token contract in [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md). `livewire/flux` was uninstalled on 2026-10-26; do not reintroduce a second component library.

## Status

| Phase | State |
|---|---|
| Design document | ✅ Approved 2026-10-26 ([DESIGN.md](DESIGN.md)) |
| −1 — Platform & repo | ✅ Repo, tooling, dotfiles done. Laravel Cloud environment creation is the one step that needs the owner's account (see [deployment](docs/operations/deployment.md)) |
| 0 — Foundations | ✅ Auth/MFA, RBAC, company scope, audit, money, numbering, documents, ledger slice, queues |
| 0.5 — Legacy intake + cutover | 🟡 Fifteen intake datasets are wired and six of them open balances: nine master-data importers and the opening trial balance, the **opening receivables and payables as documents**, the **odometer history** and the **yard tanks' opening litres**, and since the **unreconciled driver advances** (`Dr 1160 / Cr 3010`, driver-dimensioned) and the **loan schedules** the 13-week forecast needs (rebuilt plan, no posting — the liability stays the trial balance's `2210` line). Left: parts stock as an opener, and the timed dry-run rehearsal |
| 1 — Master data | ✅ Fleet, people, commercial master, parts, document subjects |
| 2 — Operations | ✅ Jobs → trips → dispatch → kiosk POD → trip costs → crossings → GPS ingest + trip map |
| 3 — Fuel + maintenance | ✅ Fuel spine, bulk tank, variances, workshop, stores, tyres, inspections, breakdowns |
| 4 — Revenue | ✅ Invoices, credit notes, payments, allocation, customer documents, consolidated runs, since, **corridor-currency issuing, realised FX at settlement, hand-raised manual documents and the deposit door** |
| 5 — Accounting | ✅ Bills/AP, supplier payments, manual journals, chart of accounts, all four statements, bank/cash reconciliations, period close/lock, loans + instalments + the 13-week forecast, driver settlements, and margin per truck/route/customer — harness-verified. **Left by design: the year-end profit carry-forward, salary runs (the line), and the human "a real month closes" (UAT Part B)** |
| 6 — Cross-border completion | 🟡 Border crossings and their cost capture are live inside Operations. Permits, transit documents, weighbridge tickets, customs cost types, agent float reconciliation and per-country summaries are not built |
| 7 — Telematics | 🟡 Ingest, simulator, trip map, provider accounts and projection exist. Live map, geofences, deviation alerts, retention/pruning are not built |
| 8 — Reporting + dashboard | ✅ Six report definitions with a metric catalogue, queued PDF/Excel exports, four scheduled mails, dashboard money band, nightly `reports:tie-to-ledger` |
| 9 — Hardening + release | 🟡 Security and performance audits, encrypted off-site export + verify + restore drill, key rotation, deployment config, runbook, user guide, UAT script, this README. **Two acceptance items remain open** (below) |

### Phase 9 acceptance — still open

The definition of done ends with two human checks, and neither can be claimed from this machine:

1. **A restore from a Cloud snapshot and from the off-site export.** The off-site half is proved locally with a real scratch-schema drill (`backup:verify-offsite --drill`, see [restore](docs/operations/restore.md)). The Cloud-snapshot half needs a Cloud cluster and an operator.
2. **A staff member who did not build the system completes a trip-to-invoice without help.** The script is written and waiting in [docs/uat.md](docs/uat.md) Part A.

The real-month close (Phase 5 DoD) now has its control — the periods screen closes and locks with a pre-flight report — but the DoD's last witness is human: [docs/uat.md](docs/uat.md) Part B waits for a real month to close with the owner's accountant agreeing with the P&L.

### Known gaps (honest list)

- **The opening balance sheet now has party detail, physical truth, drivers and lenders** (`verify-opening-intake.php`, 110 checks): `docs/cutover/demo/` carries the files the accountant is handed, and each row becomes a real invoice, bill, odometer reading, tank movement, driver-dimensioned receivable or financing facility — no VAT on the money rows, `Dr 1150 / Cr 3010` or `Dr 3010 / Cr 2110` or `Dr 1160 / Cr 3010`, the legacy dates kept on the paper while every entry is struck on cutover day so a closed period is never reached into, a backwards reading stored as `suspect` rather than overwritten, the tank's litres moving the book so its first dip disagrees with a real opening figure, and the loan file posting **nothing** while rebuilding the instalment plan insists on storing. An account has one opener: a trial-balance line on `1150`, `2110`, `1180` or `1160` is refused once the matching intake has been applied, and the other way round — `2210` deliberately names no account, which is what makes a facility schedule and a trial-balance liability the same money counted once. Still missing from Phase 0.5: **parts stock** (same shape as the tank, not yet a column), and the **timed dry-run rehearsal**, which will run against this dev book then reverse and purge, as every harness here does. Import order is not enforced by anything except the refusals: customers and suppliers before their open items, trucks, tanks, employees and drivers before their measurements and slips.
- **Revenue now speaks more than one currency** (`verify-fx-invoicing.php`, 88 checks): a corridor trip issues in the currency it was quoted in, a receipt freezes the rate the bank struck, and the gap between the two is booked as realised FX on `7510`/`7511`. What that slice deliberately left: **no partial foreign settlement** (one receipt pays one foreign document whole — splitting would blend two rates into neither), **a mixed-currency customer statement** whose rows print document figures while its totals sum in base, **no receipts register screen** (a deposit is visible as an on-account figure, reachable only from a customer row or an invoice), **no line editor for a draft** (wrong paper is discarded and re-raised), and **`exchange_rates` has no writer or screen** — `StoredRateResolver` is a door with nothing behind it until Phase 6 opens the rate register. Phase 4's first acceptance test — two concurrent issuers, two sessions, distinct numbers — is still unwritten, and now has a third door (manual issue) for the proof to cover.
- **The period close is operable**, the **financing book is live**, **driver settlements sign and post**, and **the corridor question is answerable** (margin per route and per customer beside the truck sheet). What remains of the Phase 5 DoD is human — the accountant agreeing with the P&L on a real month ([docs/uat.md](docs/uat.md) Part B) — plus **the year-end profit carry-forward into 3020** (open-period earnings ride the balance sheet's equity side until then) and **salary runs**, which stay behind the payroll-lite line.
- **Known dev-data reds** (not code): numbering counters burned by earlier interrupted harness runs make the nightly `documents:number-integrity` report drift on trip/job (`verify-operations` 5 fails, `verify-costs` 1), and last month's orphan `invoice_lines` rows block `verify-kiosk`'s trip cleanup. Clearing them is a data sweep, not a fix.
- **Bank reconciliation is live-verified** (`verify-reconciliation.php`, 28 checks) and so are the balance sheet and cash-flow statement (`verify-statements.php`, 31 checks) — but no browser pass has been recorded for any of these four screens yet (the standing MFA rule). Loans, instalments and the 13-week forecast are live since. Register rows must be linked to their ledger accounts (Bank & cash → Ledger account) for the cash-flow sheet and the reconciliation screen to read any cash.
- **A deploy that widens the permission catalogue must re-run role provisioning** (`RoleProvisioner::provision()` via `fleet:setup-owner`, or first-boot for a new company): a permission the code has but the role rows do not is refused fail-closed.
- **The off-site export does not include document bytes** — it carries ledger-critical tables only, so restoring it needs the private documents bucket restored separately.
- **No charts.** The dashboard is figures, reports are tables with totals.
- **Export files have no retention sweep**, and a scheduled mail records only `last_sent_at`.
- **Cloud disks require `league/flysystem-aws-s3-v3`**, which is a composer suggestion and not installed. The switch is configuration only; see [deployment](docs/operations/deployment.md).
- **No browser pass has been recorded for Phases 8–9.** Every screen trips the owner's MFA, and the shared session is not ours to mint; in-process render sweeps are the stand-in, and the visual pass remains open.
- **Tests are local-only and gitignored** by owner decision. Correctness in production comes from DB constraints, triggers and the `*:verify` commands.

## Local development

```bash
composer install
cp .env.example .env                 # if needed; then set DB_* and DB_CRYPT_KEY
php artisan key:generate
php artisan migrate
npm install && npm run build         # or: npm run dev
composer run dev                     # server + queue + logs + vite
```

Requirements: PHP 8.4 with `bcmath`, MySQL (the dev box runs MySQL 9.1, see [docs/DEV-DATABASE.md](docs/DEV-DATABASE.md)), Node 22+, and **`DB_CRYPT_KEY`** — encrypted columns fail closed without it; there is no `APP_KEY` fallback. `.env` targets `fleet_guard_dev`.

First run on an empty database: `php artisan db:seed` for the reference data, then `php artisan fleet:setup-owner` to create the Owner seat.

## Quality gates

```bash
composer ci:check                     # everything CI runs, in order
vendor/bin/pint --test                # formatting
vendor/bin/phpstan analyse            # larastan
composer lint:architecture            # module dependency direction, D1–D6
composer lint:section-refs            # every DESIGN.md citation resolves
composer lint:ui                      # pages use the shared UI primitives
composer audit:static                 # security:audit --static, performance:audit --static
```

`composer ci:check` is what [.github/workflows/tests.yml](.github/workflows/tests.yml) runs on every push and pull request. There is no committed test suite to run after it.

Live checks against a real database and the app kernel are tracked harnesses in `scripts/`:

| Harness | What it proves |
|---|---|
| `verify-kernel.php` | Company scope, money, numbering, audit, identity |
| `verify-ledger.php`, `verify-documents.php` | Ledger immutability and the number-integrity sweep; the private document store |
| `verify-master.php`, `verify-identity.php`, `verify-settings.php`, `verify-alerts.php` | Master data, seats and roles, company-held settings, the alert engine |
| `verify-operations.php`, `verify-dispatch-lock.php`, `verify-kiosk.php`, `verify-crossing.php`, `verify-telematics.php` | Jobs/trips/dispatch, the kiosk doors, crossings, GPS ingest |
| `verify-fuel.php`, `verify-maintenance.php`, `verify-costs.php` | Fuel, workshop and stores, trip cost rollup |
| `verify-invoicing.php`, `verify-invoice-numbering.php`, `verify-bills.php` | Invoicing, gap-free numbering, bills and supplier payments |
| `verify-reporting.php`, `verify-cutover.php` | The report library, exports, mails, tie-out; legacy intake |
| `verify-opening-intake.php` | Opening receivables and payables as documents, the opening odometer history and tank litres as measurements, the driver advances as `1160` receivables and the facilities as plans that post nothing: empty error reports for the shipped demo files, one party-dimensioned entry per document, the ageing and per-bill tie-outs reading them on day one, an unchanged journal-entry count across the loans apply, the one-account-one-opener cross-check in both directions, entries struck on cutover day with the slip's own date kept, and every retry landing on the row it already wrote |
| `verify-statements.php` | The balance sheet's equation across a window edge, the direct-method cash-flow sections, transfer invisibility and the cash-link guards |
| `verify-reconciliation.php` | Opening refusals, the add/sub walk to zero, approval only at zero, signed runs frozen, void frees the day |
| `verify-period-close.php` | The pre-close report's red/green cash row, close → posting refused → reopen → close → lock, and the reopen-locked refusal |
| `verify-loans.php` | The frozen amortisation adding up exactly, the paper refusals, the draw as one entry, the split payments and replays, the forecast window carrying the plan rows |
| `verify-settlement.php` | The advance sheet's four guards, the signed entry line-for-line with its driver dimension, the mirror on void, the trip freed for the retry |
| `verify-profitability.php` | The three margin sheets' totals recomputed straight from the operational tables — no private numbers allowed |
| `verify-fx-invoicing.php` | A corridor trip billed in its own currency at the rate its frozen figures imply, both ADR-0004 edits on a manual document, the deposit in either currency, and realised FX booked on 7510/7511 with the last receipt units absorbing the residue |
| `verify-backup.php`, `verify-rotation.php` | Off-site export/verify/drill; column-key rotation |

Each harness refuses production, prints `N checks, M failed`, exits non-zero on failure, and cleans up in a `finally`. `scripts/render-*.php` are the in-process render sweeps that stand in for the browser pass. They need a live MySQL and are deliberately **not** part of `ci:check`.

## Operations

| Document | Use |
|---|---|
| [runbook](docs/operations/runbook.md) | Every scheduled alert, its first response, and the incident procedures |
| [deployment](docs/operations/deployment.md) | Environment matrix, off-site bucket switch, release and rollback, first deployment |
| [restore](docs/operations/restore.md) | Cloud snapshot restore, off-site export format, and the local drill |
| [key-rotation](docs/operations/key-rotation.md) | `APP_KEY`, `DB_CRYPT_KEY` (with `keys:reencrypt`) and `FLEET_BACKUP_KEY` rotation |
| [hardening](docs/operations/hardening.md) | Secret inventory, escrow rules, and the human security checklist |
| [user guide](docs/user-guide.md) | Sign-in, roles, the golden path, reports, alerts — written for staff, not builders |
| [UAT](docs/uat.md) | The trip-to-invoice script, the real-month close, and the restore/key-rotation checks |

## Conventions that are not negotiable

- Money is integer minor units + an ISO currency code. Never `decimal`, never float.
- Every business write goes through an Action in `app/Domain/{Module}/Actions`. Livewire components delegate; they do not decide.
- `company_id` leads every unique key and hot index, and the scope fails closed ([ADR-0003](docs/adr/0003-single-tenant-with-company-id-everywhere.md)).
- Posted ledger entries are immutable; corrections are reversing entries ([ADR-0001](docs/adr/0001-double-entry-ledger-schema.md)).
- Ledger and documents number through `number_sequences` at issue time, never at creation ([ADR-0002](docs/adr/0002-gap-free-document-numbering.md)).
- Drivers authenticate with trip-scoped kiosk tokens, not accounts ([ADR-0006](docs/adr/0006-driver-kiosk-tokens-not-driver-accounts.md)).
- Encrypted columns carry a `key_version` and are read only through `FieldCipher`; a missing key fails closed.
- Tests are written locally and **never committed** — `tests/` is gitignored by owner decision.
- No invented business data in code or UI; demo data only through `database/seeders/Demo`.
- Every screen a permission guards is a route with its gate; a route without one is a `security:audit` finding the next morning.
