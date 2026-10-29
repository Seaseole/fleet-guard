# ADR-0001 — Double-entry ledger schema with typed dimension columns

**Status:** Accepted (owner approved the design on 2026-10-26; ledger slice lands in Phase 0)
**Date:** 2026-10-26
**Decides:** `gl_accounts`, `gl_journal_entries`, `gl_journal_lines`, `gl_periods`; immutability enforcement; period locking; correction-by-reversal; trip cost rollups.

## Context

Every operational module produces money: fuel purchases, parts issues, customs costs, invoice lines, receipts, driver advances, loan instalments, payroll. The owner needs profit per truck, route, customer and trip **and** has chosen full in-app accounting, so the same structure must also produce a trial balance, P&L, balance sheet and cash flow that an accountant can sign, with months that lock and cannot change afterwards.

Automated tests are not committed to the repository (`tests/` exists locally only), so invariant enforcement cannot rely on a suite. Production runs on **Laravel Cloud**, where the database user cannot be granted INSERT-only rights.

## Decision

1. **Three tables plus periods.** `gl_accounts` (a tree with `parent_id`, `is_postable`, `is_system`, `allowed_dimension_mask`, `external_ref`), `gl_journal_entries` (ULID PK, human `entry_no`, `posted_at` UTC instant *and* `posted_on` business date, `period_id`, `status draft|posted|void`, denormalised `total_debit`/`total_credit`/`line_count`, `source_type`/`source_id`/`source_event_id`, `reverses_entry_id`, `netting_group`), `gl_journal_lines` (append-only bigint PK, `direction`, `amount_foreign`, `amount_base`, `posted_on`, **typed dimension FK columns**) and `gl_periods`.
2. **Typed dimension columns, not a generic `dimension_type`/`dimension_id` pair:** `truck_id`, `trailer_id`, `trip_id`, `job_id`, `customer_id`, `supplier_id`, `driver_id`, `employee_id`, `work_order_id`, `invoice_id`, `bill_id`, `fuel_issue_id`, `border_crossing_id`, `country_code`, `cost_centre_id`, plus `dimension_extra JSON` for the long tail. Rule: **a dimension earns a column when a report needs it twice.**
3. **`source_type`/`source_id` stay on the entry, not the line** (one document → one entry), with `UNIQUE (company_id, source_type, source_id, source_event_id)` as the idempotency guard for replays.
4. **Balance and immutability enforced in four layers**: (a) models refuse update/delete; the only write path is `LedgerService::post(PostingDto)`; (b) DB `CHECK` on the entry (`posted → total_debit = total_credit > 0`); (c) `BEFORE UPDATE/DELETE` triggers using `SIGNAL SQL STATEMENT` with a seeded single-row `gl_ledger_barrier` as the audited emergency hatch; (d) a `BEFORE INSERT` trigger rejecting postings into a `locked` period. Laravel 13 exposes no schema-builder `check()` or trigger helpers, so all of (b)–(d) are `DB::statement` in migrations.
5. **Corrections are reversing entries only**, posted at today's `posted_on` with `reverses_entry_id` + `netting_group`; never back-dated into a locked period.
6. **`posted_on` (business date in Africa/Gaborone) is the period key**, stored on both entry and line; `posted_at` is the UTC instant. Application timezone stays UTC.
7. **Trip profitability is a write-time projection**, not a query pattern: `TripCostRollup` recalculates the trip's line sums inside the posting transaction and stores them on `trips`; `trips:recost` recomputes everything and diffs, which *is* the reconciliation report.

## Alternatives rejected

- **`dimension_type`/`dimension_id` polymorphic pair.** Cannot carry truck *and* trip on one line (needed day one); turns "profit per truck" into a pivot or self-join per dimension, rewritten constantly and unindexable. Cheapest to change *now*, brutal to change after a year of postings.
- **No ledger — dimension tags on operational tables only.** Adequate for profitability, but the owner chose statutory accounting: no trial balance, no period lock, no auditor-recognisable record, and an ETL project later.
- **A GL package / accounting SaaS integration.** No well-maintained Laravel double-entry package exists that we would trust as the system of record; an external accounting product reintroduces the two-truth-systems problem the brief is trying to escape.
- **`decimal` money columns.** Rejected globally in favour of integer minor units (ADR-0004).
- **Materialised `gl_daily_balances`.** A second truth to maintain for a workload of ~220k lines/year; deferred until a report exceeds ~500 ms.
- **INSERT-only DB grant as the immutability layer.** Strongest option, **not available on Laravel Cloud** (users are read-only/read-write, the injected user cannot be edited). Documented as a residual risk (R2) rather than claimed as a control.

## Consequences

- **Positive:** reports are ordinary indexed `GROUP BY` queries; corrections are visible and accountant-legible; period locks are enforced below the application; replays are impossible to double-post silently; the same structure serves operational profit and statutory statements.
- **Costs:** every migration touching the ledger needs `DB::statement` and must be tested on **both engines** until dev matches prod (R1); the CHECK/trigger layers are load-bearing because there are no committed tests, which raises the cost of casual refactoring; `total_debit`/`total_credit` are trusted-but-verified derived values, so `ledger:verify` is not optional; the emergency barrier row is a documented way to break immutability and must itself be audited.
- **Irreversible in practice:** the representation of dimensions on posted lines. Changing polymorphic→typed (or adding a dimension to history) means migrating the most sensitive table in the system. This is the reason it is an ADR.
