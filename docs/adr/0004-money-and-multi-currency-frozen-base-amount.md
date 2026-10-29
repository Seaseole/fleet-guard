# ADR-0004 — Money as integer minor units; frozen authoritative base amount

**Status:** Accepted
**Date:** 2026-10-26
**Decides:** storage and arithmetic of every monetary value, and the multi-currency FX model.

## Context

Base currency BWP; the business transacts in ZAR, USD, ZMW, MZN and occasionally others across SADC corridors. Every foreign-currency transaction must retain the original currency and amount, the rate used, its source and date, plus the BWP equivalent. Money must be exact (invoices must add up on the page and in the ledger), rates must be auditable after the fact, and period close means a historical number can never change because an exchange rate moved.

## Decision

1. **Money is `bigint` minor units + an ISO 4217 code, always paired.** Never `decimal` for money; never a float. `brick/money` (`Money`, `BigDecimal`) behind a `MinorUnits` cast pair so no arithmetic bypasses it. Requires `ext-bcmath` (or gmp) — verified on both the dev PHP 8.4 build and the Cloud runtime in Phase −1.
2. **JSON serialises money as strings** (`{"amount":"125000","currency":"BWP"}`), never as JSON numbers — a future mobile client must not be able to lose precision in transit.
3. **The FX block is copied verbatim onto every transactional table:** `currency_code char(3)`, `amount unsignedBigInteger` (minor units *of `currency_code`*), `fx_rate decimal(19,8)`, `fx_rate_source`, `fx_rate_date`, `fx_rate_ref`, `base_currency_code` (copied from the company at write time), `base_amount unsignedBigInteger`.
4. **`fx_rate` always means "1 foreign unit = X base", never the inverse.** If a source quotes the other direction, invert at write time and record it in `fx_rate_source`. A rate that "sometimes means the inverse" is how FX bugs are born; that is why there is no `rate_type` column.
5. **`base_amount` is authoritative and frozen at write time.** Reports, VAT, P&L, balance sheet and ledger lines read `base_amount`. Nothing re-derives a historical amount from a current rate. The write-time invariant `base_amount == round(amount × fx_rate)` is enforced in the DTO and re-checked by `ledger:verify` (a DB CHECK cannot reliably multiply-and-round across the decimal/integer boundary).
6. **The edit rule, decided now:** editing the **foreign** amount recomputes `base_amount` from the stored rate; editing the **base** amount (because the bank charged exactly that) back-solves `fx_rate` at 8 dp HALF_UP and sets `fx_rate_source = 'manual_override'`. One editor per document type in the UI, so two people never disagree about which knob moved.
7. **`exchange_rates` is a reference lookup only** — it pre-fills new documents and powers a "this ZAR rate is 4 days stale" warning. It never joins into historical amounts. Rates are manual first, behind a `RateResolver` interface, so a Reserve Bank of Botswana or bank feed becomes an adapter, not a refactor.
8. **`currencies` is a table, not an enum** (`code`, `minor_units`, `rounding_mode`, `symbol`), because minor-unit counts are facts about the world.
9. **Rounding is explicit and documented per operation** (HALF_UP): document totals sum *rounded lines*, never round-of-sum, so a customer's invoice adds up on the page; VAT is computed per line then summed; the **last** payment allocation absorbs the ±1 minor-unit residue so a settled invoice reaches exactly zero; litres and price/litre are stored ×100 as integers.
10. **Realised FX** posts to `7510/7511` at allocation time. **Unrealised revaluation is deferred** (phase 5+, opt-in) — an accountant-driven feature that should not be built speculatively.

## Alternatives rejected

| Option | Why rejected |
|---|---|
| `decimal(12,2)` money columns | The brief forbids it, and correctly: allocation residues, VAT per line and multi-currency conversion all accumulate rounding drift that `decimal` hides rather than prevents. |
| Floats | Disqualified. |
| Store only the base amount | Loses the counterparty's actual figure — a ZAR-denominated fuel receipt at Beitbridge must be evidenced in ZAR for audit, dispute and FX reconciliation. |
| Recompute base amounts from current rates at read time | Silently rewrites history and breaks the trial balance against the ledger after a period close. |
| A single global `fx_rate` table joined into transactions | Turns rate history into a mutable foreign key; `exchange_rates` exists as a lookup, with the used rate copied onto the row. |
| `rate_type` column ("this rate is inverted") | Doubles the state space of every FX calculation to solve a problem that one direction convention solves for free. |
| Laravel `decimal:2` casts as the money abstraction | No currency identity, no explicit rounding mode, no typed arithmetic — the exact three things this design needs. |

## Consequences

- **Positive:** money arithmetic is exact and auditable; a period close is safe because base amounts are immutable facts; FX history carries its own evidence (`fx_rate_source`, `fx_rate_ref`, `fx_rate_date`); adding a currency is a row, not a release; a mobile/API client cannot misread precision.
- **Costs:** every amount column is two columns and the discipline to keep them consistent; totals are denormalised and must be recomputed by the writer (CHECK + `ledger:verify` are the safety net); UI screens for foreign documents need two numbers plus a rate on screen at once; the extension requirement (`bcmath`) is a deployment dependency to verify, not assume.
- **Irreversible in practice:** choosing which column is authoritative. Making `amount × rate` the truth later means re-deriving every historical figure — i.e. restating closed periods.
