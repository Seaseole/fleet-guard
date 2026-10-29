# Fleet Guard

Fleet, haulage and cross-border transport management for a Botswana trucking business operating across SADC. 21+ trucks and trailers, BWP base currency, Africa/Gaborone.

**Read [DESIGN.md](DESIGN.md) first.** It is the engineering contract: module map, ERD, money and ledger design, permission matrix, state machines, domain events, package list, folder structure, build order, risks and the verification log. [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) is the UI contract. The decisions that are hard to reverse live in [docs/adr/](docs/adr).

## Stack

Laravel 13.38 · PHP 8.4 · Livewire 4.4 (single-file components) · Alpine.js · Tailwind CSS v4 · Vite · MySQL · `wire:navigate`

UI is our own component set in `resources/views/components/ui/*` against the token contract in [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md). `livewire/flux` was uninstalled on 2026-10-26; do not reintroduce a second component library (`DESIGN.md`).

## Status

| Phase | State |
|---|---|
| Design document | ✅ Approved 2026-10-26 ([DESIGN.md](DESIGN.md) v1.1) |
| −1 — Platform & repo | 🟡 In progress: scaffold, docs, tooling done locally; Cloud environment and the dev database engine decision outstanding |
| 0 — Foundations | ⬜ Not started (auth hardening, RBAC, audit, money, numbering, documents, ledger slice) |
| 0.5 → 9 | ⬜ See |

## Local development

```bash
composer install
npm install && npm run build     # or: npm run dev
composer run dev                 # server + queue + logs + vite
```

Requirements: PHP 8.4 with `bcmath` (Laravel's `crypt` uses `openssl`), MySQL, Node 22+. `.env` targets the local MySQL instance; `DB_DATABASE=fleet_guard_dev`.

## Conventions that are not negotiable

- Money is integer minor units + an ISO currency code. Never `decimal`, never float. (`DESIGN.md`)
- Every business write goes through an Action in `app/Domain/{Module}/Actions`. Livewire components delegate; they do not decide.
- `company_id` leads every unique key and hot index, and the scope fails closed. ([ADR-0003](docs/adr/0003-single-tenant-with-company-id-everywhere.md))
- Posted ledger entries are immutable: corrections are reversing entries. ([ADR-0001](docs/adr/0001-double-entry-ledger-schema.md))
- Ledger and documents number through `number_sequences` at issue time, never at creation. ([ADR-0002](docs/adr/0002-gap-free-document-numbering.md))
- Drivers authenticate with trip-scoped kiosk tokens, not accounts. ([ADR-0006](docs/adr/0006-driver-kiosk-tokens-not-driver-accounts.md))
- Tests are written locally and **never committed** — `tests/` is gitignored by owner decision. Correctness in production comes from DB constraints, triggers and the `*:verify` commands. 
- No invented business data in code or UI; demo data only through `database/seeders/Demo`.

## Quality gates

```bash
vendor/bin/pint --test                        # formatting
vendor/bin/phpstan analyse --memory-limit=1G  # static analysis (larastan, level 7)
```

`composer lint:architecture` (the module dependency-direction check from `DESIGN.md`) arrives in Phase 0, once `app/Domain/` exists to check.

`php artisan view:cache` only *compiles* Blade to PHP — it will happily pass a template that throws `ParseError` when rendered, which is exactly how `/dashboard` broke once (`DESIGN.md`). A view change is verified by requesting the page, not by caching it.

## Open questions

The design document lists the blocking questions — accountant answers on VAT/BRBS numbering and financial year-end, the dev database engine, and the GPS provider shortlist. Each has a stated default so work does not stall. The Flux question is **closed**: the dependency was removed and the app runs on our own primitives (§20.2).
