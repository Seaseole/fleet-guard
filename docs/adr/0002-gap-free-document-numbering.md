# ADR-0002 — Gap-free document numbering with a transactional sequence table

**Status:** Accepted
**Date:** 2026-10-26
**Decides:** `number_sequences` and the allocation algorithm used by invoices, credit notes, receipts, journal entries, trips, jobs, work orders and POs.

## Context

The brief requires gap-free sequential invoice numbers. Botswana tax invoicing may impose strict sequence rules (unconfirmed — Q2 in `DESIGN.md`), and a fiscal document whose number can be skipped, reused or quietly deleted is an audit problem, not a UI problem. The app must survive two clerks issuing invoices at the same moment, and it must run on **MySQL on Laravel Cloud** while being developed on MariaDB.

## Decision

1. One table, one row per `(company_id, scope, period_key)`, holding `next_value` and a `format` template. `period_key` is per-scope config: `'2026'`, `'FY26/27'`, or `''` for continuous — so a fiscal-year restart is a setting, not a migration.
2. **Allocation happens inside the business transaction** with a row lock:
   `SELECT next_value … FOR UPDATE` → `UPDATE … SET next_value = next_value + 1` → render the number from `format` → commit. A rollback releases the lock *and* the value, so no gap and no reuse.

   *Amended 2026-10-26 after measurement (`DESIGN.md` ).* This originally opened with `INSERT IGNORE` to create the row on demand. On MySQL 9.1 that **deadlocks**: an `INSERT IGNORE` which finds the row already present takes a shared next-key lock, so the following `FOR UPDATE` is a shared-to-exclusive lock upgrade, and four concurrent workers produced `SQLSTATE[40001] … 1213 Deadlock found` — in the steady state, not only while the row was being created. Sequence rows are therefore **created ahead of allocation** by `numbering:ensure` (scheduled nightly, and run by the seeder); allocation never inserts, and a missing row is a loud error pointing at that command. Re-measured after the change: 60 allocations across 4 concurrent processes produced 60 distinct, gapless numbers with zero deadlocks. Because a lock wait can still end in a serialization failure under load, the **enclosing Action** retries the whole business transaction (`DB::transaction($fn, attempts: 3)`) — the retry must cover the document write too, or a re-allocated number would be attached to a half-applied insert.
3. **Number at issue, not at creation.** `invoices.number` is `NULL` while `status = 'draft'`, with `CHECK (status <> 'issued' OR number IS NOT NULL)`. Drafts are addressed by ULID. Every allocated number therefore corresponds to an issued fiscal document.
4. **Void keeps its number.** "Gap-free" means *unique, monotonic, never reused, never removed*, so voiding retains the row and the number and is constrained: no payment allocation, no credit note referencing it, never delivered, same-business-day or Owner override with a mandatory reason. Anything a customer has seen gets a **credit note** in its own scope.
5. **No I/O inside the numbering transaction** — PDF render, email and queue dispatch go through `dispatch()->afterCommit()`. Multi-document transactions allocate in deterministic `(scope, period_key)` order.
6. **Detection:** nightly `documents:number-integrity` asserts, per `(company, scope, period_key)`, that the sequence head matches the count of non-draft documents, that numbers are strictly monotonic, non-duplicate, and never `NULL` on issued rows; drift raises an alert.

## Alternatives rejected

| Option | Why rejected |
|---|---|
| `max('id')+1` or `count()+1` | Lost updates under concurrency — two documents, one number. |
| `auto_increment` as the visible number | InnoDB skips values on rollback, bulk insert and restart; **not** gap-free; ID spacing leaks sales volume. |
| MariaDB native `SEQUENCE` / `NEXTVAL()` (≥10.3) | Values are **non-transactional**: a rolled-back transaction burns the number, creating exactly the gap being promised against. Also MariaDB-only syntax while production is MySQL on Laravel Cloud. Legitimate only if gaps are acceptable. |
| ULID/UUID as the visible number | Right as a PK, wrong as a document reference: short, comparable, dictatable numbers are a human requirement for phone follow-up and filing. |
| Postgres-style sequences / advisory locks | Wrong engine; a `SELECT … FOR UPDATE` row lock is the equivalent that works on both. |
| Numbering at draft creation | Breaks the meaning of gap-free: abandoned drafts would consume fiscal numbers. |

## Consequences

- **Positive:** gap-free *and* gap-detectable; voids stay auditable; numbering survives the MariaDB→MySQL move; the fiscal-year question becomes configuration; ULIDs keep URLs stable while a document is still a draft.
- **Costs:** every issuing path takes a row lock — a stuck transaction shows as a user-facing hang (default `innodb_lock_wait_timeout` 50 s) rather than corruption, which means the "keep the transaction small" rule is a product requirement, not style. Numbered documents cannot be created by a queue worker outside a transaction without re-implementing the allocator. Bulk imports must allocate in one pass (phase 0.5 does this deliberately).
- **Residual risk:** whether Botswana BRBS/eTIMS requires a per-year restart, and whether a voided eTIMS invoice must be corrected by credit note only. Answering Q2 changes configuration, not schema — which was the point of `period_key`.
