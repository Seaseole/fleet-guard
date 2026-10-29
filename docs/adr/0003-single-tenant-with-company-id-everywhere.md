# ADR-0003 — Single-tenant with `company_id` on every table

**Status:** Accepted
**Date:** 2026-10-26
**Decides:** how the app models "one company now" without closing the door on more companies later.

## Context

One owner, one company today; employee accounts later; the brief says single-tenant is the default but "the design must not make later multi-company support impossible". Multi-company can mean anything from two related shell companies in one database to legally isolated entities that must not share a database. The cheap commitments are the ones that are impossible to add retroactively; the expensive mistakes are the speculative ones.

## Decision

Five commitments, and nothing beyond them.

| # | Commitment |
|---|---|
| T1 | `company_id unsignedBigInteger NOT NULL DEFAULT 1` on **every business table**, FK to `companies`, and as the **leading column of every unique key and hot composite index** (`UNIQUE(company_id, code)`, `INDEX(company_id, scope, period_key)`, `INDEX(company_id, status, posted_on)`). |
| T2 | **Discriminator scoping**: a `BelongsToCompany` trait + Eloquent global scope; `CurrentCompany` resolved from `auth()->user()->company_id` by middleware, memoised per request, **never taken from the URL**; auto-filled on `creating`. No tenancy package, no schema-per-tenant, no per-company database. |
| T3 | **Fail closed.** A scoped model queried with no company context **throws** instead of returning unscoped rows. `withoutCompanyScope()` is explicit and reserved for CLI/totals; console commands require `--company=1`. |
| T4 | **Company-held settings**, not `config('app.*')`: `base_currency_code`, `timezone`, `fiscal_year_starts_on`, `country_id`, `vat_number`, `registration_number`, invoice footer, numbering formats, approval thresholds. Rule: `Company::current()->base_currency_code`. |
| T5 | Every policy compares `$model->company_id === $user->company_id` **even while redundant**. |

`number_sequences`, `gl_accounts`, `gl_periods`, document storage paths (`company/{id}/…`) and RBAC roles are all company-keyed.

## Alternatives rejected

- **Nothing (assume one company forever).** The later change is: add a column to ~120 tables, then change ~90 unique indexes, then find every hard-coded `BWP` and `Africa/Gaborone`. Index changes are data-integrity events, not migrations.
- **`stancl/tenancy` or similar.** Buys per-tenant schemas and domain routing we do not want, and makes every query, cache key and storage path someone else's decision.
- **Database-per-company from day one.** Correct for legally isolated entities with different accountants and tax regimes; wrong here, and it makes reporting across the group harder than making it.
- **Using the Laravel starter kit's "teams" feature as tenancy.** Teams is a billing/membership concept with URL-scoped slugs; it would leak into routes and permissions for a problem we do not have.
- **A `company_id` column but *not* in the indexes.** Half the cost with none of the benefit: single-tenant query plans would silently degrade on the day a second company appears.

## Consequences

- **Positive:** a second company becomes a UX and provisioning change (invite users, set a COA, configure numbering) rather than a schema redesign; hard-coded currency/timezone is prevented structurally, which is the migration cost people actually forget to budget; the fail-closed rule converts a cross-tenant leak from a silent data breach into a 500 that a developer sees on the first day.
- **Costs:** every query carries a predicate it does not need today; every unique index is wider; developers must remember `withoutCompanyScope()` for cross-company totals (there will be one); the scope exception must not be bypassed for convenience — the temptation to "just query without the scope" is the failure mode.
- **Honest ceiling:** this supports a handful of related companies comfortably in one database. Real legal isolation still means separate deployments — and nothing here prevents that. Deliberately **not** designed: users belonging to many companies, company switching, inter-company billing, consolidated group reporting.
