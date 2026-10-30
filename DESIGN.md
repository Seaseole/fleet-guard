# Fleet Guard — Design Document

**Status:** Approved 2026-10-26 · Phase −1 in progress · **no business logic written yet.**
**Scope of this document:** everything the brief asked for before Phase 0: module map and dependencies, entity relationship diagram, money and currency strategy, ledger design, permission matrix, state machines, domain events, package list with reasons, folder structure, build order, risks and open questions.
**Decisions locked by the owner (2026-10-26):** production target **Laravel Cloud**; **full accounting system in-app** (the ledger is the statutory books, not only a profitability engine); **trip-scoped kiosk tokens** for driver field capture instead of driver user accounts; `tests/` **may exist locally but stays gitignored**.

| Field | Value |
|---|---|
| Product | Fleet, haulage and cross-border transport management |
| Operator | One owner-operated trucking business, Botswana, operating across SADC |
| Fleet size at go-live | 21+ trucks, trailers and equipment; staff accounts added later |
| Base currency | BWP (Botswana Pula), multi-currency capture |
| Timezone | Africa/Gaborone (CAT, UTC+2, no DST) |
| Units | kilometres, litres, tonnes, engine hours |
| Stack | Laravel 13 · PHP 8.4 · Livewire 4 · Alpine.js · Tailwind CSS v4 · Vite · `wire:navigate` |
| Installed & verified (2026-10-26) | `laravel/framework` **13.38.0** · `livewire/livewire` **4.4.8** · `laravel/fortify` **1.41.0** · ~~`livewire/flux` **2.21.0** (composer `license: proprietary`)~~ **removed 2026-10-26, sec.20.2** · `laravel/passkeys` **0.2.1** · `laravel/pint` 1.32.1 · local PHP **8.4.27** with `bcmath` ✅ `intl` ✅ `openssl` ✅ and **`sodium` ❌ `redis` ❌ not loaded** |
| Production | Laravel Cloud (managed MySQL + Valkey + managed queues + scheduled tasks) |
| Document version | 1.1 — 2026-10-26 (amended with Phase −1 verification findings, sec.20.1) |

---

## Table of contents

1. [How to read this document](#1-how-to-read-this-document)
2. [Global non-negotiables](#2-global-non-negotiables)
3. [Module map and dependencies](#3-module-map-and-dependencies)
4. [Entity relationship diagram](#4-entity-relationship-diagram)
5. [Money and currency strategy](#5-money-and-currency-strategy)
6. [Accounting core: the ledger](#6-accounting-core-the-ledger)
7. [Document numbering](#7-document-numbering)
8. [Tenancy model](#8-tenancy-model)
9. [Security and permission matrix](#9-security-and-permission-matrix)
10. [State machines](#10-state-machines)
11. [Domain events between modules](#11-domain-events-between-modules)
12. [Package list with reasons](#12-package-list-with-reasons)
13. [Folder structure](#13-folder-structure)
14. [Cross-cutting foundations](#14-cross-cutting-foundations)
15. [Build order](#15-build-order)
16. [Risks and mitigations](#16-risks-and-mitigations)
17. [Open questions](#17-open-questions)
18. [Traceability: every requirement in the brief](#18-traceability-every-requirement-in-the-brief)
19. [Where I propose a different design than the brief implied](#19-where-i-propose-a-different-design-than-the-brief-implied)
20. [Verification log](#20-verification-log)

Related files: [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) · [ADR-0001 Ledger schema](docs/adr/0001-double-entry-ledger-schema.md) · [ADR-0002 Numbering](docs/adr/0002-gap-free-document-numbering.md) · [ADR-0003 Tenancy](docs/adr/0003-single-tenant-with-company-id-everywhere.md) · [ADR-0004 Money and FX](docs/adr/0004-money-and-multi-currency-frozen-base-amount.md) · [ADR-0005 Laravel Cloud constraints](docs/adr/0005-laravel-cloud-runtime-constraints.md) · [ADR-0006 Driver kiosk tokens](docs/adr/0006-driver-kiosk-tokens-not-driver-accounts.md)

---

## 1. How to read this document

This is an engineering contract, not a pitch. Three conventions make it usable across ten build phases:

- **Column sketches are normative.** Where a table's columns are listed, the type and nullability stated here is what gets migrated. Anything marked `⚠` is a decision that has been thought about and can be argued about, but should not be changed after the phase that creates it ships.
- **Each hard-to-reverse decision has an ADR** (`docs/adr/000N-*.md`) with the alternatives that were rejected. The brief asked me to tell you before these decisions are made: the ledger, invoice numbering and the tenancy model are the three it named, and ADR-0005 and ADR-0006 are two more that the Laravel Cloud and kiosk-token choices added.
- **Section 19 lists where I disagree with the brief.** The brief explicitly invites this. Four of them change the phase order; two change the schema in ways that would be expensive to reverse later.

Section 18 is a traceability table mapping every requirement in the brief to a section here. If a requirement is missing from that table, it was dropped deliberately and should be challenged.

---

## 2. Global non-negotiables

These leak into every module, so they are stated once and referenced everywhere.

| Rule | Decision |
|---|---|
| **Time** | `config/app.timezone = 'UTC'`. Every *business date* is an explicit `DATE` column derived in `Africa/Gaborone` (`Carbon::today('Africa/Gaborone')`). The ledger carries both: `posted_at` (UTC instant) and `posted_on` (local business date) — and **`posted_on` is what periods are keyed on**. Do not set the app timezone to Gaborone and let `now()` write local literals: mixing that with UTC GPS payloads and a future driver app is a class of bug you find only in reconciliation. |
| **Money** | Integer minor units. Never `decimal` for money, never a float, ever. `brick/money` behind a `MinorUnits` cast pair. Money serialises to JSON as **strings** (`{"amount":"125000","currency":"BWP"}`). |
| **Keys** | `char(26)` **ULID** primary keys for externally-referenced aggregates (trips, jobs, invoices, documents, journal entries, work orders). `unsignedBigInteger` auto-increment for high-volume append-only rows (`gl_journal_lines`, `gps_positions`, `audits`, `stock_movements`). A ULID carries a timestamp, which doubles as the idempotency and ordering key for the future API. FK columns are always type-matched. |
| **Tenancy** | `company_id unsignedBigInteger NOT NULL DEFAULT 1` on **every** business table, as the **leading column of every unique key and hot composite index**. See sec.8. |
| **Mutation** | Every business write goes through an **Action** (`app/Domain/{Module}/Actions/…`): one public method, an immutable DTO in, a model or DTO out. Livewire components and controllers never mass-assign and never contain business rules. This is what makes the future Sanctum API and driver app a thin layer instead of a rewrite. |
| **Deletes** | Soft delete only for master data with no postings. Transactional records move to `void` / `cancelled` / `archived` through a state machine. **The ledger has no delete path at all.** |
| **Reads** | No query in a Blade view. Eloquent relations are eager-loaded at the Action or table-query level; `preventLazyLoading()` is on outside production. |
| **IDs in UI** | Never expose an auto-increment as a document reference. The visible reference is the `number` (sec.7); the ULID is for links only. |
| **Nothing invented** | No fabricated business data in production code or UI copy. Demo data exists only in clearly labelled seeders (`Database\Seeders\Demo\*`) behind `--demo`, separable from production setup. |

---

## 3. Module map and dependencies

Twelve business modules on top of one shared **Kernel**. The Kernel is the only thing that may be depended upon by everyone and depends on nobody.

```mermaid
graph TD
    subgraph UI["Livewire 4 components (thin) + API controllers"]
        LWC[resources/views/components/*]
        API[app/Http/Controllers/Api/V1/*]
    end

    subgraph MODULES["Business modules"]
        OPS[Operations<br/>jobs · trips · dispatch · POD]
        FLEET[Fleet<br/>trucks · trailers · assignments]
        FUEL[Fuel<br/>tanks · purchases · issues · variance]
        MAINT[Maintenance<br/>schedules · work orders · tyres · parts]
        INV[Invoicing<br/>invoices · payments · credit notes]
        ACC[Accounting<br/>bills · bank · loans · periods · statements]
        PEOPLE[People<br/>drivers · employees · settlements]
        XB[CrossBorder<br/>crossings · permits · agents · weighbridge]
        TEL[Telematics<br/>providers · positions · geofences · alerts]
        REP[Reporting<br/>definitions · exports · scheduled sends]
    end

    subgraph KERNEL["Shared kernel — may not import a business module"]
        LEDGER[Ledger]
        NUM[Numbering]
        MONEY[Money &amp; FX]
        DOCS[Documents]
        AUDIT[Audit]
        TEN[Company scope]
        STATE[State machines]
    end

    LWC --> OPS
    API --> OPS
    OPS --> FLEET
    OPS --> PEOPLE
    OPS --> XB
    OPS --> INV
    FUEL --> OPS
    MAINT --> FLEET
    INV --> ACC
    ACC --> XB
    ACC --> PEOPLE
    TEL --> OPS
    REP --> MODULES
    REP --> LEDGER
    MODULES --> LEDGER
    MODULES --> NUM
    MODULES --> MONEY
    MODULES --> DOCS
    MODULES --> STATE
    LEDGER --> MONEY
    LEDGER --> NUM
    LEDGER --> TEN
```

### 3.1 Dependency rules (enforced, not aspirational)

| # | Rule | Why |
|---|---|---|
| D1 | `UI → component → Action → Model`. A Livewire component's write path is exactly one line: build a DTO, call the Action. | Keeps the API and a future driver app thin. |
| D2 | `Kernel` imports no `Domain/*` module. | Prevents the shared layer becoming a god object. |
| D3 | A module never posts to the ledger except through `LedgerService::post(PostingDto)`. | One place to enforce balance, period locks and idempotency. |
| D4 | Cross-module **notification** uses domain events. Cross-module **invariants** are direct synchronous calls. | Never make an invariant asynchronous for architectural purity — a dispatch conflict check that runs later is not a check. |
| D5 | A module's *model* is not queried from another module's UI. Use that module's read model/DTO or an explicit relation declared in one place. | Stops the module map dissolving into a graph of accidental joins. |
| D6 | Money never exists as a `decimal`/`float` column, and never without a currency code beside it. | sec.5. |

Enforcement without a test suite: a `composer lint:architecture` script greps `use App\Domain` statements and fails on a Kernel→module or module→module-model import violation. This is deliberately dumb, cheap and machine-checkable (see sec.14.6).

### 3.2 Module → screen map

| Module | Primary screens (all list → filter → detail → create/edit per the design system) |
|---|---|
| Dashboard | Executive KPI cards, action-first ordering, date-range and comparison filters, drill-down to source records |
| Fleet | Fleet list, vehicle detail with timeline, assignment history, odometer/hours log |
| Operations | Jobs board, dispatch board (drag-drop day × vehicle grid), trip detail, POD capture, trip costs |
| Fuel | Fuel log per truck, purchase entry, issue/receipt, consumption analysis, bulk tank stock |
| Maintenance | Service schedule due list, work order detail, tyres, parts inventory, inspections, breakdown register |
| Invoicing | Invoice list/draft builder, consolidated billing run, payments & allocation, credit notes, ageing, statements |
| Accounting | Journal browser, chart of accounts, expenses, bills, bank & cash accounts, loans, period close, trial balance, P&L, balance sheet, cash flow, opening balances (cutover) |
| People | Driver list/detail, employee register, licences & permits expiring, salary & allowance setup, advances, trip settlement |
| Cross-border | Crossing register, border cost capture, permits, weighbridge tickets, agent floats & reconciliation, per-country summary |
| Telematics | Live map, trip playback, planned vs actual, geofence setup, alerts |
| Documents | Central document store, per-entity document tabs, expiry dashboard |
| Reporting | Report library, export queue, scheduled report setup |
| Security | Users & roles, invitations, MFA settings, sessions/devices, audit trail, re-auth gates, settings |

---

## 4. Entity relationship diagram

Three diagrams: the operational hub (the trip), the financial spine, and the fleet/fuel/maintenance cluster. Column sketches for the money tables are in sec.5–sec.7; the tables here show keys and the columns that define relationships. `UK` = unique key, `FK` = foreign key, `IDX` = indexed.

### 4.1 The trip as the hub

```mermaid
erDiagram
    COMPANY ||--o{ USER : employs
    COMPANY ||--o{ CUSTOMER : bills
    COMPANY ||--o{ TRUCK : owns
    CUSTOMER ||--o{ JOB : requests
    ROUTE ||--o{ ROUTE_RATE : priced_by
    JOB ||--o{ TRIP : executed_as
    JOB }o--|| CUSTOMER : for
    JOB }o--|| ROUTE : on
    TRUCK ||--o{ TRIP : driven_by_truck
    TRAILER }o--o{ TRIP : hauled_with
    DRIVER ||--o{ TRIP : driven_by
    TRIP ||--o{ LOAD : carries
    TRIP ||--o{ PROOF_OF_DELIVERY : evidenced_by
    TRIP ||--o{ TRIP_COST : accumulated_in
    TRIP ||--o{ BORDER_CROSSING : crosses
    TRIP ||--o{ GPS_POSITION : traced_by
    TRIP ||--o{ CHARGEABLE : billed_as
    CHARGEABLE }o--o{ INVOICE_LINE : consolidated_into
    TRIP ||--o{ TRIP_SETTLEMENT : reconciled_by
    TRIP ||--o{ TRIP_ACCESS_TOKEN : captured_via

    COMPANY {
        bigint id PK
        string name
        char3 base_currency_code "BWP"
        string timezone "Africa/Gaborone"
        date fiscal_year_starts_on
        string vat_number
    }
    CUSTOMER {
        bigint id PK
        bigint company_id FK
        string name UK
        string contact_email
        string payment_terms "net_30"
        bool active
    }
    ROUTE {
        bigint id PK
        bigint company_id FK
        string code UK "GBE-WDH-JNB"
        decimal distance_km
        string origin_country_code
        string destination_country_code
    }
    ROUTE_RATE {
        bigint id PK
        bigint company_id FK
        bigint route_id FK
        bigint customer_id FK
        string rate_type "per_tonne|per_trip|per_km"
        bigint rate_minor_units
        char3 currency_code
        date valid_from
        date valid_to
    }
    JOB {
        char26 id PK
        bigint company_id FK
        string job_no UK
        bigint customer_id FK
        bigint route_id FK
        string status "lifecycle enum"
        string rate_type
        bigint quoted_amount
        char3 currency_code
        bigint base_amount "authoritative BWP"
        date required_load_on
        date required_delivery_on
        string terms_json
    }
    TRIP {
        char26 id PK
        bigint company_id FK
        string trip_no UK
        char26 job_id FK
        bigint truck_id FK
        bigint trailer_id FK
        bigint driver_id FK
        bigint employee_id FK "supercargo, optional"
        string status "trip state machine"
        string previous_state
        datetime state_changed_at
        string billing_state "derived: not_billable|pending_charge|ready|partially_billed|fully_billed"
        string settlement_state "derived: open|partial|settled"
        datetime planned_departure_at
        datetime departed_at
        datetime arrived_at
        datetime completed_at
        bigint odometer_start_km
        bigint odometer_end_km
        bigint engine_hours_start
        bigint route_id FK
        bigint revenue_base "rollup"
        bigint fuel_cost_base "rollup"
        bigint maintenance_cost_base "rollup"
        bigint border_cost_base "rollup"
        bigint driver_cost_base "rollup"
        bigint other_direct_cost_base "rollup"
        bigint direct_cost_base "rollup"
        bigint contribution_base "rollup"
        datetime ledger_posted_at
        bool costs_frozen
        string exception_flags_json
    }
    LOAD {
        bigint id PK
        bigint company_id FK
        char26 trip_id FK
        string commodity
        bigint tonnes
        bigint cubic_meters
        string consignee
        string origin
        string destination
        bigint waybill_document_id FK
    }
    PROOF_OF_DELIVERY {
        char26 id PK
        bigint company_id FK
        char26 trip_id FK
        bigint load_id FK
        string status "pending|submitted|approved|rejected"
        datetime delivered_at
        string receiver_name
        string signature_document_id FK
        bigint quantity_received_tonnes
        string exceptions_json
        string client_uuid UK "offline dedupe"
    }
    TRIP_COST {
        bigint id PK
        bigint company_id FK
        char26 trip_id FK
        string cost_type "fuel|toll|border|lodging|advance|labour|parts|other"
        bigint amount
        char3 currency_code
        decimal fx_rate_19_8
        date fx_rate_date
        bigint base_amount
        char26 source_id "ULID of originating record"
        date occurred_on
    }
    TRIP_ACCESS_TOKEN {
        char26 id PK
        bigint company_id FK
        char26 trip_id FK
        bigint driver_id FK
        string token_hash UK
        datetime expires_at
        string scopes "pod|odometer|fuel|expense"
        bool revoked
        datetime last_used_at
        string last_ip
    }
```

**The trip is the hub of the whole system.** A trip links the job, customer, route, truck, trailer, driver, loads, costs, fuel issues, border crossings, documents, GPS track, invoice lines and settlement. Trip profitability is the sum of its revenue and cost ledger lines (sec.6.6). Two structural rules here, both explained in sec.19:

- A **job is not a trip**: `Job (commercial: customer, rate, terms) → trips/legs (vehicle + driver + dates) → POD per leg → chargeables → invoice per customer per billing run`. Multi-leg and return loads are additional trips against the same job, not a self-referencing trip.
- **`invoiced` is not a trip state.** One job can produce several invoices (freight, detention, accessories, recharge of customs) and one invoice commonly bundles many trips (a monthly statement per customer). Billing status is therefore *derived* (`billing_state`) from `chargeables`/`invoice_lines`, never stored as a trip status.

### 4.2 The financial spine

```mermaid
erDiagram
    GL_ACCOUNT ||--o{ GL_ACCOUNT : parent_rollup
    GL_ACCOUNT ||--o{ GL_JOURNAL_LINE : posted_to
    GL_PERIOD ||--o{ GL_JOURNAL_ENTRY : belongs_to
    GL_JOURNAL_ENTRY ||--|{ GL_JOURNAL_LINE : balances_with
    NUMBER_SEQUENCE ||--o{ INVOICE : allocates
    NUMBER_SEQUENCE ||--o{ GL_JOURNAL_ENTRY : allocates
    INVOICE ||--o{ INVOICE_LINE : itemised_by
    INVOICE_LINE }o--o| CHARGEABLE : sourced_from
    INVOICE ||--o{ PAYMENT_ALLOCATION : settled_by
    PAYMENT ||--o{ PAYMENT_ALLOCATION : applied_through
    INVOICE ||--o{ CREDIT_NOTE : corrected_by
    INVOICE ||--o{ STATEMENT_LINE : presented_in
    LOAN ||--o{ LOAN_INSTALLMENT : amortises_as
    BILL ||--o{ BILL_LINE : itemised_by
    BANK_ACCOUNT ||--o{ GL_JOURNAL_LINE : moves
    CUSTOMER ||--o{ INVOICE : billed
    SUPPLIER ||--o{ BILL : invoices

    GL_ACCOUNT {
        bigint id PK
        bigint company_id FK
        string code UK "5100"
        string name
        string type "asset|liability|equity|revenue|expense"
        string normal_balance "debit|credit"
        bigint parent_id FK
        bool is_system
        bool is_postable
        bool is_active
        tinyint allowed_dimension_mask "bit flags: truck|trip|customer|driver|cost_centre"
        bigint default_tax_rate_id FK
        char3 currency_code "restrict bank accounts"
        string external_ref "accountant/Xero code"
    }
    GL_JOURNAL_ENTRY {
        char26 id PK
        bigint company_id FK
        string entry_no UK "JE-2026-000412"
        datetime posted_at "UTC instant"
        date posted_on "business date - period key"
        bigint period_id FK
        string status "draft|posted|void"
        string description
        char3 currency_code
        decimal fx_rate_19_8
        string fx_rate_source
        date fx_rate_date
        bigint total_debit "base minor units"
        bigint total_credit "base minor units"
        smallint line_count
        string source_type "logical key, not table"
        char26 source_id
        char26 source_event_id
        char26 reverses_entry_id FK
        char26 netting_group
        bigint created_by FK
        datetime voided_at
        string void_reason
    }
    GL_JOURNAL_LINE {
        bigint id PK
        bigint company_id FK
        char26 entry_id FK
        bigint account_id FK
        string direction "debit|credit"
        bigint amount_foreign
        bigint amount_base "authoritative"
        date posted_on "denormalised for single-table reporting"
        char3 currency_code
        string line_note
        bigint truck_id FK
        bigint trailer_id FK
        char26 trip_id FK
        char26 job_id FK
        bigint customer_id FK
        bigint supplier_id FK
        bigint driver_id FK
        bigint employee_id FK
        char26 work_order_id FK
        char26 invoice_id FK
        char26 bill_id FK
        char26 fuel_issue_id FK
        char26 border_crossing_id FK
        char2 country_code
        bigint cost_centre_id FK
        json dimension_extra "long tail: permit_id, tyre_id, route_id"
        datetime created_at
    }
    GL_PERIOD {
        bigint id PK
        bigint company_id FK
        string period UK "2026-03"
        date starts_on
        date ends_on
        string status "open|closed|locked"
        datetime closed_at
        bigint closed_by FK
    }
    INVOICE {
        char26 id PK
        bigint company_id FK
        string number UK "nullable until issued"
        bigint customer_id FK
        string status "draft|issued|void"
        date issued_on
        date due_on
        char3 currency_code
        decimal fx_rate_19_8
        string fx_rate_source
        date fx_rate_date
        bigint base_amount
        bigint vat_amount_base
        bigint balance_due_base "maintained by allocation events"
        string settlement_state "derived: open|partial|paid|refunded|written_off"
        string delivery_state "not_sent|pdf_generated|emailed|failed"
        string terms_note
        bigint creator_id FK
        datetime voided_at
        string void_reason
    }
    INVOICE_LINE {
        bigint id PK
        bigint company_id FK
        char26 invoice_id FK
        char26 chargeable_id FK
        char26 trip_id FK
        string description
        bigint quantity
        string unit "tonne|trip|km|hour|item"
        bigint unit_rate
        bigint line_amount
        char3 currency_code
        bigint base_amount
        bigint tax_rate_id FK
        bigint tax_amount_base
    }
    CHARGEABLE {
        bigint id PK
        bigint company_id FK
        char26 trip_id FK
        char26 job_id FK
        bigint customer_id FK
        string type "freight|detention|accessories|customs_recharge|fuel_recharge|other"
        string basis "per_tonne|per_trip|per_km|fixed"
        bigint amount
        char3 currency_code
        bigint base_amount
        string status "pending|ready|billed|written_off"
        string source_uuid UK "idempotent generation from POD"
    }
    PAYMENT {
        char26 id PK
        bigint company_id FK
        string receipt_no UK
        bigint customer_id FK
        date paid_on
        bigint bank_account_id FK
        bigint amount
        char3 currency_code
        decimal fx_rate_19_8
        string fx_rate_source
        bigint base_amount
        string method "cash|eft|mobile|card|debit_order"
        string reference
    }
    PAYMENT_ALLOCATION {
        bigint id PK
        bigint company_id FK
        char26 payment_id FK
        char26 invoice_id FK
        bigint amount_base
        bigint fx_gain_loss_base
        bigint allocated_by FK
        datetime allocated_at
        bigint reverse_of_id FK
        string reason
    }
    CREDIT_NOTE {
        char26 id PK
        bigint company_id FK
        string number UK
        char26 invoice_id FK
        bigint customer_id FK
        string status "draft|issued|applied|settled|cancelled"
        date issued_on
        bigint amount
        char3 currency_code
        bigint base_amount
        string reason_code
        string narrative
    }
    LOAN {
        char26 id PK
        bigint company_id FK
        bigint supplier_id FK "lender"
        string reference UK
        string purpose "vehicle|equipment|working_capital"
        bigint truck_id FK
        bigint principal
        char3 currency_code
        bigint base_principal
        decimal interest_rate_19_8
        string frequency "monthly|fortnightly"
        date first_payment_on
        bigint bank_account_id FK
        string status "loan state machine"
    }
    LOAN_INSTALLMENT {
        bigint id PK
        bigint company_id FK
        char26 loan_id FK
        smallint seq
        date due_on
        bigint principal_due
        bigint interest_due
        bigint total_due
        bigint paid_principal
        bigint paid_interest
        string status "scheduled|partial|paid|overdue|restructured"
        datetime paid_at
    }
    BILL {
        char26 id PK
        bigint company_id FK
        bigint supplier_id FK
        string supplier_doc_no
        string status "draft|recorded|partially_paid|paid|void"
        date bill_on
        date due_on
        bigint amount
        char3 currency_code
        decimal fx_rate_19_8
        bigint base_amount
        char26 source_work_order_id FK
        string category "fuel|parts|labour|insurance|lease|other"
    }
    BANK_ACCOUNT {
        bigint id PK
        bigint company_id FK
        string name
        string institution
        string number_masked "readable, never decrypted in lists"
        text number_encrypted
        string key_version
        char3 currency_code
        bigint account_id FK "gl_accounts 1010-ish"
        string type "bank|cash|wallet"
        bool active
    }
    STATEMENT_LINE {
        bigint id PK
        bigint company_id FK
        bigint customer_id FK
        date statement_on
        string document_type "invoice|payment|credit_note|opening"
        char26 document_id
        bigint amount_base
        bigint running_balance_base
    }
```

### 4.3 Fleet, fuel and maintenance cluster

```mermaid
erDiagram
    TRUCK ||--o{ FUEL_TANK : has
    TRUCK ||--o{ VEHICLE_ASSIGNMENT : assigned_to
    TRUCK ||--o{ SERVICE_SCHEDULE : serviced_by
    TRUCK ||--o{ TYRE : runs_on
    TRUCK ||--o{ ODOMETER_READING : measured_by
    TRAILER ||--o{ VEHICLE_ASSIGNMENT : assigned_to
    DRIVER ||--o{ LICENCE_ENDORSEMENT : holds
    DRIVER ||--o{ VEHICLE_ASSIGNMENT : assigned
    FUEL_PURCHASE ||--o{ FUEL_ISSUE : dispenses
    FUEL_TANK ||--o{ FUEL_ISSUE : fills
    FUEL_ISSUE ||--o| FUEL_VARIANCE : analysed_as
    SERVICE_SCHEDULE ||--o{ WORK_ORDER : generates
    WORK_ORDER ||--o{ WORK_ORDER_LINE : costs
    WORK_ORDER ||--o{ PART_ISSUE : consumes
    PART ||--o{ STOCK_MOVEMENT : moved_as
    PART ||--o{ PART_ISSUE : issued_as
    TYRE ||--o{ TYRE_POSITION : fitted_as
    WORK_ORDER ||--o{ INSPECTION : checked_by
    TRUCK ||--o{ BREAKDOWN : suffers
    DOCUMENT ||--o{ DOCUMENT_VERSION : versioned_as

    TRUCK {
        bigint id PK
        bigint company_id FK
        string registration_no UK "BWD1234K"
        string fleet_no UK "T-07"
        string vin UK
        string make
        string model
        string year
        string status "available|on_trip|in_workshop|out_of_service|sold"
        bigint carrying_capacity_tonnes
        bigint odometer_km
        decimal engine_hours_10_2
        bigint purchase_amount_base
        date purchased_on
        char26 loan_id FK "financing link"
        bigint insurance_document_id FK
        bigint tyre_size
        smallint service_interval_km
        smallint service_interval_hours
        smallint service_interval_months
        string fuel_type "diesel"
        smallint main_tank_litres
        smallint aux_tank_litres
        decimal expected_consumption_per_100 "litres"
        string compliance_flags_json
        bool active
    }
    TRAILER {
        bigint id PK
        bigint company_id FK
        string registration_no UK
        string fleet_no UK
        string vin UK
        string type "flatbed|tipper|curtainsider|tanker|lowboy"
        decimal capacity_tonnes_10_2
        string status
        bigint odometer_km
        string axle_config
        bool active
    }
    EQUIPMENT {
        bigint id PK
        bigint company_id FK
        string code UK
        string kind "loader|generator|toolbox"
        string status
        bigint purchase_amount_base
    }
    VEHICLE_ASSIGNMENT {
        bigint id PK
        bigint company_id FK
        bigint truck_id FK
        bigint trailer_id FK
        bigint driver_id FK
        char26 trip_id FK
        datetime from_at
        datetime to_at
        string assigned_by FK "history is mandatory per brief"
        string reason
    }
    ODOMETER_READING {
        bigint id PK
        bigint company_id FK
        bigint truck_id FK
        char26 trip_id FK
        bigint odometer_km
        decimal engine_hours_10_2
        date read_on
        string source "trip_departure|trip_arrival|fuel_issue|manual|kiosk"
        string plausibility "ok|suspect|disputed"
        bigint recorded_by FK
    }
    DRIVER {
        bigint id PK
        bigint company_id FK
        bigint employee_id FK
        string licence_no_masked
        text licence_no_encrypted
        string key_version
        string licence_code "C1|EC"
        date licence_expires_on
        date permit_expires_on "PDP"
        bigint default_allowance_rate_base
        decimal fuel_efficiency_baseline_10_2
        string status "active|standby|suspended|left"
        bool kiosk_enabled
    }
    EMPLOYEE {
        bigint id PK
        bigint company_id FK
        string employee_no UK
        string name
        string national_id_masked
        text national_id_encrypted
        string role_or_grade
        date hired_on
        date left_on
        bigint gross_salary_base "input to accounts, not statutory payroll"
        string pay_frequency "monthly|fortnightly"
        bool active
    }
    FUEL_TANK {
        bigint id PK
        bigint company_id FK
        bigint truck_id FK
        string kind "main|auxiliary"
        smallint capacity_litres
        bool active
        varchar composite_uk UK "(company_id, truck_id, kind)"
    }
    FUEL_PURCHASE {
        char26 id PK
        bigint company_id FK
        bigint filling_station_id FK
        bigint fuel_card_id FK
        char3 currency_code
        bigint amount
        decimal fx_rate_19_8
        string fx_rate_source
        date fx_rate_date
        bigint base_amount
        date purchased_on
        bigint litres "x100 = 2 dp"
        bigint price_per_litre_minor "x100"
        string invoice_no
        bool posted_to_ledger
        string source_uuid UK
    }
    FUEL_ISSUE {
        char26 id PK
        bigint company_id FK
        bigint truck_id FK
        bigint fuel_tank_id FK
        char26 trip_id FK
        bigint litres_x100
        bigint odometer_km
        char26 purchase_id FK "null = bulk tank issue"
        bigint cost_base "derived from purchase or bulk average"
        string source "pump_card|bulk_tank|advance|kiosk"
        bigint recorded_by FK
        string status "recorded|posted|reversed"
        string exception_reason
    }
    FUEL_VARIANCE {
        bigint id PK
        bigint company_id FK
        bigint truck_id FK
        char26 trip_id FK
        decimal litres_per_100_10_2
        decimal expected_per_100_10_2
        decimal variance_pct_10_2
        decimal tonnes_carried_10_2
        string state "open|acknowledged|disputed|resolved"
        string acknowledgement_note
        datetime detected_at
    }
    BULK_TANK {
        bigint id PK
        bigint company_id FK
        string name
        smallint capacity_litres
        bigint stock_litres_x100
        bigint average_cost_per_litre_minor
    }
    SERVICE_SCHEDULE {
        bigint id PK
        bigint company_id FK
        bigint truck_id FK
        string kind "km|hours|date|condition"
        smallint interval_value
        bigint last_done_at_value
        date last_done_on
        date next_due_on
        bigint next_due_at_value
        string state "ok|due_soon|overdue|blocked"
        string checklist_document_id FK
    }
    WORK_ORDER {
        char26 id PK
        bigint company_id FK
        string wo_no UK
        bigint truck_id FK
        bigint trailer_id FK
        char26 trip_id FK "breakdown on trip"
        string status "work order state machine"
        string kind "service|repair|breakdown|tyre|inspection|capital"
        decimal estimated_hours_10_2
        bigint labour_cost_base
        bigint parts_cost_base
        bigint external_cost_base
        bigint total_cost_base
        bigint approval_required_amount_base
        bigint approved_by FK
        bigint mechanic_employee_id FK
        bigint supplier_id FK "external garage"
        bool capitalised
        bigint capitalised_amount_base
        datetime closed_at
        string hold_reason "awaiting_parts|external_garage|awaiting_owner_approval|vehicle_needed_on_trip"
    }
    WORK_ORDER_LINE {
        bigint id PK
        bigint company_id FK
        char26 work_order_id FK
        string kind "labour|part|external|consumable"
        string description
        bigint quantity_x100
        bigint unit_cost_minor
        char3 currency_code
        bigint base_amount
        bigint part_id FK
        bool capital_candidate
        string posted_marker "idempotency: when this line posted"
    }
    PART {
        bigint id PK
        bigint company_id FK
        string sku UK
        string name
        string category
        bigint unit_cost_minor
        char3 currency_code
        smallint reorder_level
        smallint stock_quantity "only if perpetual stores chosen - Q5"
        bigint truck_id FK "specific part"
    }
    STOCK_MOVEMENT {
        bigint id PK
        bigint company_id FK
        bigint part_id FK
        string kind "receipt|issue|adjustment|return|count"
        smallint quantity
        smallint balance_after
        char26 reference_id "work order / bill"
        bigint unit_cost_minor
        datetime moved_at
        bigint moved_by FK
    }
    TYRE {
        bigint id PK
        bigint company_id FK
        string serial UK
        string brand
        string size
        bigint purchase_cost_base
        decimal tread_depth_new_mm_10_2
        string status "in_service|rotating|retread_due|scrap|on_spares"
    }
    TYRE_POSITION {
        bigint id PK
        bigint company_id FK
        bigint tyre_id FK
        bigint truck_id FK
        string position "FL|FR|RL1..."
        datetime fitted_at
        bigint fitted_odometer_km
        datetime removed_at
        bigint removed_odometer_km
        smallint retread_count
        decimal cost_per_km_10_4
    }
    INSPECTION {
        char26 id PK
        bigint company_id FK
        string kind "pre_trip|periodic|tacho"
        bigint truck_id FK
        char26 trip_id FK
        bigint driver_id FK
        datetime done_at
        string result "pass|fail|advisory"
        json checklist_answers
        string photo_document_ids_json
        string defects_found_json
    }
    BREAKDOWN {
        char26 id PK
        bigint company_id FK
        bigint truck_id FK
        char26 trip_id FK
        datetime occurred_at
        string location
        string description
        smallint hours_lost
        char26 work_order_id FK
        string cause "mechanical|driver|road|load|other"
        bool recoverable_from_driver
    }
    DOCUMENT {
        char26 id PK
        bigint company_id FK
        string type "contract|licence|insurance|permit|delivery_note|pod|customs|work_order|maintenance|weighbridge"
        string morph_type "nullable, polymorphic to any entity"
        char26 morph_id
        string title
        date expires_on
        string lifecycle "draft|current|superseded|archived|voided"
        string retention_class "tax|legal|operational|none"
        string visibility "role keys"
        bool confidential
        datetime archived_at
    }
    DOCUMENT_VERSION {
        char26 id PK
        bigint company_id FK
        char26 document_id FK
        smallint version_seq
        string disk "documents_private"
        string path
        string mime
        unsignedbigint size_bytes
        string sha256
        bool is_current
        datetime uploaded_at
        bigint uploaded_by FK
        string virus_scan_status "pending|clean|infected"
        string is_current_flag "STORED generated col, UNIQUE for one-current-version"
        string replaced_reason
    }
```

### 4.4 Supporting and security tables

```mermaid
erDiagram
    USER ||--o{ USER_ROLE : has
    USER ||--o{ USER_SESSION : signs_in_with
    USER ||--o{ AUDIT : acts_in
    AUDIT ||--o{ AUDIT_DIFF : changes
    NUMBER_SEQUENCE ||--o{ INVOICE : allocates
    BORDER_CROSSING ||--o{ BORDER_COST : incurs
    AGENT_CASH_ADVANCE ||--o{ AGENT_RECONCILIATION : cleared_by
    GEOFENCE ||--o{ ALERT : triggers
    BORDER_POST ||--o{ BORDER_CROSSING : at_post
    CURRENCY ||--o{ EXCHANGE_RATE : quoted_as
    GPS_PROVIDER_ACCOUNT ||--o{ GPS_POSITION : feeds
    WEIGHBRIDGE_TICKET ||--o| BORDER_CROSSING : evidences
    PERMIT }o--|| DOCUMENT : evidenced_by
    ALERT }o--|| USER : notified_to

    NUMBER_SEQUENCE {
        bigint id PK
        bigint company_id FK
        string scope "invoice|credit_note|receipt|journal_entry|trip|work_order|po|vat_return"
        string period_key "2026 or FY26-27 or empty=continuous"
        bigint next_value
        string format "INV-{PERIOD}-{SEQ:7}"
        datetime last_used_at
        bigint last_used_by FK
        varchar composite_uk UK "(company_id, scope, period_key)"
    }
    AUDIT {
        bigint id PK
        bigint company_id FK
        string auditable_type
        char26 auditable_id
        string event "created|updated|deleted|state_changed|login|permission_change|export|document_downloaded"
        bigint user_id FK
        string user_name_snapshot
        string ip_address
        string user_agent
        json old_values
        json new_values
        datetime created_at
        string tags
        string reason
    }
    SECURITY_EVENT {
        bigint id PK
        bigint company_id FK
        bigint user_id FK
        string kind "mfa_enabled|password_changed|reauth_ok|reauth_fail|session_revoked|invite_accepted|kiosk_token_issued"
        string ip_address
        datetime happened_at
        json meta
    }
    OPERATIONAL_EVENT {
        bigint id PK
        bigint company_id FK
        string aggregate_type
        char26 aggregate_id
        string from_state
        string to_state
        bigint actor_id FK
        string actor_kind "user|kiosk|system|scheduler"
        datetime occurred_at
        string reason "mandatory for destructive transitions"
        json context
    }
    USER_SESSION {
        string id PK
        bigint user_id FK
        string ip_address
        string user_agent
        datetime last_seen_at
        datetime expires_at
        bool two_factor_ok
    }
    FILLING_STATION {
        bigint id PK
        bigint company_id FK
        string name
        string country_code
        decimal lat_10_7
        decimal lng_10_7
        bool active
    }
    FUEL_CARD {
        bigint id PK
        bigint company_id FK
        string card_no_masked
        text card_no_encrypted
        bigint supplier_id FK
        bigint credit_limit_base
        bool active
    }
    BORDER_POST {
        bigint id PK
        bigint company_id FK
        string code UK "TBR|KBN|MSU|WB|SD"
        string name
        string country_code
        decimal lat_10_7
        decimal lng_10_7
        datetime open_from
        datetime open_to
        string notes
    }
    BORDER_CROSSING {
        char26 id PK
        bigint company_id FK
        char26 trip_id FK
        bigint border_post_id FK
        string country_code
        string direction "entry|exit"
        datetime entered_at
        datetime exited_at
        string status "planned|entered|exited|costs_captured|agent_reconciled|closed"
        string transit_doc_no
        bigint weighbridge_ticket_id FK
        string clearance_state
    }
    BORDER_COST {
        bigint id PK
        bigint company_id FK
        char26 border_crossing_id FK
        string kind "duty|clearing_agent|permit|road_user_fee|transit_bond|scanning|other"
        bigint amount
        char3 currency_code
        decimal fx_rate_19_8
        string fx_rate_source
        date fx_rate_date
        bigint base_amount
        bool recoverable_input_tax
        bigint tax_rate_id FK
        char26 receipt_document_id FK
        string payable_to_agent_id FK
        string posted_marker
    }
    PERMIT {
        bigint id PK
        bigint company_id FK
        string kind "transit|road_user|cabotage|fuel_tax|corridor"
        string country_code
        string number_masked
        char26 document_id FK
        date valid_from
        date valid_to
        string holder_type "truck|trailer|company|driver"
        bigint holder_id
    }
    WEIGHBRIDGE_TICKET {
        bigint id PK
        bigint company_id FK
        char26 trip_id FK
        bigint load_id FK
        datetime weighed_at
        string operator
        decimal gross_tonnes_10_3
        decimal tare_tonnes_10_3
        decimal net_tonnes_10_3
        decimal permitted_tonnes_10_3
        bool overloaded
        bigint fine_amount_base
        string ticket_no UK
        char26 document_id FK
    }
    AGENT_CASH_ADVANCE {
        char26 id PK
        bigint company_id FK
        bigint supplier_id FK "clearing agent"
        char26 trip_id FK
        date advanced_on
        bigint amount
        char3 currency_code
        decimal fx_rate_19_8
        bigint base_amount
        bigint spent_base "sum of matched costs"
        bigint variance_base
        string reconciliation_state "unreconciled|partial|reconciled|disputed"
        char26 reconciliation_id FK
    }
    AGENT_RECONCILIATION {
        char26 id PK
        bigint company_id FK
        char26 advance_id FK
        json matched_cost_ids
        bigint variance_base
        string status "draft|confirmed|owner_override"
        bigint confirmed_by FK
        string reason
        datetime confirmed_at
    }
    EXCHANGE_RATE {
        bigint id PK
        bigint company_id FK
        char3 currency_code FK
        date rate_date
        decimal rate_to_base_19_8 "1 foreign = X base"
        string source "manual|bpb|rbg|bank_statement|contract"
        string ref
        datetime entered_at
        varchar composite_uk UK "(company_id, currency_code, rate_date, source)"
    }
    CURRENCY {
        char3 code PK "BWP|ZAR|USD|ZMW|MZN|BRL"
        string name
        smallint minor_units "2 for BWP/ZAR/USD, 0 for JPY"
        string rounding_mode
        string symbol
        bool is_active
    }
    GPS_PROVIDER_ACCOUNT {
        bigint id PK
        bigint company_id FK
        string adapter "simulator|teltonika|gaia|flespi"
        string endpoint
        text api_key_encrypted
        bool active
        datetime last_sync_at
    }
    GPS_POSITION {
        bigint id PK
        bigint company_id FK
        string provider
        string provider_message_id UK "webhook retry dedupe"
        bigint truck_id FK
        char26 trip_id FK
        datetime fixed_at
        decimal lat_10_7
        decimal lng_10_7
        smallint speed_kph
        smallint heading_deg
        bigint odometer_km
        int accuracy_m
        json raw_json
        datetime ingested_at
    }
    GEOFENCE {
        bigint id PK
        bigint company_id FK
        string kind "depot|border|customer_site|country|corridor"
        bigint reference_id "polymorphic-ish: post/customer/truck"
        decimal lat_10_7
        decimal lng_10_7
        int radius_m
        string polygon_json
        bool active
    }
    ALERT {
        bigint id PK
        bigint company_id FK
        string type "doc_expiry|service_due|overdue_invoice|fuel_variance|geofence|route_deviation|loan_due|cash_shortage|permit_expiry|posting_failure|self_check_failure"
        string severity "info|warning|critical"
        string subject_type "nullable, with subject_id as a pair"
        char26 subject_id "nullable"
        date occurred_on
        string cooldown_key "type+subject+bucket, unique per company"
        json payload
        string state "open|acknowledged|resolved|muted"
        datetime first_seen_at
        datetime last_seen_at
        tinyint notify_count "deliveries, not sightings"
        datetime acknowledged_at
        bigint acknowledged_by FK
        datetime muted_until
        bigint muted_by FK
        string mute_reason
        datetime resolved_at
        bigint resolved_by FK "null when the scheduler closed it"
        string resolved_by_kind "user|scheduler"
        string resolve_reason
    }
    NOTIFICATION_PREFERENCE {
        bigint id PK
        bigint company_id FK
        bigint user_id FK
        string type "one of the alert types above"
        string channel "mail|database"
        bool enabled "absent row means deliver"
    }
    REMINDER_THRESHOLD {
        bigint id PK
        bigint company_id FK
        string family "licence|permit|insurance|service|loan|invoice|contract|roadworthiness"
        smallint days_before "60|30|14|7, plus any number the Owner types"
        json recipients "role keys, non-empty array"
        bool active
    }
```

Two names this block did not have before slice 8, each because sec.10 and sec.14.1 contradicted the sketch:

- **`muted_until`, `mute_reason`, `acknowledged_*`** — sec.10 requires a mute to have an expiry and both exits to
  carry a reason; the sketch listed only `resolved_at`. Three dated decisions, because they are made by
  different people and "who dismissed this" has to have an answer.
- **`resolved_by_kind`** — sec.14.6 says a clean sweep closes its own finding, so a resolution can be made by a
  principal that is not a user. `operational_events.actor_kind` solved the identical problem in slice 7; the
  rule is copied rather than reinvented, and the CHECK refuses a `user`-kind resolution with no user.

`reminder_thresholds.family` is deliberately **not** `type`. `alerts.type` is the vocabulary of *findings*
(`doc_expiry`) and `reminder_thresholds` is the vocabulary of *papers* (`licence`, `insurance`, `roadworthiness`)
— one column name for two vocabularies is how a threshold row looks joinable to an alert when it is not.
`cooldown_key` is unique **per company**, not globally: two tenants' `ledger:verify` failure name the same
subject, and a global unique would let the first company's alert swallow the second's silently.

---

## 5. Money and currency strategy

### 5.1 The two rules that never bend

1. **Money is an integer number of minor units plus an ISO 4217 code.** `bigint` columns, never `decimal`, never `float`. `brick/money` (`Money`, `BigDecimal`) behind a `MinorUnits` cast pair so arithmetic never touches a float, and rounding is always explicit.
2. **A money value without a currency is a bug.** Every amount column has a currency code beside it in the same row, or inherits it from an explicit parent (`gl_journal_line.currency_code` copies its entry's and is CHECK-verified).

`ext-bcmath` is required for `brick/money` performance (it suggests bcmath *or* gmp). Verify on both the local PHP 8.4 build and the Laravel Cloud runtime at Phase −1; `php -m | grep -E 'bcmath|gmp'`.

### 5.2 The FX column block

Applied **verbatim** to `invoices`, `invoice_lines`, `credit_notes`, `bills`, `payments`, `payment_allocations`, `fuel_purchases`, `border_costs`, `agent_cash_advances`, `loans`, `loan_installments`, `trip_costs`, `salary_runs` and `gl_journal_entries`:

| Column | Type | Meaning |
|---|---|---|
| `currency_code` | `char(3) NOT NULL` | ISO 4217, uppercase. The currency the counterparty actually sees. |
| `amount` | `unsignedBigInteger NOT NULL` | Minor units **of `currency_code`**. |
| `fx_rate` | `decimal(19,8) NOT NULL` | **Always "1 foreign unit = X base".** Never the inverse. |
| `fx_rate_source` | `string(40) NOT NULL` | `manual \| rbg_daily \| bpb \| contract \| customer_agreed \| bank_statement \| opening_balance \| manual_override` |
| `fx_rate_date` | `date NOT NULL` | The date the rate was struck. |
| `fx_rate_ref` | `string(80) NULL` | Evidence: bulletin date/id, bank statement reference. |
| `base_currency_code` | `char(3) NOT NULL DEFAULT 'BWP'` | Copied from the company at write time, not read from config at read time. |
| `base_amount` | `unsignedBigInteger NOT NULL` | Minor units of BWP. **Authoritative.** |

Why `decimal(19,8)` for a rate when amounts are integers: a rate is not money, it is a dimensionless multiplier, and 8 dp is the precision the ZAR/BWP and ZMW/KWacha corridors need to make a large foreign amount round to a stable base amount. Money columns stay integers; only rates are decimal.

### 5.3 Which number wins

**`base_amount` is authoritative and frozen at write time.** Reports, VAT, P&L, balance sheet and the ledger all read `base_amount`. Nothing ever recomputes a historical amount from a current rate — that silently rewrites history and breaks the trial balance against the ledger.

At write time the invariant is `base_amount == round(amount × fx_rate, minor_units, HALF_UP)`, enforced in the DTO and re-checked by `ledger:verify` (a DB `CHECK` cannot reliably do multiply-and-round across the decimal/integer boundary).

The manual-override rule, decided now because it is otherwise an argument in every payment screen:

| User edits | What recomputes | Bookkeeping |
|---|---|---|
| the **foreign** amount | `base_amount = round(amount × fx_rate)` | unchanged `fx_rate_source` |
| the **base** amount (the bank charged exactly this) | `fx_rate = base_amount / amount` (8 dp, HALF_UP) | `fx_rate_source = 'manual_override'` |

One editor per document type in the UI, so two people never fight about which knob moved.

### 5.4 Rate management

`exchange_rates` is a **reference lookup table only**: used to pre-fill a new document and to warn "the ZAR rate is 4 days stale". It never joins back into historical amounts. Manual entry first; a rate-feed adapter later behind the same `RateResolver` interface (Reserve Bank of Botswana or a bank statement feed), which is why `fx_rate_source` exists as data rather than a hard-coded string.

`currencies` (`code`, `name`, `minor_units`, `rounding_mode`, `symbol`, `is_active`) is a table, not an enum, because this corridor set includes ZAR, USD, ZMW, MZN and occasionally ZWG/BRL, and because a currency's minor-unit count is a fact about the world, not about our code.

### 5.5 Rounding, stated once

| Operation | Rule |
|---|---|
| Document totals | Sum of **rounded line amounts**, never round-of-sum. Lines are computed first, then added, so a customer's invoice always adds up on the page. |
| VAT | Computed per line, rounded HALF_UP to 2 dp of the invoice currency, then summed. |
| Payment allocation | Allocate in FIFO order of invoice age; the **last** allocation absorbs the ±1 minor-unit residue so a paid invoice's `balance_due_base` reaches exactly zero. |
| Fuel: litres and price/litre | Litres stored `× 100` (`bigint`, 2 dp), price per litre stored `× 100`. Cost = litres × price ÷ 10 000, rounded HALF_UP. |
| Consumption per 100 km | Computed, never stored as truth; stored only as an analysis snapshot with its inputs (`litres`, `km`, `tonnes`, `route`) so it can be recomputed and defended. |
| FX | HALF_UP at 8 dp for rates; base amounts HALF_UP to the base currency's minor units. |

Realised FX at payment: the difference between the open item's rate and the payment's rate posts to `7510 Realised FX gain` / `7511 Realised FX loss` as an allocation-driven line. **Unrealised revaluation of foreign-denominated balances at period close is deferred** (phase 5+, opt-in): it is an accountant-driven feature and building it speculatively is how you end up with a balance sheet nobody signs.

---

## 6. Accounting core: the ledger

A lightweight double-entry ledger that operational modules post to through domain events, with dimensions on each line so profitability reports are ordinary queries. Because you chose **full accounting in-app**, this ledger is also the statutory books: chart of accounts, journals, period locking, trial balance, P&L, balance sheet and cash flow all read from it (sec.15 phase 5).

### 6.1 Schema

Full columns are in [sec.4.2](#42-the-financial-spine). The three decisions inside it:

**`gl_accounts` is a tree.** `parent_id` gives rollup sections for the trial balance and P&L without a separate report-definition table; `is_postable` marks header rows; `is_system` marks seeded accounts that cannot be deleted or re-coded; `external_ref` holds your accountant's/Xero's code so an export lines up without a mapping spreadsheet. `allowed_dimension_mask` is a bit flag set (`truck|trip|customer|driver|cost_centre`) that lets the poster assert "this account must carry a truck" — cheap, and it catches the class of posting bug where a fuel line loses its truck and quietly lands in overheads.

**`type` is `string(16)` + backed enum + DB `CHECK`, not a MySQL `ENUM`.** Adding an `ENUM` value is an `ALTER TABLE` with the full value list and enum ordering semantics are a foot-gun; a `CHECK` list is one statement and behaves identically on MySQL 8 and MariaDB.

**`gl_journal_entries` carries FX, `gl_journal_lines` carries only `amount_base`.** A line that could be in a different currency from its entry is a re-design, not a column.

### 6.2 Dimensions: typed columns, not a generic pair

`gl_journal_lines` has nullable FK columns for the dimensions that reports actually group by: `truck_id`, `trailer_id`, `trip_id`, `job_id`, `customer_id`, `supplier_id`, `driver_id`, `employee_id`, `work_order_id`, `invoice_id`, `bill_id`, `fuel_issue_id`, `border_crossing_id`, `country_code`, `cost_centre_id`, plus `dimension_extra json` for the long tail.

Why not `dimension_type` / `dimension_id`:

- A generic pair **cannot hold two dimensions on one line**, and you need truck *and* trip on day one (a fuel line is both "which truck" and "which trip").
- "Profit per truck" becomes a self-join or a `CASE` pivot per dimension — unindexable, unreadable, and you will write it about forty times. Typed columns make it `GROUP BY truck_id` over a composite index.
- The real cost of typed columns is "add a dimension = migration". Mitigated by three things: the `dimension_extra` JSON column, MariaDB/MySQL indexes on `STORED` generated columns (`->storedAs(...)->index()`) so a hot JSON key can be promoted to an indexed column without touching reports, and a written rule: **a dimension earns a column when a report needs it twice.**
- `route_id`, `tyre_id`, `permit_id` deliberately **stay in JSON** for now: `country_code` and `cost_centre_id` are structurally reporting dimensions, those three are not.
- Putting a polymorphic `source_type`/`source_id` on *lines* would kill the indexes above and buy nothing: the source stays on the **entry** (one document → one entry).

### 6.3 Balanced or it does not commit

LAYERED, because you chose no DB-grant control (see below) and no committed test suite:

1. **No update or delete path in code.** `JournalEntry`/`JournalLine` refuse `updating` and `deleting` in `booted()` with `ImmutableLedgerException`, except the single `draft → posted` transition on the entry. The only write path is `LedgerService::post(PostingDto)`.
2. **DB `CHECK` on the entry row:** `status <> 'posted' OR (total_debit = total_credit AND total_debit > 0)` and `status <> 'draft' OR (total_debit = 0 AND total_credit = 0)`. MariaDB enforces CHECK from 10.2.1, MySQL from 8.0.16 — both hold on our engines. A CHECK cannot aggregate across rows, so `total_debit`/`total_credit`/`line_count` are computed by the poster and *verified* by `ledger:verify`, which recomputes them from lines and alarms on drift.
3. **Triggers, which survive a bad migration or a panic edit in a SQL client:**

```sql
CREATE TRIGGER gl_journal_lines_bu BEFORE UPDATE ON gl_journal_lines
FOR EACH ROW
  IF (SELECT COUNT(*) FROM gl_ledger_barrier WHERE id = 1) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fleet Guard: ledger rows are immutable';
  END IF;
-- plus BEFORE DELETE on gl_journal_lines and gl_journal_entries
```

   `gl_ledger_barrier` is a single-row, seeded table: the documented emergency hatch. A maintenance migration inserts the row, performs the fix, deletes the row — and that insert/delete is itself audited. Laravel 13's schema builder has **no `check()` method and no trigger helpers** (verified against the 13.x migrations docs), so both go in migrations as `DB::statement(...)`.
4. **`BEFORE INSERT` period-lock trigger** on `gl_journal_entries` that reads `gl_periods.status` for the resolved `period_id` and `SIGNAL`s when it is `locked`. A CHECK cannot read another table, so this one has to be a trigger.
5. **The layer the Cloud decision removed.** The strongest version of ledger immutability is a DB user granted only `SELECT, INSERT` on `gl_journal_*`, with migrations under a separate credential. **Laravel Cloud does not offer this**: database users are read-only or read-write, the default injected user cannot be edited or deleted, and there is no grant editor. Consequence, stated plainly rather than pretended away: immutability against someone with database credentials becomes an *operational* control — Cloud daily backups with 30-day retention, binary logs retained ~8 days, restore-to-a-new-cluster for forensics, and `ledger:verify` running in the scheduler so drift raises an alert instead of being discovered at year-end. Layers 1–4 still stop every application path and every careless SQL-client edit.

### 6.4 Corrections, reversals and netting

Posted entries are immutable. A correction is a **reversing entry**: `PostReversal` builds a mirrored entry, sets `reverses_entry_id` to the original and `netting_group` to the original's id, and never touches the original. Both entries appear in the journal browser — which is what an accountant wants to see anyway.

Reversals into a locked period post at **today's** `posted_on` with a note referencing the original. Back-dating a reversal silently changes a trial balance you already locked, which defeats the point of locking.

Reports filter `netting_group IS NULL` for "gross as issued" and include both for "net position", so the two numbers an owner and an accountant want are both available and neither is a fudge.

### 6.5 Periods and locking

`gl_periods (company_id, period 'YYYY-MM', starts_on, ends_on, status open|closed|locked, closed_at, closed_by)`, `UNIQUE(company_id, period)`. A period is auto-created with `firstOrCreate` **inside** `PostJournalEntry`, so a missing row can never silently block a posting. `posted_on → period_id` is resolved at write time and stored on the entry, which is what makes the lock trigger a single-row lookup rather than a date range scan.

`period_key` and the fiscal calendar come from the company (`fiscal_year_starts_on`), not from the calendar year, because the VAT/financial-year question (sec.17 Q3) is unresolved and must not become a migration.

### 6.6 Trip profitability without a scan

Three tiers, in this order:

1. A list view or dashboard **never** sums `gl_journal_lines`.
2. **Write-time denormalised rollup on `trips`** (`revenue_base`, `fuel_cost_base`, `maintenance_cost_base`, `border_cost_base`, `driver_cost_base`, `other_direct_cost_base`, `direct_cost_base`, `contribution_base`, `ledger_posted_at`), recalculated **inside the posting transaction** by `TripCostRollup` with a single indexed `SUM(...) WHERE trip_id = ?` over typically fewer than twenty lines. This is not a cache; it is a projection maintained by the poster, and it is the only way the dispatch board and the executive dashboard stay fast.
3. **Real-time SUM is the source of truth** for the trip detail screen and for audits, and `trips:recost` recomputes every rollup and diffs it against the stored value — that diff *is* the reconciliation report.

No `gl_daily_balances` or materialised-aggregate table until a report exceeds ~500 ms. At roughly 150 posting events/day × ~4 lines ≈ 220 k lines/year, the indexes in sec.6.7 carry you for years, and a summary table is a second truth to maintain.

### 6.7 Indexes on the ledger

```
gl_journal_entries: UNIQUE(company_id, entry_no)
                    UNIQUE(company_id, source_type, source_id, source_event_id)   -- idempotency
                    INDEX(posted_on), INDEX(period_id), INDEX(source_type, source_id),
                    INDEX(company_id, status, posted_on), INDEX(netting_group)
gl_journal_lines:   INDEX(entry_id), INDEX(account_id, posted_on), INDEX(posted_on, account_id),
                    INDEX(trip_id, account_id), INDEX(truck_id, account_id, posted_on),
                    INDEX(customer_id, account_id, posted_on), INDEX(work_order_id)
```

`posted_on` is denormalised onto every line for exactly this reason: date-range + dimension reports become single-table index range scans.

### 6.8 How each module posts

| Source event | Entry (one per document) | Debit | Credit |
|---|---|---|---|
| `InvoiceIssued` | Sales invoice | `1200 Accounts receivable — customer` | `4100 Freight revenue`, `2210 Output VAT` |
| `PaymentReceived` | Bank receipt | `1010 Bank` / `1020 Cash` | `1200 AR`; realised FX difference to `7510/7511` |
| `PaymentAllocated` (on-account) | Unapplied receipt | `1010 Bank` | `2150 Unapplied customer cash` — an overpayment never "adjusts" an invoice |
| `CreditNoteIssued` | Credit note | `4100 Revenue`, `2210 Output VAT` | `1200 AR` |
| `FuelPurchaseRecorded` | Fuel purchase | `5120 Fuel expense`, `2211 Input VAT` | `3100 Supplier payable` (or `1010/1020` if paid at pump) |
| `FuelIssueRecorded` (bulk tank) | Stock issue | `5120 Fuel expense` | `1310 Bulk fuel stock` (moving average) |
| `PartsIssued` | Stock issue | `5200 Maintenance expense` | `1300 Parts inventory` |
| `WorkOrderLineApproved` (labour) | Internal labour | `5210 Repairs & maintenance` | `5290 Workshop labour clearing` |
| `BillRecorded` (external garage) | Supplier bill | `5210 Repairs`, `2211 Input VAT` | `3100 Supplier payable` |
| `CapitalisedWorkOrderClosed` | Asset addition | `1510 Vehicle assets` | `3100/1010` |
| `BorderCostCaptured` | Border cost | `5400 Duties & permits` **or** `1210 Recoverable input tax` (COA config, not code) | `1020 Cash` / `1290 Agent receivable` |
| `AgentCashAdvanced` | Agent float | `1290 Border agent receivable` | `1010 Bank` |
| `AgentAdvanceReconciled` | Variance | `7900 Cash short` / credit `7901 Cash over` (variance ≠ 0 requires Owner) | contra `1290` |
| `AdvanceDisbursed` | Driver advance | `1250 Employee receivable` | `1010/1020` |
| `TripSettled` | Settlement | `5300 Driver travel expense`, `1010` (recovery), `2211` (VAT if any) | `1250 Employee receivable` |
| `SalaryRunPosted` | Payroll (payroll-lite) | `6100 Salaries` | `2100 Net payroll payable`, `1250` (advances cleared), `2200 statutory payable` only if configured |
| `LoanDisbursed` | Disbursement | `1010 Bank` | `2500 Vehicle loan liability`; fees `6510 Loan fees` / `1010` |
| `LoanInstallmentPaid` | Instalment | `2500 Principal`, `6500 Interest expense` | `1010 Bank` — split read from stored schedule rows, never recomputed at report time |
| `ExpenseRecorded` | General expense | category `5xxx`, `2211 Input VAT` | `1010/1020/3100` |
| `OpeningBalanceImported` | Migration entry | per CSV | `4900 Retained earnings / opening equity` |

Every one of these is a `PostingRule` in a single registry (`Kernel\Ledger\AccountMap`), so "which account does this event hit?" has exactly one answer in one file. Duty-as-expense vs duty-as-recoverable-tax is a COA configuration, not a code branch.

### 6.9 Operational reports vs the ledger — the tie-out

Because the ledger is now the statutory record: **financial statements read the ledger; operational screens read operational tables; `reports:tie-to-ledger` reconciles the two on a schedule and alarms.** Without the tie-out, a single unposted bill makes the dashboard and the P&L disagree and the owner stops trusting both. With it, "revenue per the ledger 1.24M vs per jobs 1.26M, difference: 3 unposted chargeables on trips T-11, T-19" is a screen instead of a suspicion.

---

## 7. Document numbering

Gap-free sequential numbers for invoices, credit notes, receipts and journal entries — and for operational references (trips, work orders, jobs, POs) where "never reused" matters more than "no gaps".

### 7.1 The table

```
number_sequences
  id             unsignedBigInteger PK
  company_id     unsignedBigInteger NOT NULL DEFAULT 1
  scope          string(40) NOT NULL   -- invoice | credit_note | debit_note | receipt | pro_forma
                                        -- journal_entry | trip | job | work_order | po | vat_return
  period_key     string(12) NOT NULL DEFAULT ''  -- '2026' | 'FY26/27' | '' (continuous)
  next_value     unsignedBigInteger NOT NULL
  format         string(64) NOT NULL   -- 'INV-{PERIOD}-{SEQ:7}'
  last_used_at   datetime NULL
  last_used_by   unsignedBigInteger NULL
  created_at / updated_at
  UNIQUE (company_id, scope, period_key)
  CHECK (next_value > 0)
```

### 7.2 Allocation

> **Amended 2026-10-26 after measurement (sec.20.4).** The original step 1 — `INSERT IGNORE` the row first — is **wrong on MySQL** and was reproduced as a deadlock. An `INSERT IGNORE` that finds the row already there takes a **shared next-key lock** on it, so the following `SELECT … FOR UPDATE` is a shared-to-exclusive lock upgrade; four concurrent workers deadlocked on it (`SQLSTATE 40001 / errno 1213`) on the first run, in the steady state as well as during creation. Allocation now takes exactly one lock, and the rows are created ahead of it by `NumberSequenceRegistry` (`numbering:ensure`, scheduled 00:10 daily).

Inside the business transaction (`IssueInvoice`, `PostJournalEntry`, …):

1. `SELECT next_value FROM number_sequences WHERE (company_id, scope, period_key) = … FOR UPDATE` → `$n`. The row **must already exist**; a missing row is an error pointing at `numbering:ensure`, never an opportunistic insert, because inserting here is what reintroduces the shared lock.
2. `UPDATE number_sequences SET next_value = next_value + 1, last_used_at = now(), last_used_by = ?`.
3. Render the number from `format` and write it to the document.
4. Commit.

The row lock serialises allocation, and because the increment is transactional, a rollback releases both the lock and the value: no gaps, no reuse. Verified: 60 numbers across 4 concurrent processes → 60 distinct, gapless `PO-00001`…`PO-00060`, counter exactly 61, zero deadlocks.

Because a lock wait can still end in a serialization failure under real load, the **enclosing Action** is wrapped in `DB::transaction($fn, attempts: 3)` — the retry belongs to the whole business transaction, not to the allocator, so a retried attempt re-allocates cleanly instead of half-applying.

`format` must contain `{SEQ}`: a format with no counter cannot be gap-free, so `NumberFormat::validate()` refuses it — and refuses it **at row-creation time**, so a typo in `config/fleet.php` never reaches `number_sequences` only to fail at the moment a fiscal document is issued.

### 7.3 What was rejected, and why

| Option | Verdict |
|---|---|
| `max(id)+1` / `count()+1` | Two concurrent issuers get the same number. The classic. |
| `auto_increment` as the visible number | InnoDB skips values on rollback, bulk insert and restart; **not** gap-free, and ID spacing leaks your sales volume. |
| MariaDB `SEQUENCE` / `NEXTVAL()` (available ≥ 10.3) | Values are **non-transactional**: a rolled-back transaction burns the number, creating exactly the gap you promised not to have. Also MariaDB-only syntax, and production is **MySQL** on Laravel Cloud. Legitimate only if gaps are acceptable. |
| ULID as the visible number | Correct as the PK, wrong as a document reference: humans need short, comparable, dictatable numbers. |

### 7.4 The rules that make "gap-free" mean something

- **Number at issue, not at creation.** `invoices.number` is `NULL` while `status = 'draft'`, with `CHECK (status <> 'issued' OR number IS NOT NULL)`. Drafts are addressed by ULID. Every allocated number therefore corresponds to an issued fiscal document — which is what the requirement is actually for.
- **Void keeps its number.** "Gap-free" legally means *unique, monotonic, never reused, never removed*, so voiding sets `status = 'void'` and retains row + number. Constrained hard: void is allowed only when there is no payment allocation, no credit note referencing it, it has never been delivered (no email or PDF handover recorded), and it is same-business-day or an Owner override with a mandatory reason. Anything a customer has seen gets a **credit note** — its own scope, not a re-use.
- **Never hold the lock across I/O.** No mail, PDF render, HTTP or queue dispatch inside the transaction — `dispatch(...)->afterCommit()`. Multi-document transactions allocate in deterministic `(scope, period_key)` order to avoid deadlocks. Default `innodb_lock_wait_timeout` is 50 s, so a stuck transaction shows up as a user-facing hang, not corruption — keep the transaction small.
- **Detect what you cannot prevent.** Nightly `documents:number-integrity`: per `(company, scope, period_key)`, assert `max(next_value)` matches the count of non-draft documents, numbers are strictly monotonic, no duplicates, no `NULL` on issued. Alarm on drift. This also catches a human with a SQL client.

⚠ `period_key` is configurable per scope so that a per-year restart, a financial-year restart (`FY26/27`) or a continuous sequence are all a **setting**, decided with your accountant (sec.17 Q2) rather than baked into a migration.

---

## 8. Tenancy model

**Single company now; multi-company must not become a rewrite later.** Five commitments, and nothing more. ADR-0003.

| # | Commitment | Cost today | Cost to skip it later |
|---|---|---|---|
| T1 | `company_id NOT NULL DEFAULT 1` on every business table, **leading column of every unique key and hot composite index** (`UNIQUE(company_id, code)`, `INDEX(company_id, scope, period_key)`, `INDEX(company_id, status, posted_on)`) | One column per table | Changing ~90 unique indexes is a data-integrity event, not a migration |
| T2 | Discriminator scoping: a `BelongsToCompany` trait + global scope, `CurrentCompany` resolved from `auth()->user()->company_id` by middleware, cached per request, **never from the URL**; auto-filled on `creating` | ~120 lines | A package (`stancl/tenancy`) or schema-per-tenant, both of which touch every query |
| T3 | **Fail closed**: a scoped model queried with no company context **throws** instead of returning unscoped rows. `withoutCompanyScope()` is explicit and only for CLI/totals, and CLI commands require `--company=1` | One exception class | A silent cross-tenant leak, discovered by a customer |
| T4 | Company-held settings: `base_currency_code`, `timezone`, `fiscal_year_starts_on`, `country_id`, `vat_number`, `registration_number`, invoice footer, numbering formats, approval thresholds. Rule: `Company::current()->base_currency_code`, memoised — **never `config('app.currency')`** | One table + a resolver | Two hundred hard-coded `BWP`/`Africa/Gaborone` references |
| T5 | Policies always compare `$model->company_id === $user->company_id` even while redundant | One line per policy | An audit of every policy, later, under time pressure |

`number_sequences`, `gl_accounts`, `gl_periods`, document storage paths and RBAC roles are all company-keyed.

**Explicitly not designed:** a user belonging to many companies, company switching, inter-company billing, consolidated group reporting. **Honest ceiling:** this comfortably supports a handful of related companies in one database. Real legal isolation (different accountants, different tax regimes, "must not share a database") means separate databases, and nothing here prevents that — it becomes a deployment decision rather than a rewrite.

---

## 9. Security and permission matrix

### 9.1 Identity

| Concern | Design |
|---|---|
| Owner-first | A first-run setup command/screen creates the Company + Owner account, seeds the COA, roles and default settings. **Public registration is disabled** (remove `Features::registration()` from `config/fortify.php` — documented behaviour of the Laravel 13 starter kits, verified). |
| Staff accounts | The Owner invites by email with a role. An invite is single-use, expires, and is accepted against a verified email address. No self-signup path exists at all. |
| MFA | TOTP via the starter kit's Fortify 2FA, **enabled by default** (`Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`), with recovery codes. **Mandatory** for Owner and for any role holding `financial:*` or `accounting:*` permissions — enforced by middleware, not by a polite prompt on the profile page. Passkeys are **first-party, not a WorkOS dependency** (Phase −1 verification): the installed kit ships `laravel/passkeys` 0.2.1 with `Features::passkeys(['confirmPassword' => true])` already in `config/fortify.php`, plus `components/passkey-registration.blade.php`, `passkey-verify.blade.php` and a `.well-known/passkey-endpoints` route. The decision stands as a *sequencing* choice, not a missing capability: TOTP first (works on any phone, no platform account), passkeys as an optional additional factor after the Owner enrols. |
| Re-auth before sensitive actions | Fortify's `/user/confirm-password` behind a `reauth` middleware on: changing bank details, period locking, permission changes, exporting the ledger or payroll, downloading documents in bulk, voiding an issued invoice. |
| Rate limiting | Login limited per `email + IP`; invite send, password reset and kiosk-token redemption each get their own limiter. |
| Sessions | `SESSION_DRIVER` on Valkey/Redis with a configurable idle timeout (default 2 h, absolute max 12 h). Device/session list is a **first-party screen** (append `user_agent`, `ip_address`, `last_seen_at` to the session record, offer "log out everywhere") rather than another package — whether the starter kit still ships a Jetstream-style sessions screen is unverified and the fallback is ~100 lines. |
| Driver field access | **Trip-scoped kiosk tokens** (sec.9.6), not driver user accounts. ADR-0006. |

### 9.2 Roles

`spatie/laravel-permission` v8 stores roles and permissions; **policies make every real decision**. `can()` is not scattered through components — components ask a policy or a named gate, which is what makes field-level rules auditable.

Owner, Operations Manager, Dispatcher, Workshop Manager, Stores Clerk, Accountant, Read-only Auditor, plus two non-user principals: **Kiosk token** (per-trip scopes) and **System** (scheduler, no interactive session).

### 9.3 Permission matrix

Legend: **F** full · **C** create+update own · **R** read · **–** none · **A** approve · **⚑** requires re-auth

| Capability | Owner | Ops Mgr | Dispatcher | Workshop Mgr | Stores Clerk | Accountant | Auditor | Kiosk |
|---|---|---|---|---|---|---|---|---|
| Dashboard: all financial cards | F | F | R (ops cards only) | R (workshop cards) | R (stock) | F | R | – |
| Alert register: see / acknowledge & mute / resolve | F / F / F | F / F / F | R / F / – | R / F / – | R / – / – | R / – / – | R / – / – | – |
| Fleet: view / edit / sell vehicle | F / F / ⚑A | F / F / A | R / – / – | R / odometer / – | R / – / – | R / – / – | R | – |
| Vehicle purchase price & finance | **F** | R | **hidden** | hidden | hidden | F | R | – |
| Jobs: create / quote / rate cost | F / F / F | F / F / F | C / C / **hidden** | – | – | R | R | – |
| Trips: plan / dispatch / complete / reopen | F / F / F / ⚑A | F / F / F / A | F / F / R / – | R | R | R | R | view own |
| POD capture & approve | F | F | F / F | – | – | R | R | **capture** |
| Fuel: record / correct / waive variance | F / ⚑A / A | F / F / A | R / – / – | R | C (bulk) | R | R | record own |
| Consumption variance & cost per trip | F | F | **hidden margin, R litres** | R (own trucks) | – | F | R | – |
| Work orders: raise / approve / close | F / F / ⚑A (over threshold) | F / A / F | R | C / R / C | C (parts) / – / – | R | R | – |
| Parts stock: adjust / count | F / F | F / F | – | R / C | C / F | R | R | – |
| Invoices: draft / issue / void ⚑ | F / F / ⚑F | R / R / – | R (own jobs) | – | – | **F / F / ⚑F** | R | – |
| Payments, allocation, credit notes | F / F / ⚑A | R | – | – | – | F / F / F | R | – |
| Expenses & supplier bills | F / F | C / C | – | C (workshop) | C (parts) | F / F | R | – |
| Bank & cash accounts, encrypted numbers | F / ⚑ | R (masked) | – | – | – | F / ⚑ | R (masked) | – |
| Ledger: post / manual journal / **close period** | F / F / **⚑F** | R | – | – | – | F / F / ⚑F | R | – |
| Loans & instalments | F / F | R | – | – | – | F | R | – |
| Salaries, advances, settlements | F / F | R (allowances) | – | – | – | F | R | view own settlement |
| Documents: upload / download / delete | F / F / ⚑F | F / F / – | C / R(own trips) / – | C / F / – | C / R / – | F / F / – | R / R / – | upload own POD |
| Cross-border: costs, agents, reconciliation | F / F / ⚑A | F / F / A | R | – | – | F / F / F | R | – |
| GPS live view & alerts | F | F | F | R (own) | – | R | R | own trip |
| Reports & exports (⚑ for financial) | F / ⚑ | F / ⚑ | ops only | workshop only | stock only | F / F | R / ⚑ | – |
| Users, roles, permissions ⚑ | **F only** | – | – | – | – | – | R | – |
| Settings & numbering ⚑ | F | R | – | – | – | F (own scopes) | – | – |

Two things this matrix is enforcing rather than decorating: **a dispatcher must not see margins** (`trip.contribution_base`, `job.rate_cost`, `truck.purchase_price` are hidden at the field layer), and **only the Owner or Accountant can move a posted number** — every path that can change the ledger is either re-auth-gated or a reversing entry.

The alert register's three columns are the sec.9.4 rule applied to a screen that only has one table: the split sits on the state machine's own boundary, not on convenience. `alerts.acknowledge` covers the two reversible decisions (acknowledged, muted-with-an-expiry) and is held by the roles sec.14.1 actually delivers to, because sec.14.1's own example is the Operations Manager *and* the Dispatcher both acting on one alert. `alerts.resolve` is held by Owner and Operations Manager only, because `alerts_resolved_is_final` means the engine cannot undo it — and a single permission for both would have to be granted at the level a dispatcher needs and would then let that dispatcher close a compliance finding for good. Every role holds `alerts.view`, so the read-only row is honest about it: the Auditor sees the register and holds neither write.

### 9.4 Field-level protection

Named gates, not ad-hoc `@can` expressions: `seeTripMargin`, `seeVehicleCost`, `seePayroll`, `seeBankDetail`, `seeCustomerTerms`. Each gate is one policy method, each field has exactly one gate, and the Blade/APSF component that renders the field asks the gate. A report export passes the same gates — **an export is an authorised read, not a side door** — which is why `Reporting` calls policies rather than re-selecting columns.

### 9.5 Encrypted columns and the audit trail

| Data | Treatment |
|---|---|
| Bank/account numbers, driver licence number, national ID, fuel card PAN | Encrypted with a **dedicated `DB_CRYPT_KEY`** (separate config entry, not `APP_KEY`) + a `key_version` column on the row. A `*_masked` column holds the display form (`••••4521`) in clear so search, sorting and reports still work — encryption otherwise silently breaks every screen that touches the column. The set of encrypted columns is listed per column, because **adding encryption later needs a re-encrypt migration and removing it exposes history.** |
| `APP_KEY` rotation | Becomes a key-management job (`keys:reencrypt`), not a data-loss event. Key escrowed off-box. Recorded honestly: **key + database backup on the same machine is not a control** — which is why sec.14.5 puts the encrypted export in a *separate* off-site bucket. |
| Audit trail | `owen-it/laravel-auditing` v14 for create/update/delete with `old_values`/`new_values`; plus our own `audits` rows for login, permission change, export, document download, and every state transition. Every audit row records **who, when, IP, user agent, and before/after**. `audits` and `security_events` are **append-only**, protected by the same trigger/barrier pattern as the ledger (sec.6.3), and no application user has a delete or update path. |
| Retention | Audit rows retained ≥ 7 years (configurable), archived to the documents disk after 24 months rather than deleted — "the app cannot delete it" is the control, so a retention rule must move it, not remove it. |
| Financial records | Soft delete only for master data with no postings. **No hard deletion of posted financial data** anywhere in the codebase; policies refuse it and the DB trigger refuses it twice. |

### 9.6 Kiosk tokens (driver field capture)

`trip_access_tokens` carries a **hash** of the token (never the token), the trip, the driver, a set of scopes (`pod|odometer|fuel|expense`), `expires_at` (default: trip planned end + 24 h), revoke flag, and `last_used_at`/`last_ip`. Issued by a dispatcher with the trip, shown once as a URL + a 6-digit PIN for bad-signal entry, rate-limited on redemption, and bound to the trip (a token cannot read another trip).

The kiosk surface is four screens: **POD capture** (photo via `capture="environment"` camera, signature canvas, quantity received, exceptions), **odometer + engine hours**, **fuel issue** (litres, tank, station, price), **trip expense** (toll, border petty cash, lodging). Every one writes through the **same Action** the office UI uses, so nothing about the future driver app or Sanctum API has to be re-designed. Kiosk writes are audited with `actor_kind = kiosk` and the token id, so attribution survives the shared phone. Offline is handled by a client-generated `client_uuid` on the POD record (`UNIQUE`) so a retry after a dropped border signal cannot duplicate the delivery note.

---

## 10. State machines

### 10.1 Implementation

States are **PHP backed enums** stored as `string(24)` with a DB `CHECK` listing the values. Transitions are declared **once per aggregate** as a static table and enforced by a first-party `CanTransition` trait:

```php
guardTransition($from, $to, $actor, reason: ...)  // throws IllegalTransition / NotAuthorised
```

Every transition writes an `operational_events` row (aggregate, from, to, actor, `actor_kind`, timestamp, **reason**) *before* the state column changes, so "who moved this and why" is answerable independently of the audit diff. Aggregates carry `previous_state` and `state_changed_at`.

`spatie/laravel-model-states` was considered and rejected: it needs PHP ^8.4, a class per state multiplies files for ~15 states, and your transitions must be enforced in Actions and Policies anyway, where an enum table is more direct. Revisit only if states start needing behaviour methods of their own.

**Reasons are mandatory for anything destructive** (`void`, `cancel`, `abort`, `reject`, `write_off`, `reopened`, variance waiver). A nullable `reason` column is how you lose the plot six months later.

### 10.2 Trip

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> planned : dispatcher
    planned --> assigned : dispatcher (validity checks)
    assigned --> loaded : kiosk/dispatcher
    loaded --> departed : dispatcher (odometer_start, fuel opening)
    assigned --> departed : dispatcher (no load plan)
    departed --> in_transit : arrival at first stop
    in_transit --> at_border : crossing reached
    at_border --> in_transit : crossed
    in_transit --> arrived : destination reached
    arrived --> pod_pending : kiosk/dispatcher
    pod_pending --> delivered : POD approved (accounts or dispatcher)
    delivered --> completed : owner/dispatcher (completion checklist)
    completed --> closed : accountant (costs frozen)
    closed --> reopened : owner only, reason (⚑ re-auth)
    reopened --> completed : re-run completion checklist
    planned --> on_hold : any pre-departure blocker
    assigned --> on_hold : any pre-departure blocker
    on_hold --> planned : blocker cleared
    assigned --> cancelled : dispatcher (pre-departure)
    planned --> cancelled : dispatcher
    departed --> aborted : dispatcher/owner, reason (costs still post, may still bill)
    completed --> [*]
    closed --> [*]
    cancelled --> [*]
    aborted --> [*]
```

| Transition | Who | Gate conditions enforced at the transition, not afterwards |
|---|---|---|
| `planned → assigned` | Dispatcher | Truck status `available`; driver licence **and** permit valid **on the trip's start date** (not "currently"); trailer roadworthy; no overlapping assignment for truck or driver; insurance and licence documents not expired. **This is the check that prevents an expired-licence dispatch.** |
| `assigned → loaded` | Kiosk / Dispatcher | Load tonnage ≤ truck and trailer capacity; waybill document attached. |
| `loaded/assigned → departed` | Dispatcher / Kiosk | `odometer_start ≥ last known odometer`; opening fuel reading; POD book/waybill present; departure geofence event accepted if GPS is configured. |
| `arrived → delivered` | Accounts Clerk / Dispatcher | POD captured with signature + photo + quantity; exceptions recorded or explicitly none. |
| `delivered → completed` | Owner / Dispatcher | `odometer_end ≥ odometer_start` and plausible; fuel reconciled **or explicitly waived with reason**; every border crossing has `exited`; driver advances recorded; required attachments present. |
| `completed → closed` | Accountant / Owner | Trip revenue and costs posted; settlement recorded; freezes cost editing and marks the rollup final. |
| `closed → reopened` | **Owner only, ⚑ re-auth, mandatory reason** | Must not rewrite the ledger — it posts **deltas** (a reversal plus a new entry). |

**A driver may never transition to `delivered`, `completed` or `closed`.** The kiosk captures facts; office roles decide completion.

### 10.3 Job (commercial)

`enquiry → quoted → accepted → in_execution → all_trips_completed → ready_to_invoice → invoiced → settled → closed`, with `declined` and `cancelled` reachable only pre-acceptance, and `suspended` from any pre-completion state. `invoiced`, `settled` and `all_trips_completed` are **derived aggregates** over the job's trips/invoices, surfaced as badges but never set by hand — only Owner may set `closed`. A job may have zero trips (`enquiry`, `quoted`, `declined`) and many trips (multi-leg, return loads).

### 10.4 Invoice — three axes, deliberately not one enum

| Axis | Stored or derived | Values |
|---|---|---|
| **lifecycle** | stored | `draft → issued → void` |
| **settlement** | derived from `payment_allocations` + credit notes | `open` `partially_paid` `paid` `refunded` `written_off` |
| **ageing** | derived from `due_on` vs today | `current` `d1_30` `d31_60` `d61_90` `d90_plus` `disputed` |

`draft → issued` (Accounts Clerk / Owner) is **the only point** at which a number is allocated and revenue posts. `issued → void` is Owner-only and constrained by sec.7.4. A credit note is a **separate document** referencing the invoice; it never rewinds invoice lifecycle — the invoice's derived `settlement_state` simply moves toward `refunded` as the credit is allocated.

Why split: a single conflated `status` containing both `void` and `paid` means payment allocation mutates lifecycle state, and gap-free numbering plus audit semantics die quietly in that coupling. This is the same reason `settlement_state` is computed from allocation rows rather than stored as truth on `invoices` (a maintained `balance_due_base` is a projection, and `invoices:recompute-balances` is the tie-out).

### 10.5 Credit note

`draft → issued → applied → settled`, plus `draft → cancelled`. Partial application keeps `issued` with a derived remaining amount. Its own numbering scope, its own ledger posting (sec.6.8).

### 10.6 Work order

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> awaiting_approval : estimated cost over threshold
    draft --> approved : under threshold
    awaiting_approval --> approved : owner (⚑ above limit)
    awaiting_approval --> rejected : owner
    approved --> planned : scheduled
    approved --> in_progress : vehicle in workshop
    planned --> in_progress
    in_progress --> on_hold : awaiting_parts|external_garage|awaiting_owner_approval|vehicle_needed_on_trip
    on_hold --> in_progress
    in_progress --> completed : vehicle released
    completed --> inspected : QA check
    inspected --> closed : accountant (costs frozen)
    draft --> cancelled
    approved --> cancelled
```

| Transition | Who | Notes |
|---|---|---|
| `draft → awaiting_approval → approved` | Workshop Manager raises; **Owner approves above `work_order.approval_required_amount_base`** | This threshold is the single control that matters in a trucking workshop: it stops a fitter ordering tyres on the company's account. |
| `in_progress → completed` | Workshop Manager / Mechanic | Requires odometer at fitment, parts issued, labour hours recorded. |
| `completed → inspected` | Workshop Manager or Owner | Pre-trip/periodic inspection checklist for safety-critical work (brakes, steering, tyres, coupling). |
| `inspected → closed` | Accountant | Freezes costs, triggers the **capitalise vs expense** decision, sets `capitalised_amount_base`. |
| `any pre-approval → rejected/cancelled` | Owner / Workshop Manager | Reason mandatory; reverses any parts issues (stock movements are reversed, not deleted). |

**Posting is not a state.** Parts post **when stock leaves** (perpetual inventory: `Dr 5200 / Cr 1300`), labour when the timesheet is approved, external bills when the supplier bill is recorded. Only `closed` freezes editability. Gate posting on `closed` and both your parts stock and your month are wrong mid-period — which is exactly the kind of defect the owner discovers at year-end.

**A work order that is "billed" is a different thing.** The fleet is owned, so cost recovery is *not* a customer invoice: driver negligence/misuse recovery posts `Dr 1260 Other receivable — driver / Cr 5200 Maintenance expense (contra)` and settles through trip settlement; warranty recovery from a supplier posts `Cr 5200` with a debit note against the supplier bill. A **service invoice** against a job/customer exists only when the truck or workshop is genuinely contracted out.

### 10.7 Loan

`draft → submitted → approved → disbursed → servicing → closed`, with `servicing ⇄ arrears`, `servicing → restructured` (new schedule rows; history preserved), `arrears → defaulted → written_off | recovered`, `approved → cancelled` (pre-disbursement only). `disbursed` posts `Dr 1010 / Cr 2500` (+ fee line). Each instalment payment posts a **split** — principal to liability, interest to `6500` — read from stored `loan_installments` rows, never recomputed from a formula at report time. A prepayment **appends** revised schedule rows and never edits a paid row.

### 10.8 Document — two axes

| Axis | Stored or derived | Values |
|---|---|---|
| lifecycle | stored | `draft → current → superseded → archived` , `voided` |
| validity | **derived from `expires_on`** | `valid` `expiring_soon` `expired` `perpetual` |

**The subject of `current → superseded` is the version, not the document** (owner-confirmed 2026-10-27; the sentence this replaces read as though publishing a version superseded the document, which would have left a re-uploaded scan showing no live copy for the truck and no alert). Publishing a version moves `is_current` and records `replaced_reason` on the version rows; the document's lifecycle is untouched. A document reaches `superseded` only when the paper it evidences is replaced by another document — a decision made by a person, therefore stored, therefore never automatic. One `is_current` version per document, enforced by a `STORED` generated column plus a unique index (MariaDB and MySQL have no partial indexes; `storedAs()` is documented in Laravel 13's migrations). Because a new version may be filed against a `draft` or a `current` document only (`acceptsNewVersion()`), bytes cannot be slipped into a record that has already been superseded, archived or voided. **`expired` is not a transition**: a nightly job derives it and emits `DocumentExpiryImminent` / `DocumentExpired`. Driver licences, permits, insurance, roadworthiness, weighbridge tickets, PODs and customs papers all live in this one machine — the alternative (a hand-coded expiry boolean per entity) is how you end up with five different definitions of "expired".

### 10.9 Smaller machines (same pattern)

| Aggregate | States |
|---|---|
| Fuel issue | `recorded → posted → reversed` (a correction is a reversal + new issue; the tank balance moves back) |
| Border crossing | `planned → entered → exited → costs_captured → agent_reconciled → closed` — the last two are what make phase 6 tractable, and `agent_reconciled` requires receipt-vs-advance matching with a variance line |
| Chargeable | `pending → ready → billed`, plus `written_off` |
| Payment allocation | reversible, never deleted: `reverse_of_id` + reason; `ledger:verify` asserts `Σ allocations == payment.amount_base` |
| Alert | `open → acknowledged → resolved`, plus `muted` with an expiry so muting cannot silently become permanent |
| Salary run | `draft → computed → approved → posted → reversed` |

---

## 11. Domain events between modules

### 11.1 The rule that keeps the numbers trustworthy

**At 21 trucks, money moves synchronously.** Queued ledger posting buys eventual consistency in the P&L, which buys reconciliation screens, which buys the owner losing trust in a number. There is no write-throughput problem to solve here.

| Runs **synchronously, inside the business transaction** | Runs **queued, after commit** |
|---|---|
| Ledger postings, sequence allocation, trip cost rollup, stock decrement, state transitions, audit rows, invariants (conflict/validity checks) | PDF render, invoice and reminder email, Excel exports, notification fan-out, GPS ingest normalisation, geofence and deviation evaluation, webhook parsing, scheduled reports, analytics snapshots |

### 11.2 Event map

| Producer | Event | Subscriber / Action | Sync? | Idempotency key |
|---|---|---|---|---|
| Operations | `TripAssigned` | `LicenceValidityCheck`, `ReserveVehicle`, `NotifyDriver` | check + reserve **sync**, notify queued | `trip_id + state` |
| Operations | `TripDeparted` | open fuel reading, create border placeholders, record `odometer_start` | sync | `trip_id` |
| Kiosk | `PodCaptured` | `StoreDocument`, `MarkPodPending` | doc row sync, image handling queued | `proof_of_deliveries.client_uuid` |
| Operations | `PodApproved` | `BuildChargeablesFromPod`, `billing_state → ready` | sync | `pod_id` — chargeables built from the **event payload**, not re-read later |
| Operations | `TripCompleted` | `FinaliseTripRollup`, `EvaluateFuelVariance` | rollup sync, variance queued | `trip_id + occurred_at` |
| Operations | `TripClosed` / `TripReopened` | `FreezeCosts` / `PrepareDeltaPosting`, requires re-auth | sync | `trip_id + closed_at` |
| Fuel | `FuelPurchaseRecorded` | `PostFuelPurchase`, `UpdateTruckEfficiency` | **sync** / queued analytics | event ULID → `source_event_id` |
| Fuel | `FuelVarianceDetected` | `CreateAlert`, `NotifyOwner` | queued | `truck + period + type` with a cooldown — **posts nothing** |
| Fuel | `BulkTankStockAdjusted` | `PostStockAdjustment`, `NotifyOwner` | sync | movement id |
| Maintenance | `PartsIssued` | `DecrementStock`, `PostPartsIssue` | sync | movement id; `lockForUpdate` on the part row for concurrent issues |
| Maintenance | `WorkOrderApproved` | `ReleaseProcurement`, `NotifyStores` | queued | wo + version |
| Maintenance | `WorkOrderClosed` | `FreezeWorkOrderCosts`, `PostCapitalisation` | sync | `wo + closed_at`; reopened orders post **deltas** |
| Invoicing | `InvoiceIssued` | `AllocateNumber`, `PostInvoiceIssue`, `RenderPdf`, `EmailInvoice` | number + posting **sync**, PDF + email queued | invoice ULID — never render or send inside the numbering transaction |
| Invoicing | `PaymentReceived` | `AllocatePayment`, `PostPayment`, realised-FX line | sync | payment ULID |
| Invoicing | `CreditNoteIssued` | `PostCreditNote`, `RecomputeAgeing` | sync | note ULID |
| CrossBorder | `BorderCrossingExited` | `CaptureCustomsCosts` → `PostBorderCosts`, `UpdateTripBorderCost` | sync | crossing id |
| CrossBorder | `AgentCashAdvanced` | `PostAgentAdvance` | sync | advance id |
| CrossBorder | `AgentAdvanceReconciled` | `ClearAgentReceivable`, `PostCashVariance` (variance ≠ 0 requires Owner) | sync | reconciliation id |
| People | `AdvanceDisbursed` | `PostAdvance` | sync | advance id |
| People | `TripSettled` | `ClearAdvances`, `PostDriverCost`, `ReconcileAllowance` | sync | settlement id |
| Accounting | `BillRecorded` / `BillPaid` | `PostBill`, `PostBillPayment` (+FX) | sync | bill id |
| Accounting | `PeriodCloseRequested` | `ValidateNoUnposted`, `LockPeriod`, `RejectLaterPostings` | sync, ⚑ re-auth | period id |
| Loans | `LoanDisbursed` / `LoanInstallmentPaid` | `PostDisbursement`, `PostInstalmentSplit` | sync | instalment id |
| Fleet | `OdometerRecorded` | `RecalculateEfficiency`, `TriggerServiceScheduleCheck` | **next-due computed sync** (a schedule must not wait for a nightly job), analytics queued | reading id |
| Documents | `DocumentVersionPublished` | `SupersedePreviousVersion`, `ResetExpiryReminders` | sync | doc + version |
| Documents | `DocumentDownloaded` | `AuditRecorder` | sync | event id |
| Scheduler | `ExpiryReminderDue` | `SendExpiryNotifications` | queued | `doc + threshold + recipient` |
| Telematics | `GpsPositionIngested` | `UpsertLatestPosition` (sync), `EvaluateGeofences`, `DetectDeviation` (queued) | mixed | `provider + provider_message_id` |
| Ledger | `JournalEntryPosted` | `UpdateTripRollup`, `MarkSourcePosted`, `RefreshDashboardCache` | sync / cached | entry id |
| Security | `MfaEnabled`, `PasswordChanged`, `SensitiveActionConfirmed`, `SessionRevoked` | append to `security_events` | sync | event id |
| Any model | Eloquent create/update/delete | `AuditRecorder` (laravel-auditing) | **sync, same transaction** | — if an audit row can be lost, it is not an audit trail |

### 11.3 Idempotency and ordering — the four mechanisms

1. **Duplicate delivery.** Queues are at-least-once. Guarded by `UNIQUE(company_id, source_type, source_id, source_event_id)` on `gl_journal_entries` plus `firstOrCreate` semantics in `PostJournalEntry`; a replay that hits the key is **caught and logged, never retried forever**. `ShouldBeUnique` with `uniqueId = $event->eventId`, `uniqueFor = 1 day` stops the double-enqueue before it reaches the DB.
2. **Out-of-order.** Two mandatory habits: (a) **event-carried state transfer** — the event DTO carries `posted_on`, amounts, currency, rate, dimension ids, so a subscriber never re-reads mutable aggregate state to decide numbers; (b) `posted_at`/`posted_on` come from the event's `occurred_at` (ULID timestamp), never from job execution time — otherwise a job retried at 02:00, or a January retry of a December fuel receipt, lands in the wrong period.
3. **Period-boundary retries.** A posting that hits a locked period **fails loudly**: it goes to a `ledger_posting_failures` table and a first-party "Posting failures" screen, never auto-posted into the open period and never silently dropped. `ledger:replay --event=...` (Owner, ⚑, mandatory reason) re-posts with an explicit effective date.
4. **No cascades and no fake ordering.** `InvoiceIssued` must not fire `PaymentReceived`; derived state comes from a query, not a chain, because a two-deep event chain is undebuggable. Where two postings must pair (invoice + VAT) they are **one entry with many lines** — that is what double-entry is for.

---

## 12. Package list with reasons

Compatibility checked against Packagist on 2026-10-26. "L13" means the declared `illuminate`/`laravel/framework` constraint allows `^13.0`.

### 12.1 Adopted

| Package | Version | L13 | Reason |
|---|---|---|---|
| `laravel/framework` | ^13 | — | Base. PHP 8.3+ required, we pin **8.4** (Cloud supports 8.2–8.5; 8.5 is very new for the ecosystem). |
| **Livewire 4 starter kit** (`laravel/ui`-style scaffold via `laravel new --livewire`) | latest | yes | Fortify-backed auth with **2FA on by default**, `/user/confirm-password` re-auth, rate-limited login, documented registration opt-out. Deleting this means hand-writing authentication and TOTP — the highest-risk code in the app to own. |
| `livewire/livewire` | ^4 | yes | Single-file components, islands, optimistic UI, `wire:navigate` (the brief's SPA navigation), Alpine bundled. No React/Vue, per the "avoid heavy front-end frameworks" constraint. |
| `tailwindcss` | ^4 | n/a | Brief's styling choice; v4 `@theme` is how the token layer in `docs/DESIGN-SYSTEM.md` becomes CSS variables. |
| `spatie/laravel-permission` | 8.3.2 | ✅ PHP ^8.3 | Roles/permissions in DB with gate integration. Chosen over hand-rolled ACL because role editing must be data (the Owner adds a role without a deploy). |
| `owen-it/laravel-auditing` | 14.0.6 | ✅ illuminate ^11–^13 | Model-centric `old_values`/`new_values` JSON **is** the brief's audit requirement. Rejected `spatie/laravel-activitylog` (a user-activity feed, weaker diffs, PHP ^8.4 floor). |
| `brick/money` | 0.15.2 | ✅ PHP ^8.2 | Exact integer minor units + `BigDecimal` + explicit `RoundingMode`. Needs `ext-bcmath` (or gmp) — verify on both runtimes. |
| `spatie/laravel-pdf` | 2.14.0 | ✅ L ^11–^13 | Blade → PDF for invoices, credit notes, statements, PODs. **v2 supports a DOMPDF driver that needs no external binary, no Node, no Docker** — the only honest answer for both Laravel Cloud and the Windows dev box. Chrome/Browsershot is an opt-in driver, not the default. |
| `spatie/simple-excel` | 3.11.0 | ✅ L ^9–^13 | Streaming readers/writers over `openspout` 5.12.2 (framework-free) for Excel exports and the brief's CSV **imports**. Rejected `maatwebsite/excel`: heavier, import-oriented, and we would then have two Excel stacks. |
| `laravel/sanctum` | ^4 | yes | The brief's API-readiness promise: a versioned token API reusing the same Actions, without a session cookie assumption. Installed and configured in phase 0, **endpoints land only when an integration actually needs them**. |
| `laravel/pint` | ^1 | yes | Coding standard the brief asked for. |
| `larastan/larastan` | ^3 | yes | Static analysis (brief's "static analysis") — and with tests gitignored, this is the cheapest automated correctness net that still runs in CI. |
| `intervention/image` | ^3 | yes | Only where needed: downscale/normalise POD photos and scan captures before storage. Not for thumbnails-as-a-feature. |
| `spatie/laravel-schedule-monitor`(eval) | latest | check | The reminder engine and backups must be *observed*; a scheduler that silently stops is the failure mode of every "documents expiring" promise. Evaluate at phase 0; fallback is a first-party `scheduler_heartbeat` row + alert. |
| `barryvdh/laravel-debugbar` | dev only | yes | Local N+1 and query inspection during development. |

### 12.2 Rejected or deferred, with reasons

| Package | Verdict | Why |
|---|---|---|
| `spatie/laravel-medialibrary` | **Rejected** | Your requirement is versioning + expiry + type taxonomy + private disk + signed short-lived URLs + retention classes. Medialibrary optimises conversions/thumbnails and its collection model fights a per-company/per-type disk layout. Purpose-built `documents` + `document_versions` instead. |
| `spatie/laravel-model-states` | **Rejected** | Needs PHP ^8.4, a class per state for ~15 states, and transitions already live in Actions/Policies. Native enums + a first-party guard (sec.10.1). |
| `spatie/laravel-backup` | **Rejected for the database** | Two independent blockers: 10.3.3's released tag declares Laravel **^12 only** (L13 support is on `main` untagged — UNVERIFIED as a release), and on Laravel Cloud the database is backed up as managed snapshots that **cannot be downloaded as raw `mysqldump` files**. Replaced by the sec.14.5 strategy; a `spatie`-style file backup remains *for the documents disk only* if its L13 tag lands by phase 9, otherwise a first-party queued job. |
| `laravel/reverb` | **Deferred to v1.1** | Live GPS with 21 vehicles is a polling problem: `wire:poll` at 60–120 s is simpler, cheaper and works on the Cloud scale-to-zero posture. `ShouldBroadcast` events are emitted from day one with `BROADCAST_CONNECTION=null`, so enabling Reverb later (possible — Cloud supports worker clusters for long-running processes) is config + one Echo block, not a rewrite. |
| `laravel/horizon` | **Not used** | Needs POSIX signals/`ext-pcntl` and cannot run on the Windows dev box — and it is unnecessary on Cloud, which ships managed queues with dedicated workers, autoscaling and a failed-job retry dashboard. What we do build in phase 0: a first-party **queue health screen** (failed jobs, oldest pending, retry button), because PDF/email/export failure is the main silent-failure mode. |
| `nwidart/laravel-modules` | **Rejected** | Real per-module providers/composer overhead for a one-developer app. Plain namespaces under `app/Domain/*` + a documented dependency direction give ~90 % of the boundary. |
| `stancl/tenancy` | **Rejected** | Single company. Discriminator scoping (sec.8) is five cheap commitments; the package would buy per-tenant schemas we do not want. |
| Filament / Nova | **Rejected** | Either would decide the UI for us and cut against the brief's "consistent design system of our own tokens + avoid heavy front-end frameworks". Livewire + Tailwind + our primitives. **Flux UI shipped with the kit and was removed on 2026-10-26** (owner decision, sec.20.2); `resources/views/components/ui/*` is the only component system in the app. |
| `get-streamed/*`, `dompdf` directly, `maatwebsite` | **Rejected** | Already covered by `spatie/laravel-pdf` (dompdf driver) and `simple-excel`. |
| A payroll/statutory package | **Out of scope** | sec.14.7 states the boundary. |

---

## 13. Folder structure

`app/Domain/{Module}` for logic, module-named view directories for Livewire 4 SFCs, and a shared Kernel that no module may reach inside.

```
app/
├── Domain/
│   ├── Kernel/                          # ← no Domain/* import is allowed in here
│   │   ├── Ledger/
│   │   │   ├── Actions/PostJournalEntry.php  PostReversal.php
│   │   │   ├── AccountMap.php           # "which account for this event" — one place
│   │   │   ├── LedgerService.php  TripCostRollup.php  PeriodResolver.php
│   │   │   ├── Models/{Account,JournalEntry,JournalLine,Period,LedgerBarrier}.php
│   │   │   ├── Data/PostingDto.php  LineDto.php
│   │   │   └── Support/{PostingFailureRecorder, LedgerVerifier}.php
│   │   ├── Numbering/{NumberSequence,NumberAllocator,NumberIntegrityChecker}.php
│   │   ├── Money/{MinorUnitsCast,MoneyCast,RateResolver,RoundingPolicy,FxEditor}.php
│   │   ├── Documents/{Document,DocumentVersion,DocumentStore,SignedUrlService,VirusScan}.php
│   │   ├── Audit/{AuditableConfig,AuditRecorder,SecurityEvent,OperationalEvent}.php
│   │   ├── Alerts/                          # sec.14.1's engine; the only Kernel package with a scheduler path
│   │   │   ├── AlertType.php  AlertSeverity.php  AlertState.php
│   │   │   ├── Actions/{RaiseAlert,TransitionAlert}.php   # the only doors in and out of a row
│   │   │   ├── Models/{Alert,ReminderThreshold,NotificationPreference}.php
│   │   │   ├── Data/{Threshold,ThresholdSet,ReminderCandidate,RaiseOutcome,ReminderRunReport,AlertCopy}.php
│   │   │   ├── Contracts/ReminderSource.php # ← the seam Phase 1 modules implement; Kernel names no module
│   │   │   ├── Sources/DocumentExpirySource.php
│   │   │   ├── Notifications/AlertRaised.php
│   │   │   └── Support/{ThresholdRepository,RecipientResolver,AlertDelivery,ReminderRunner,SelfCheckAlerter}.php
│   │   ├── Tenancy/{Company,BelongsToCompany,CurrentCompany,ResolveCurrentCompany}.php
│   │   ├── State/{CanTransition,TransitionGuard,IllegalTransition}.php
│   │   └── Settings/{CompanySettings,CachedSettings}.php
│   ├── Fleet/       {Models,Actions,Policies,States,Events,Listeners,Data,Services,Support}
│   ├── Operations/  # jobs, trips, dispatch, POD, chargeables, kiosk tokens
│   ├── Fuel/        # tanks, purchases, issues, bulk stock, variance
│   ├── Maintenance/ # schedules, work orders, tyres, parts, inspections, breakdowns
│   ├── Invoicing/   # invoices, payments, allocations, credit notes, ageing, statements
│   ├── Accounting/  # bills, bank & cash, expenses, loans, forecast, period close, statements
│   ├── People/      # drivers, employees, allowances, advances, salary runs, settlements
│   ├── CrossBorder/ # posts, crossings, costs, permits, weighbridge, agents, FX
│   ├── Telematics/  # Contracts/GpsProvider.php  Adapters/{SimulatorProvider,…}
│   │                # PositionIngest, LatestPositionCache, Geofences, DeviationDetector, EtaCalculator
│   ├── Reporting/   # ReportDefinitions/, Exporters/, ScheduledReports/, MetricCatalog
│   └── Security/    # KioskTokens/, Reauth/, DataGovernance/ (encrypted columns), Sessions/
├── Http/
│   ├── Controllers/Api/V1/*             # thin: validate → Action → Resource
│   ├── Requests/                        # FormRequest per Action input
│   └── Resources/                       # API serializers (money as strings)
├── Console/Commands/                    # ledger:verify  documents:number-integrity  trips:recost
│                                        # reports:tie-to-ledger  reminders:run  backups:export-offsite
├── Livewire/                            # only class-based components that must be namespaced/reused
└── Providers/{AppServiceProvider,DomainServiceProvider,TelematicsServiceProvider,FortifyServiceProvider}.php

resources/
├── views/
│   ├── components/                      # Livewire 4 SFCs + Blade x-components (design system)
│   │   ├── ui/                          # button, input, table, badge, card, modal, toast, empty, skeleton
│   │   ├── layouts/                     # app shell: sidebar + topbar; auth; kiosk; print
│   │   ├── fleet/  operations/  fuel/  maintenance/  invoicing/  accounting/
│   │   ├── people/  crossborder/  telematics/  reporting/  documents/  security/
│   │   └── dashboard/                   # permission-aware KPI cards
│   ├── livewire/                        # classic views for --class components only
│   ├── print/                           # invoice / credit note / statement / POD (print CSS subset)
│   └── emails/                          # reminders, invoices, scheduled reports
├── css/app.css                          # Tailwind v4 @theme tokens → CSS variables
└── js/                                  # Alpine only + Leaflet entry + signature pad + command palette

routes/
├── web/{dashboard,fleet,operations,fuel,maintenance,invoicing,accounting,people,
│        crossborder,telematics,reporting,documents,security,kiosk}.php   # required from bootstrap/app.php
└── api.php                              # v1 group, Sanctum

config/fleet.php        # module flags, thresholds, GPS provider map, COA seed version, reminder buckets
database/
├── migrations/          # prefix by module: 2026_01_15_000100_kernel_ledger_….php, _000200_fleet_…
├── seeders/
│   ├── Production/{CompanySeeder,OwnerSeeder,RolesSeeder,ChartOfAccountsSeeder,CurrenciesSeeder}.php
│   └── Demo/{VehicleSeeder,DriverSeeder,CustomerSeeder,RouteSeeder,TripSeeder,FuelSeeder,
│             MaintenanceSeeder,InvoiceSeeder,BorderSeeder,GpsSimulatorSeeder}.php   # --demo only
└── factories/
docs/
├── DESIGN-SYSTEM.md
├── api-surface.md          # sec.14.9's contract, written in phase 0; no endpoints implemented
├── adr/0001…0006
├── operations/{runbook.md,backup-restore.md,deployment.md,hardening.md}
└── user-guide/{dispatch.md,fuel.md,workshop.md,invoicing.md,reporting.md}
tests/                    # EXISTS LOCALLY, listed in .gitignore — see sec.14.6
```

**Livewire 4 placement rule.** SFCs are Blade-resident (`resources/views/components/…`), so domain modularity *cannot* fully live in `app/` — that is accepted, and the mitigation is written down rather than discovered mid-build:

- Business logic is in `app/Domain/*/Actions`; a component's write path is a 3-line delegate, no matter how thin it looks.
- **Verified at Phase −1 (Livewire 4.4.8, `make:livewire --sfc|--mfc|--class`):** single-file components are written with a **literal `⚡` glyph in the filename** — e.g. `resources/views/livewire/settings/⚡appearance.blade.php`, `⚡two-factor-setup-modal.blade.php` — and the starter kit generates them that way throughout. This is not a documentation rendering artifact. Consequences we accept knowingly: NTFS and Git handle the UTF-8 names, but `git status`/`git diff` quote them (`core.quotePath`), shell globbing and grep need quoting, and Windows consoles may show `?`. **Checkpoint before Phase 0 UI work:** confirm Tailwind v4 source scanning, Pint, Larastan, the architecture-lint grep and the editor all behave on these names; if any of them fights us, module screens move to `--mfc`/`--class` and the ⚡ form is kept only where the kit already uses it. **Nothing in this design depends on the SFC file form** — only file layout does, so this remains a reversible style choice, not an architectural one.
- Start every screen as an SFC. Promote to `--mfc` (directory with `.php` + `.blade.php`) or `--class` when it exceeds **~250 lines** or is reused/embedded as an island. A numeric threshold decided now beats arbitrary drift later.
- Mirror the module name in the view directory so `app/Domain/Fuel` and its UI are adjacent by name.

---

## 14. Cross-cutting foundations

### 14.1 Notifications and the reminder engine

One `Alert` table (sec.4.4) is the source of truth for "needs attention"; notifications are a *delivery* of an alert, which is what makes the bell, the email and a future WhatsApp message consistent instead of three divergent senders.

| Trigger | Types | Channels |
|---|---|---|
| Scheduler, nightly | `doc_expiry` (licence, permit, insurance, roadworthiness), `service_due` (km, hours, date), `loan_due`, `permit_expiry` | in-app + email |
| Scheduler, hourly | `overdue_invoice`, `cash_shortage` forecast breach | in-app + email |
| Domain event | `fuel_variance`, `geofence`, `route_deviation`, `posting_failure`, `breakdown` | in-app, critical → email |

Thresholds (60/30/14/7 days) are **rows in `reminder_thresholds`**, per company and per type, editable by the Owner — not constants. Every alert carries a `cooldown_key` (`type + subject + bucket`) so a nightly job cannot email the same expiring licence fourteen times. Recipients are resolved by role, and `notification_preferences` lets a user opt out per type/channel except for critical financial and safety alerts. Scheduler is the only driver; nothing in the reminder path runs on a page load.

**Built in slice 8 (2026-10-27), with the five decisions the sentences above left open:**

- **The bell reads `notifications`, the register reads `alerts`, and exactly one accessor reconciles them.**
  `User::unreadAlertsCount()` → `Alert::countUnreadDeliveriesFor($user)`: the person's own unread
  `AlertRaised` deliveries, counted only where the alert behind them is still live. A `notifications` row has
  no idea whether the thing it delivered is finished, and an `alerts` row has no idea who read it — the bell
  needs both, so the read is two small queries rather than a JSON join. A second component asking its own
  question of either table is how a bell and a register start disagreeing, which is why the accessor is the
  only door and the topbar is the only caller.
- **One alert row per condition *instance*, escalated across bands** — not one row per band, which is what
  "type + subject + bucket" reads as. For papers the bucket is the expiry date, so a truck's licence is one
  live row that moves `info → warning → critical` and re-delivers at each crossing; the register's "what needs
  me now" count stays one per paper while sec.14.1's fourteen-emails rule still holds. `#2`-style episode suffixes
  give a condition that returns after being resolved its own row, capped at 12.
- **`days_before` is any number, not the four in the sentence above.** 60/30/14/7 are the *seeded* rows; the
  unique key is `(company_id, family, days_before)` and the Owner's 45-day row is a legitimate row. A fixed
  list would have needed a code change to answer "what my accountant actually asked for".
- **A reminder that reaches nobody is a defect with an exit code.** A catalogue role with no holder in that
  company contributes nobody and is reported per tenant ("… told nobody — the role keys on that threshold row
  have no holder in company #2"), and `reminders:run` exits 1; a role name outside sec.9.2's catalogue still
  throws. Both halves matter: the first version asked spatie for the role by name, so an unprovisioned tenant
  raised `RoleDoesNotExist`, aborted the sweep, and every company after it got no reminders — quietly, at 02:30.
- **Delivery is queued on a named `alerts` queue with a snapshot, never a model.** The worker runs with no
  company context (`DomainServiceProvider` drops it after every job, which is sec.20.4's leak closed), so a
  notification that re-read the company-scoped `Alert` threw `ModelNotFoundException` and left the finding in
  `failed_jobs` instead of in anybody's inbox. `Data/AlertCopy` is built while the tenant is pinned; the
  verifier fails the run if a single `alerts`-queue job lands in `failed_jobs`.

A self-check alert (sec.14.6) has no threshold row — its shape is "N days before a date" and drift has no such
date — so its recipients come from `fleet.reminders.self_check_recipients`, defaulting to `owner`.

### 14.2 Settings

One `CachedSettings` read per request (a single memoised `Company::current()->settings` blob + `config/fleet.php` defaults), never one query per key. `Cache::touch()` (Laravel 13) refreshes the TTL on write without re-reading. Every setting is company-scoped, which is what sec.8's T4 depends on.

### 14.3 Performance

- `preventLazyLoading()` outside production; a query-count assertion in the local suite's page tests.
- Indexes on every FK and every filtered column; composite indexes lead with `company_id`; ledger indexes per sec.6.7.
- Queued jobs (Cloud managed queues) for PDFs, exports and heavy reports; `Queue::route(...)` (Laravel 13) declares routing centrally rather than per job class.
- Dashboard: write-time rollups (sec.6.6) + a 60–300 s cache per company × filter, invalidated by `JournalEntryPosted`.
- Pagination everywhere (cursor for the GPS and audit tables); no unbounded `get()`.
- **GPS volume:** `gps_positions` with batched multi-row inserts (one statement per webhook batch), `UNIQUE(provider, provider_message_id)` for webhook dedupe, a 4-week retention on the raw table, monthly archive to the documents disk as compressed CSV, and a separate `latest_positions` projection so "where are my trucks" never queries the history table. At 21 vehicles × 30 s pings ≈ 60 k rows/day, archiving is a requirement, not an optimisation.

### 14.4 Documents and storage

Private disk (`documents_private`, never publicly reachable; on Cloud an S3-compatible private bucket), path `company/{id}/{type}/{entity}/{ulid}/{version}`. Downloads go through `URL::temporarySignedRoute` with a short TTL (default 60 s) and no token store — a redemption system is over-engineering at this scale. Responses send `Cache-Control: private, no-store`. Type validation by MIME sniff *of the content*, not the extension; size caps per type; a virus scan step on upload (queue-clamav against a ClamAV sidecar is the honest Linux design — on Cloud there is no local daemon, so the options are a hosted scanning API or documented defence-in-depth with strict type allowlists; recorded as sec.17 Q8). Every download and export is audited with actor, IP and document id. Role-based visibility via `documents.visibility` + policy.

### 14.5 Backups and restore (rewritten for Laravel Cloud)

| Layer | What it is | How it is proven |
|---|---|---|
| Database | **Cloud-managed daily backups, retention set to 30 days** + a manual snapshot before every release and before every period close | Quarterly **restore drill into a new cluster** (Cloud restores to a *new* cluster, never in place) with row-count and trial-balance comparison |
| Point-in-time | Cloud retains binary logs ~8 days | Documented in the runbook as the recovery window for a bad deploy |
| Off-site copy | A **monthly application-level export** of ledger-critical tables (accounts, entries, lines, invoices, lines, payments, allocations, loans, instalments, documents index) as gzipped CSV, encrypted with **AES-256-GCM via `ext-openssl`** (`ext-sodium` is **not loaded** on the local PHP 8.4.27 build, verified 2026-10-26 — Sodium is therefore not an assumption we may make, and Cloud availability must be checked, not presumed) and written to a **separate** S3-compatible bucket in another provider/region, using only app credentials — because Cloud snapshots are not downloadable as raw dumps | `backup:verify-offsite` asserts the last successful export is < 35 days old and alarms otherwise |
| Documents | The private bucket gets **cross-bucket replication or a nightly versioned copy** to the same off-site bucket | Sample restore of one POD per drill |
| Secrets | `APP_KEY`, `DB_CRYPT_KEY`, SMTP and GPS credentials in Cloud environment variables/secret store only, escrowed offline (password manager + printed copy in the safe), never in the repo | Rotation procedure in the runbook, and `keys:reencrypt` for the encrypted columns |
| Health | A `backup_health` row updated by every job, surfaced on the Owner's dashboard | **Silence is loud**: a stale row is itself an alert |

The runbook also states the honest limit: **key + backup in the same place is not a control.** That is why the off-site export uses a different credential set and a different provider.

### 14.6 Coding standards, and the quality regime without committed tests

- `pint` (preset `laravel`) and `larastan` **at level 7** (the level `composer require larastan/larastan:^3.0` configured on install; stricter than the level 6 this document originally proposed, and kept). Both run **locally before every phase sign-off** — and only locally for the tests, because `tests/` is gitignored, so there is no CI step that could run them. What CI can carry later is pint + phpstan + the architecture lint. Verified on the fresh skeleton 2026-10-26: `pint --test` PASS on 45 files, `phpstan analyse` 0 errors.
- Architecture linter: a `composer lint:architecture` script that greps `use App\Domain…` statements and fails on a Kernel→module or module→module-model violation (sec.3.1).
- **Local-only tests** (your decision): `tests/` exists on the developer machine, is listed in `.gitignore`, and covers ledger balance and idempotency, number allocation under concurrency, FX rounding and the manual-override rule, payment allocation with residue, state-machine transition authorisation, and each page's permission matrix. `phpunit.xml`/Pest config also gitignored; CI therefore runs pint + larastan + the architecture linter, and the phase sign-off checklist records which local suites were run.
- **Production self-checks** (these are the durable regime, not the tests): `ledger:verify`, `documents:number-integrity`, `trips:recost`, `invoices:recompute-balances`, `reports:tie-to-ledger`, `parts:stock-count-diff`, and the visible "Posting failures" and "Out of balance" screens. Every one is scheduled; every failure raises an alert.
  - **Wired 2026-10-27 (slice 8) for the three that exist**: `ledger:verify`, `documents:number-integrity` and
    `documents:verify-integrity` each hand their findings to `Kernel/Alerts/Support/SelfCheckAlerter`, which
    raises through the same `RaiseAlert` door as a reminder — so drift gets the cooldown, the episode suffix,
    the `operational_events` row and the bell for free, and a second raise path cannot drift from the first. A
    clean run resolves its own finding, because an accurate alert about a problem that no longer exists is
    wallpaper, not a signal; the close is recorded with `resolved_by_kind = 'scheduler'` and **no**
    `resolved_by`, rather than attributed to whoever was signed in.
  - Two rules came out of measuring it. A finding **with no tenant is returned, not thrown and not guessed**:
    one stray object at the private disk root must not suppress twenty trucks' worth of real findings, and
    guessing a tenant is how one company reads another's integrity report. And `clear()` takes the company the
    run was narrowed to — a `--company=1` sweep says nothing about company 2, so it may not close company 2's
    alert; the verifier caught that one while it was being written, and caught its sequel too: clearing
    matched the base cooldown key only, so a check that failed a *second* time (which sits on `base#2`) could
    never be closed. Episode keys are therefore derived in one place, `Alert::episodeKeys()`.
  - Not yet wired, because they do not exist: `trips:recost`, `invoices:recompute-balances`,
    `reports:tie-to-ledger`, `parts:stock-count-diff`. Each is a one-call job when its module lands.

### 14.7 Payroll boundary (stated as a rule because it will bleed)

`People` computes **gross, allowances, advances deducted, deductions and net**, and posts **one payroll journal** (`Dr 6100 Salaries / Cr 2100 Net payable, Cr 1250 advances cleared`). **No payslips, no PAYE/NSSF/BWRA computation, no filings, no statutory reports** — that is the accountant's software and was explicitly out of scope. Where a statutory *payable* must sit on the balance sheet, it is an account the accountant posts via a manual journal, not a calculation we invent. This boundary is in the design doc precisely because "we also need PAYE" arrives at phase 5 otherwise.

### 14.8 Localisation and formats

English first; every string through `lang/` keys (no hard-coded literals in components — enforced by a lint rule in the local suite). One central `Formatter` for dates, numbers, money and units (`km`, `l`, `t`), reading the company's locale/currency defaults, so `BWP 1 234,56` vs `P 1,234.56` is one decision in one file. Multi-currency display: base amount always shown, original currency as a secondary line with its rate on hover/detail. `Africa/Gaborone` for display, UTC for storage (sec.2).

### 14.9 API readiness

Business logic lives in Actions with DTOs in and out (already true for the UI). Phase 0 defines the API *surface* document (`docs/api-surface.md`: routes, DTOs, money-as-strings, idempotency keys, ULID addressing) and installs Sanctum; endpoints are implemented **when an integration exists**, not speculatively. The kiosk token design (sec.9.6) is the first consumer of that surface, which is how the contract gets tested before a mobile app exists.

---

## 15. Build order

### 15.1 Why the brief's order needs three changes

| Problem in the stated order | Consequence | Change |
|---|---|---|
| The **ledger is phase 5** but fuel, parts, work orders, customs, invoices, receipts, advances and loan disbursements (phases 2–4) are all *posting events* | Retrofitting double-entry over three phases of history means either an unverifiable bulk backfill or two parallel truth systems — which is exactly where owners stop trusting the numbers | A **ledger slice** (COA v1, entries, lines, immutability, periods, `LedgerService::post`, read-only journal browser) moves into **phase 0**. Accounting *features* stay in phase 5. |
| **Cross-border (phase 6)** comes after **invoicing (phase 4)**, but rechargeable duty, permits and agent floats are *inputs to the invoice amount* | Invoice before capturing them and you issue credit notes on every trip — visible and ugly with gap-free numbering | Crossing records + border cost capture move to **phases 2–3**; permits, agent advances, reconciliation, FX and the weighbridge archive stay in **phase 6**. |
| **Hardening is phase 9**, but audit with before/after values, RBAC, MFA, document storage and numbering are worthless retrofitted | The Owner's first months are unauditable; document expiry reminders fire in phase 9 against data that arrived in phases 2 and 6 | Security, documents, numbering and audit move to **phase 0**. Phase 9 keeps restore drills, performance, deployment, runbook and guide. |
| *(missing entirely)* **Legacy data and cutover** | The single most common reason fleet systems never go live: nobody budgets for opening balances, odometer truth and the accountant's tie-in | New **phase 0.5**. |

### 15.2 Recommended sequence

Each phase ends with: a summary of what exists, a manual verification checklist, the seed data to inspect, and a **stop** for your go-ahead.

| Phase | Contents | Definition of done |
|---|---|---|
| **−1 — Platform decision execution** | Laravel Cloud organisation + environment (staging, production), region choice, **Laravel MySQL** cluster with 30-day backup retention, Valkey cache, object storage bucket for documents, SMTP domain + SPF/DKIM, DNS, env vars and secret escrow, deploy pipeline from Git, preview environments. **`git init`, `.gitignore` including `/tests`**, `README.md` stub. `laravel new` with the **Livewire** starter kit, PHP pinned to **8.4**. | A deploy of the empty skeleton reaches a working HTTPS URL; queue, scheduler and object storage confirmed live; secrets escrowed; the Cloud PHP extension list verified (`bcmath`), MySQL major version recorded in ADR-0005. |
| **0 — Foundations** | Layout + design system primitives (sec.13, `docs/DESIGN-SYSTEM.md`); owner-first setup; Fortify auth with **mandatory TOTP** + recovery codes + re-auth gate; RBAC roles/permissions/policies; audit trail + `security_events` + `operational_events`; company scope (fail-closed); money + FX + `currencies`; `number_sequences` + allocator + integrity checker; **documents + versions + private disk + signed URLs**; **ledger slice**: COA v1 seeded, entries/lines/periods, CHECKs + triggers + barrier, `LedgerService::post`, read-only journal browser; notification/`Alert` skeleton + reminder engine; queue health + failed-jobs screen; `ledger:verify`, `documents:number-integrity`, `trips:recost` stubs; Pint/Larastan/architecture lint in CI. | A login with MFA, an invited user with a role who cannot see a field they lack, a signed document download, a manually posted balanced journal (and one rejected unbalanced attempt), and an audit row showing before/after for a real edit. **No business data required.** |
| **0.5 — Legacy data intake + cutover rehearsal** | CSV import (validation preview + error report) for customers, suppliers, vehicles, trailers, drivers, employees, routes/rates, parts, **opening odometer + fuel readings**, **opening AR/AP**, **unreconciled advances**, **loan schedules and opening equity**. Opening balances post as a real journal so the trial balance ties to the accountant's figures **before** operations start. | The accountant signs off the opening trial balance; a dry-run cutover on staging completes in under a day; import error report is empty for the demo file. |
| **1 — Master data** | Fleet + trailers + equipment with timelines and assignment history; customers, suppliers; routes + rate types; drivers, employees, licences/permits with expiry tracking; parts register; COA review with the accountant; reminder engine pointed at real records. | 21+ vehicles seeded; an expired licence produces the right alert at the right threshold; vehicle timeline shows assignment + odometer + documents. |
| **2 — Operations** | Jobs → quotes → trips; dispatch board with conflict + validity checks; loading schedule; POD capture via kiosk (photo, signature, quantity, exceptions); trip costs; **chargeables**; **crossing records + border cost capture**; **GPS ingest + simulator + trip map**. | A cross-border trip can be planned, dispatched, departed, crossed, delivered, POD-approved and completed with a defensible cost rollup — and dispatch *refuses* an expired licence. |
| **3 — Fuel + maintenance** | Both tanks per truck, purchases (cards/stations/advances), issues, consumption per 100 km with expected-vs-actual variance + acknowledgement, fuel cost per trip, optional bulk tank with moving-average stock and reconciliation; service schedules by km/hours/date; work orders with approval threshold; parts issues/stock; tyres; inspections; breakdowns; maintenance history per vehicle. | Variance alert fires on a seeded "siphoning" scenario; a work order over the threshold cannot start without Owner approval; parts stock and the ledger agree after an issue. |
| **4 — Revenue** | Invoice per trip **and** consolidated billing runs; gap-free numbering at issue; VAT (rates configurable — sec.17 Q4); deposits, partial payments, allocation with residue rule, credit notes, unapplied cash; ageing buckets, overdue flags, reminders; PDF invoice + customer statement; email delivery. | Two concurrent issuers get distinct sequential numbers; a paid invoice reaches exactly zero balance through allocations only; a void is refused once a payment exists; the PDF renders correctly under the **DOMPDF** driver. |
| **5 — Accounting** | Bills/AP, expenses, bank + cash accounts with reconciliation UI, chart-of-accounts maintenance, manual journals (with reason + re-auth), period close with validation + locking, trial balance, P&L, balance sheet, cash flow, loans with instalment splits, 13-week cash forecast from known schedules, driver settlements, profitability by truck/route/customer/trip. | **A real month closes**: nothing posts into it afterwards, the trial balance balances, `reports:tie-to-ledger` is clean, and the owner's accountant agrees with the P&L. This is the phase that decides whether the system is trusted. |
| **6 — Cross-border completion** | Permits, transit documents, road-user fees, customs/clearance costs by type, weighbridge tickets with overload detection, exchange rate management + staleness warning, border-agent advances and reconciliation with variance posting, per-country cost summaries. | A Beacon–Beira corridor trip reconciles its agent float to the cent, splits recoverable input tax correctly and produces a per-country cost breakdown. |
| **7 — Telematics** | Provider interface + real adapters as hardware arrives, live map (Leaflet/OSM, no key), trip progress, planned vs actual route, ETA, delay and route-deviation alerts, geofences at depots/borders/customer sites, retention + archiving. | The simulator drives the full alert path; the integration contract (`docs/telematics-integration.md`) is written and signed off by a candidate provider. |
| **8 — Reporting + dashboard** | The report library with a **metric catalogue** (every metric has a written definition and a source query), filters, PDF/Excel exports queued for large ranges, scheduled email for the 2–3 reports you nominate, executive dashboard completed on real data. | Each report's number ties to the ledger and to the operational screen it came from; a 12-month export completes without a memory error. |
| **9 — Hardening + release** | Security review (authz on every route/action/export, encrypted columns, signed URLs), performance review (query counts, index usage, page budgets), **backup + restore drill executed and documented**, key rotation procedure, deployment config, operations runbook, user guide, final `README.md`, UAT with one real month closed. | A restore is performed from both a Cloud snapshot and the off-site export; a staff member who did not build it completes a trip-to-invoice without help. |

**Cross-phase rules:** every phase ships its own 2–4 dashboard KPI cards (the dashboard is the screen used daily and it surfaces data-quality problems early — waiting until phase 8 hides them for eight phases); every phase updates `docs/` (schemas, ADR amendments) in the same commit; every phase ends with the seed-data set needed to review it manually.

**v1 line.** Phases −1 → 5 = "run the business". Phases 6–9 = "run it properly". GPS alerting, forecast modelling, scheduled reports and any mobile app are v1.1+ candidates. Realistic total: **6–10 months** part-time for one developer, with phases 0–5 the long pole.

---

## 16. Risks and mitigations

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R1 | **Dev/prod database divergence.** Local dev is **MariaDB 11.8**; Laravel Cloud's database is **managed MySQL**. | Triggers, `CHECK`s and `STORED` generated columns exist in both, but dialects differ: MariaDB-only `SEQUENCE` (already rejected), collation defaults, `utf8mb4_0900_ai_ci` is MySQL-only, group-by strictness differs. A migration that passes locally can fail in production. | **Preferred: switch local dev to MySQL 8/9** so dev matches prod. **Measured blast radius at Phase −1: this box's single MySQL/MariaDB service also hosts seven other projects** — `takuwa` (178 MB), `ultimatepos` (55 MB), `highblossom` (24 MB), `erpgo` (17 MB / 241 tables), `skuul` (10 MB / 152 tables), `spinthewheel`, `panpharma`: ≈285 MB and ~750 tables. MySQL cannot read MariaDB's data directory, so a switch requires a full `mysqldump --all-databases` + restore, and those apps' raw SQL may then meet stricter `ONLY_FULL_GROUP_BY`, collation and function differences. **Mitigations in order of safety:** (a) run MySQL 9.1 as a **second instance on its own port for fleet-guard only** — zero effect on the other seven; (b) dump → switch → restore → re-verify each project, accepting the residual risk to them; (c) keep MariaDB and enforce the dual-engine subset (below) until a MySQL is available. Otherwise the design doc keeps an explicit "engine surface used" list (this file), every migration is run against staging before merge, and MariaDB-only constructs are banned by the architecture lint. Decided at phase −1 (sec.20.1): **stay on MariaDB locally until the ledger slice**, then choose between a MySQL instance on its own port and a dump-and-global-switch. |
| R2 | **No grant-level ledger immutability on Cloud** (users are read-only/read-write; the injected user cannot be edited). | A person with DB credentials can mutate posted entries and the app will not know. | Layers 1–4 of sec.6.3 (app guards, CHECKs, triggers + barrier, period-lock trigger) + `ledger:verify` in the scheduler + Cloud daily backups and ~8 days of binary logs + a documented forensic restore procedure. Stated honestly rather than pretended to be physics. |
| R3 | **Backup/export constraint.** Cloud snapshots are not downloadable, and restores create a *new* cluster. | No simple `mysqldump` cron to an off-site disk; a restore is a two-step operation, and an unnoticed retention setting means no backup at all. | sec.14.5: 30-day retention as an infrastructure decision, monthly encrypted application-level export to a *different provider*, `backup_health` staleness alert, and a **restore drill as a phase-9 acceptance test**, not a hope. |
| R4 | **Full in-app accounting is now the biggest scope item** (your decision). | Phase 5 is ~2–3 months, not ~3 weeks, and its correctness bar is "an accountant signs it". | Ledger slice in phase 0; ADR-0001; a COA workshop with your accountant **before** the COA is seeded in phase 0 (it is `is_system` data afterwards); `reports:tie-to-ledger` as the trust mechanism; the payroll boundary fixed in sec.14.7 so scope cannot creep. |
| R5 | **Fiscal and regulatory unknowns that change schema**: BRBS/eTIMS VAT mechanics, gap-free tax-invoice requirements, per-year vs financial-year restart, zero-rating of export/cross-border freight, retention periods, SADC permit/roadworthiness regimes, driver-hour rules. | Wrong answers here are not refactors; they are amended returns. | One hour with the accountant before phase 0 freezes numbering and COA. Each unknown is an open question with a **default and a switch** (sec.17), so being wrong is a config change. |
| R6 | **GPS hardware/provider dependency** — cost, coverage at remote posts (Beitbridge, Kasarito, Tuli, Ponton), API churn. | Phase 7 could stall on procurement, and "live tracking" is the feature most likely to be sold to you as easier than it is. | Adapter + simulator is a first-class deliverable with a written integration contract; `gps_positions` is provider-agnostic with webhook dedupe; a 4-week local buffer; and **no business invariant depends on GPS being online** — fuel variance, trip distance and ETA fallback all compute from odometer and trip facts. |
| R7 | **Garbage-in data**: odometer trust, pump vs driver-reported litres, aux-tank honesty, missing POD photos, unrecorded cash advances. | Every "profit per truck" number becomes arguable, and the owner stops using the reports. | Variance is an **alert with an acknowledgement workflow**, never an auto-correction; opening/closing readings required at `departed` and `arrived` with plausibility bounds; per-trip exception register so reports can run "excluding disputed"; `plausibility` column on every reading. |
| R8 | **Scope vs a single developer** (12 modules, one owner, no team). Bus factor 1, and the risk that nothing ships. | 6–10 months; the failure mode is a half-built system with no cutover. | Re-sequenced phases make −1 → 5 a usable v1; git from day one with a tag per phase; ADRs as institutional memory; `docs/operations/runbook.md` so a second developer can continue; the sec.17.2 cut list. |
| R9 | **Practical path issue**: the workspace is `C:\laragon\www\Fleet Guard` — a space in the docroot, and not yet a git repo. | Vite/Laragon vhost/composer package-name friction that gets more expensive after phase 2. | Create the project as `C:\laragon\www\fleet-guard` with `APP_NAME="Fleet Guard"`, `git init` in phase −1. Documents stay where they are until then. |
| R10 | ~~**UI component licensing**: the Livewire starter kit ships **Flux UI**, while your brief asks for our own design system.~~ | Shipping on a component library whose production terms we did not read is a self-inflicted legal problem, and mixing two component systems is how a design system rots. | **Closed 2026-10-26.** `livewire/flux` was uninstalled and every screen the kit generated was re-authored on our own Tailwind v4 primitives (`resources/views/components/ui/*`, token contract in `docs/DESIGN-SYSTEM.md`), so the licensing question no longer applies and there is exactly one component system (`DESIGN.md` sec.20.2). The residual risk moved from legal to maintenance: **we now own 31 primitives and their accessibility**, which is why the primitive inventory, required states and a11y rules are specified rather than improvised. |

---

## 17. Open questions

Blocking means a *schema or phase-size* decision depends on the answer. Defaults exist so nothing stalls; a default that turns out wrong should be a config change, not a migration.

### 17.1 Blocking

| # | Question | Why it matters | Default if unanswered |
|---|---|---|---|
| Q1 | **Who closes the books today, and in what software?** | Decides how much of phase 5 is first-class UI (statements, reconciliation, year close) versus a clean journal export for an accountant. | Build statements + period close in-app (you chose full accounting); keep `external_ref` on accounts and a journal export so the accountant can double-key or review. |
| Q2 | Does **BRBS/eTIMS** require tax-invoice sequences to be strictly gap-free per year, and must a voided tax invoice be corrected by credit note only? | Decides whether the row-lock allocator and the nightly integrity checker are load-bearing or merely defensive. | Keep the gap-free allocator (it satisfies the stricter reading of both) and constrain voids as sec.7.4. |
| Q3 | **Financial year-end** for numbering restart and period locking (calendar year, or a Botswana FY such as `FY26/27`?) | `period_key` scoping and `gl_periods` boundaries. | `period_key` per scope, defaulting to **continuous** for operational references and **calendar year** for fiscal documents; both changeable by the Owner in settings. |
| Q4 | **VAT**: standard rate, your registration number, and the zero-rating treatment for export/cross-border freight legs? | `tax_rates`, invoice lines, output/input split, and whether duty is expense or recoverable input tax. | Configurable `tax_rates` table (default 12%), zero-rated line type available, duty → expense with a per-account recoverable flag. |
| Q5 | Is **parts inventory** a real staffed stores with stock counts (perpetual), or an issue register with occasional counts? | Perpetual inventory brings cycle counts, adjustments, negative-stock fights and a `parts` ledger account. An issue register is a week of work instead of a month. | **Issue register with optional stock counts**; the `stock_movements` table exists either way, so going perpetual later is enabled, not rewritten. |
| Q6 | ~~**Flux UI** production licence acceptable, or own Tailwind primitives?~~ | One component system, and a legal answer before shipping. | **Closed 2026-10-26 — resolved by removal, not by licence review.** Owner: "We'll make our own flux ui". `livewire/flux` is uninstalled and `resources/views/components/ui/*` is the system of record for every surface, auth and settings included (sec.20.2). |
| Q7 | **GPS provider**: shortlist, cost per vehicle/month, and does it offer a push webhook? | Phase 7's adapter count; pull-polling changes the ingest design (cron + rate limits instead of webhooks). | Build the interface + simulator only; document the contract; assume webhook ingest, fall back to a scheduled pull adapter. |
| Q8 | Is a **virus-scanning** service acceptable for document uploads (hosted API), or do we enforce a strict type allowlist with no scanning? | On Cloud there is no local ClamAV; the brief asked for virus validation. | Allowlist `pdf \| jpg \| png \| heic \| webp` + content-MIME sniffing + no-execution storage, with a pluggable `VirusScan` contract so a hosted scanner can be added. |

### 17.2 Also needed, not blocking

Which **2–3 reports** earn scheduled email (I will build the delivery mechanism once and the definitions for what you name) · which **roles** you will actually staff in the first 12 months (the matrix has 7; you may need 3) · **insurance/finance** data feeds for loans · who is the **second approver** for period close (single-owner today, but the workflow needs a name) · whether **fuel advances to drivers** are cash or card, since it changes the settlement posting · WhatsApp notifications (deferred, but tell me if this is a must-have — it changes the notification layer) · **depot/geofence coordinates** · whether trailers and equipment need their own **ROI** reporting (affects the dimension set on lines) · the **demo data shape** you want to inspect after each phase (I will mirror your real corridors: Gaborone–Windhoek, Gaborone–Durban, Beira, Dar es Salaam, Lusaka) · **how the final rehearsal treats balances that require dimensions** — receivables, payables, driver advances and loan lines (1150, 1160, 2140 in COA v1) cannot enter the opening trial balance until the Phase 1/5 importers carry truck/customer/driver columns, so the choice is a control total on a clearing account for the rehearsal or waiting for those importers (today the validator refuses such a row and names the account; see sec.20.12).

---

## 18. Traceability: every requirement in the brief

| Brief section | Where it is answered |
|---|---|
| Executive dashboard | sec.3.2 (permission-aware cards), sec.6.6 + sec.14.3 (rollups/cache), sec.15 (cards ship per phase), sec.9.3 (who sees which figure) |
| Fleet and trailer management | sec.4.3 `TRUCK`/`TRAILER`/`EQUIPMENT`/`VEHICLE_ASSIGNMENT`/`ODOMETER_READING`, sec.10.2 (status checks), sec.15 phase 1 (timeline) |
| Load, booking and dispatch | sec.4.1 (`JOB`/`TRIP`/`LOAD`/`PROOF_OF_DELIVERY`/`ROUTE_RATE`), sec.10.2 (transition gates incl. expired-document warnings), sec.9.6 (kiosk POD), sec.15 phase 2 |
| Fuel management | sec.4.3 (`FUEL_TANK` main+aux, `FUEL_PURCHASE`, `FUEL_ISSUE`, `BULK_TANK`, `FUEL_VARIANCE`), sec.6.8 postings, sec.5.5 litres precision, sec.15 phase 3 |
| Maintenance and workshop | sec.4.3 (`SERVICE_SCHEDULE`, `WORK_ORDER(_LINE)`, `TYRE(_POSITION)`, `PART`, `STOCK_MOVEMENT`, `INSPECTION`, `BREAKDOWN`), sec.10.6, sec.15 phase 3 |
| Invoicing and customer payments | sec.4.2, sec.7, sec.10.4, sec.6.8, sec.15 phase 4 |
| Accounting and profitability | sec.6 (all), sec.14.5, sec.15 phase 5, sec.16 R4 |
| Driver and employee management | sec.4.3 `DRIVER`/`EMPLOYEE`, sec.9.6 (kiosk), sec.14.7 (payroll boundary), sec.6.8 postings, sec.15 phases 1–2 + settlement in 5 |
| Cross-border SADC operations | sec.4.4 (`BORDER_POST`, `BORDER_CROSSING`, `BORDER_COST`, `PERMIT`, `WEIGHBRIDGE_TICKET`, `AGENT_CASH_ADVANCE(_RECONCILIATION)`), sec.5 (FX), sec.10.9, sec.15 phases 2/3 + 6 |
| GPS tracking and route planning | sec.4.4 (`GPS_POSITION`, `GEOFENCE`, `ALERT`, `GPS_PROVIDER_ACCOUNT`), sec.12.2 (Reverb deferred), sec.14.3 (volume strategy), sec.16 R6, sec.15 phases 2 + 7 |
| Document management | sec.4.3 (`DOCUMENT`, `DOCUMENT_VERSION`), sec.9.5 (access + audit), sec.14.4 (private disk, signed URLs, scanning), sec.10.8 |
| Loans and cash flow | sec.4.2 (`LOAN`, `LOAN_INSTALLMENT`, `BANK_ACCOUNT`), sec.10.7, sec.6.8, sec.15 phase 5 |
| Reports and analytics | sec.3.2, sec.13 (`Reporting/`), sec.6.9 (metric tie-out), sec.14.3 (queued exports), sec.15 phase 8 |
| Security and user permissions | sec.9 (all), sec.14.5 (backups), sec.16 R2 |
| Money and currency | sec.5 |
| Accounting core | sec.6 |
| The trip as the hub | sec.4.1, sec.6.6, sec.10.2 |
| State machines | sec.10 |
| Security (owner-first, RBAC, MFA, audit, protected records) | sec.9.1–sec.9.5 |
| Documents and backups | sec.14.4, sec.14.5 |
| Notifications | sec.14.1 |
| Performance | sec.14.3 |
| Design system | sec.13, `docs/DESIGN-SYSTEM.md` |
| Layout | `docs/DESIGN-SYSTEM.md` sec.7 |
| Localisation and formats | sec.14.8 |
| API readiness | sec.13 (`Http/Controllers/Api/V1`), sec.14.9, sec.9.6 (kiosk as first consumer) |
| Data import and seeding | sec.13 (`seeders/Production` vs `seeders/Demo`), sec.15 phase 0.5 |
| Constraints: no committed tests | sec.14.6 |
| Constraints: no invented data | sec.2 (last row), sec.13 seeder split |
| Constraints: prefer well-maintained packages, list reasons | sec.12 |
| Constraints: flag hard-to-reverse decisions | sec.19, `docs/adr/0001–0006`, sec.16 R1–R4 |
| Constraints: never commit secrets | sec.14.5, sec.9.5 |

---

## 19. Where I propose a different design than the brief implied

The brief invited this section. Five items, each with the reason and what it costs to ignore.

**1. `invoiced` must not be a trip state, and a trip must not be a job.**
The brief describes trips that get invoiced, which reads as one-to-one. In practice one job produces several invoice lines of different types (freight, detention, accessories, customs recharge) and one invoice routinely bundles many trips for a monthly customer statement. Modelled as one status field, consolidated invoicing forces a fake relationship and per-trip invoicing forces duplicate invoices. Cost of ignoring: a rewrite of the invoicing module after the dispatch board exists.

**2. Ledger posting must not be a work-order state.**
"A complete maintenance history per vehicle" plus monthly period locking means parts post when stock leaves and labour posts when approved. Gate posting on `closed` and both the parts inventory and the closed month are wrong mid-period. Cost: month-end discrepancies discovered at year-end — the worst possible time.

**3. The ledger slice must move to phase 0, and cross-border cost capture must move earlier.**
Argued in sec.15.1. The design stays exactly as you specified; only the order changes. Cost of ignoring: two truth systems, or a backfill you cannot validate.

**4. Profitability reports should read operational tables, with the ledger as the statement of record — not ledger-only reporting.**
You chose full in-app accounting, so the ledger *is* the statutory record; but if per-truck profit is derived only from GL postings, every unposted bill is an invisible wrong answer on the dispatch screen. The design therefore reads the write-time rollups on `trips`/`trucks` for operational screens, reads the ledger for statements, and runs `reports:tie-to-ledger` so a disagreement is a visible alert rather than a suspicion. Cost of ignoring: the owner distrusts a number that was actually *correct* but late.

**5. A cash-flow "forecast" is scoped as a 13-week roll-up of known schedules, not a modelling engine.**
The brief asks for projected shortage warnings. Built from invoices by due date, scheduled instalments, recurring costs and known supplier commitments, that is honest and cheap. Anything scenario-shaped or probabilistic is a research project for a 21-truck business and will be maintained by nobody. Cost of ignoring: a feature that looks sophisticated and is quietly wrong the first time a customer pays late.

**Also stated, not proposed:** `spatie/laravel-backup` as the database backup path is not available on Laravel Cloud (sec.16 R3), Horizon is not available on the dev box and not needed on Cloud (sec.12.2), and the strongest ledger-immutability layer (INSERT-only grants) is not available on Cloud (sec.6.3.5). Three places where your intended design meets a platform limit; in each case the substitute is documented rather than hidden.

---

## 20. Verification log

Each phase records what was asserted in this document against what the machine actually did. Entries here supersede the prose above where they disagree.

### 20.1 Phase −1 — 2026-10-26, local environment and fresh scaffold

Scaffolded with `laravel new fleet-guard --livewire --database=mysql --git --no-boost --npm`.

| Claim in this document | Reality | Action taken |
|---|---|---|
| Laravel 13, Livewire 4, Fortify-backed kit, 2FA default-on, `/user/confirm-password` present | ✅ `laravel/framework` 13.38.0, `livewire/livewire` 4.4.8, `laravel/fortify` 1.41.0; `config/fortify.php` ships `twoFactorAuthentication(confirm, confirmPassword)` and `pages/auth/confirm-password.blade.php` | None. |
| Registration is disabled by removing `Features::registration()` | ✅ present and **enabled by default** — the kit also generates `pages/auth/register.blade.php` | Phase 0 removes the feature **and** the register view/route, not just the config line. |
| "Passkeys deferred; WorkOS variant would provide them" (sec.9.1) | ❌ **Wrong**: `laravel/passkeys` 0.2.1 is installed first-party, `Features::passkeys(['confirmPassword' => true])` is already enabled, and the kit ships `passkey-registration`/`passkey-verify` components + a `.well-known/passkey-endpoints` route | sec.9.1 amended. TOTP-first sequencing unchanged; the reason for deferring is now policy, not capability. |
| Livewire 4 SFC filename convention "verify at install" (sec.13) | ⚠ Verified: SFCs are written with a **literal `⚡` glyph in the filename** (`resources/views/livewire/settings/⚡appearance.blade.php`); `make:livewire` supports `--sfc/--mfc/--class` | sec.13 amended with the Windows/git consequences and a pre-UI checkpoint. |
| Flux licence unknown (Q6, R10) | ⚠ Evidence gathered: composer `license: proprietary`; free components ship in the kit, the rest are the paid Pro tier (`livewire/flux-pro`) | **Superseded by sec.20.2** — the owner chose to author our own UI, and `livewire/flux` was uninstalled rather than licensed. |
| `brick/money` needs `ext-bcmath` (ADR-0004, sec.5.1) | ✅ `bcmath` **is** loaded on PHP 8.4.27; `intl`, `openssl`, `gd`, `zip`, `curl`, `pdo_mysql`, `fileinfo` also present. **`gmp`, `sodium` and `redis` are not.** | sec.14.5 amended: encrypted export uses AES-256-GCM via openssl, not Sodium. Cloud extension list still to be confirmed. |
| Local cache/queue/session on Redis/Valkey | ⚠ No `redis` PHP extension locally, so the scaffold's `.env` correctly defaults to `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`; Mailpit is available for SMTP | Accepted for dev (database drivers); Cloud uses Valkey. Dev queue behaviour differs from prod, so **queue health screen and `queue:monitor` stay in Phase 0**. |
| MariaDB 11.8 dev vs managed MySQL prod (R1) | ⚠ Both engines are installed on this box: `C:\laragon\mysql` = MariaDB **11.8.2** (running) and `C:\ProgramData\envkit\services\mysql\` = **9.1.0**. The single shared service also hosts **seven other projects** (~285 MB, ~750 tables: takuwa, ultimatepos, highblossom, erpgo, skuul, spinthewheel, panpharma). MySQL cannot read MariaDB's data directory, so a global switch means a full dump and restore of all seven. | **Owner decision 2026-10-26: stay on MariaDB for now.** The engine question is re-opened **immediately before the Phase 0 ledger-slice migrations** — the first DDL where production-engine fidelity actually matters. Until then every migration is written to the MySQL-8-compatible subset (no MariaDB-only `SEQUENCE`, explicit collations, `ONLY_FULL_GROUP_BY`-safe queries), and the ledger CHECK / trigger / generated-column DDL is exercised against MySQL 9.1 (on its own port, or Cloud staging) before it is trusted. |
| Project path contains a space (R9) | ✅ Resolved: app created at `C:\laragon\www\fleet-guard`; design docs relocated into the repo | R9 closed. |
| `composer.json` requires `php: ^8.3` | Kept as `^8.3`; the **runtime** is what we pin (local 8.4.27, Cloud to be set to 8.4) | Recorded so ADR-0005's "pin 8.4" is read as a runtime setting, not a composer constraint. |

**Not yet verified (carried into the next phases):** the Laravel Cloud PHP extension list (`bcmath`, `redis`/Valkey client, `intl`, `gd`), Cloud's MySQL major version, `spatie/laravel-backup` L13 tag, and whether the `⚡` SFC filenames survive Tailwind v4 source scanning + Pint + Larastan cleanly.

### 20.2 Flux removal and own-component rebuild — 2026-10-26

Owner decision, in the owner's words: **"We'll make our own flux ui, proceed."** This closes Q6 and R10 by removal rather than by licence review: `livewire/flux` is **uninstalled**, and `resources/views/components/ui/*` is the single component system of record for every surface, including the kit's auth and settings screens.

| What was done | Evidence |
|---|---|
| 31 primitives authored (`resources/views/components/ui/*.blade.php`): button, input, textarea, select, checkbox, radio, otp, switch, badge, avatar, icon, card, dialog, dropdown, menu, table, th, td, tabs, navlist, tooltip, popover, heading, text, link, separator, skeleton, empty-state, pagination, toast + toast/group | Blade compiles clean: `php artisan view:cache` succeeds; `pint --test` PASS (48 files); `phpstan analyse` (Larastan level 7) `[OK] No errors`. |
| `composer remove livewire/flux` | `composer.json` has zero flux entries; `grep -i flux` over `app/ resources/ config/ routes/ bootstrap/` returns nothing. |
| Icon set rebuilt from Heroicons MIT artwork already on disk (`vendor/livewire/flux/stubs/**/flux/icon`), extracted by `scripts/build-icon-registry.php` | 59 icons in `components/ui/icon.blade.php`, 0 residual Blade/Flux markup. |
| Token layer: `resources/css/app.css` `@theme` + `.dark` custom variant, IBM Plex Sans/Mono via `vite-plugin-fonts` | Built CSS dropped **418.82 kB → 45.09 kB** (gzip 9.10 kB) when Flux's generated utilities went away. |
| Theme bootstrap moved to our own mechanism (`window.FleetGuardTheme`, `localStorage` key `fg_theme`, `@include('partials.head')`), replacing `@fluxAppearance` | `evaluate_script` in a real browser: dark mode → body `rgb(13,15,14)`, accent `#2dbbae`, on-accent `#04211e`; light mode → body `rgb(247,247,244)`, accent `#0f766e`, white button label, `color-scheme: light`. Toggle flips `<html class="dark">` and persists. |
| Toast pipeline replaces `Flux::toast()` → `$this->dispatch('toast', …)` bridged into `Alpine.store('fgToasts')` | Pushing a toast renders inside the `aria-live="polite"` region with the kind-coloured border; auto-dismisses. |
| Every starter-kit `data-test` hook preserved (`login-button`, `logout-button`, `sidebar-menu-button`, `mobile-menu-button`, `update-profile-button`, `delete-user-button`, `confirm-delete-user-button`, `update-password-button`, `reset-password-button`, `email-password-reset-link-button`, `confirm-password-button`) | Present in the served HTML of the corresponding pages. |

**Routes checked after the rebuild** (authenticated where noted): `/login` 200, `/register` **404** (registration removed from `config/fortify.php`, `Fortify::registerView()` removed, view deleted), `/forgot-password` 200, `/reset-password/{token}` 200, `/two-factor-challenge` → `/login` for a guest, `/email/verify` → `/login` for a guest, `/user/confirm-password` → `/login` for a guest, `/` → 302 → `/dashboard` when authenticated, `/dashboard` 200, `/settings` → `/settings/profile` 200, `/settings/appearance` 200, `/settings/security` → `/user/confirm-password` 200 (the sensitive-action re-auth gate from sec.9.1 firing as designed). Zero `ParseError`/`ViewError` responses and zero flux markers in any of them.

**Defects the verification caught and fixed** — recorded because each is a class of mistake that recurs in Phase 0:

1. **Blade directives are illegal inside a component tag's attribute list.** `<x-ui.navlist.item … @if($collapsed) class="…" @endif >` compiled without complaint but threw `ParseError: syntax error, unexpected token ":"` at *render* time, blanking `/dashboard`. `php artisan view:cache` does **not** catch this (it compiles Blade to PHP; PHP is only parsed on include). Fix: move the condition into the component as a `collapsibleLabel` prop. **Consequence for the quality gates: a Blade-syntax check must also *render* every page, not only compile it.**
2. **Compile-time props cannot express a runtime-only state.** The desktop rail passes `collapsed => true` into the nav partial at render time while the actual collapse is Alpine state, so labels and section headings were permanently hidden even at full width. Fixed with `rail => true`, which emits `x-show="! collapsed"` / `:class="collapsed ? 'justify-center px-0' : ''"` so the browser, not Blade, decides. Same treatment for `x-app-logo` and `x-desktop-user-menu` via new `collapsible` props. Verified: expanded rail = 256 px with labels and headings; collapsed = 72 px, labels and headings gone, `fg_sidebar_collapsed` persisted.
3. **The `@else` branch of the nav partial left an orphan `</div>`** from the original single-branch markup — caught while fixing (1).
4. **Tailwind v4 cannot see class names built by string interpolation**, so `size-{{ $size }}` / `w-{{ $width }}` silently emit no CSS. All size/width/variant maps are now literal strings inside the components. Confirmed in the built CSS: `.duration-\(--t-fast\)`, `.w-\[72px\]`, `.lg\:ps-\[72px\]`, `.bg-accent-soft`, `.text-on-accent`, `.shadow-fg-3`, `.justify-center`, `.px-0` all present.
5. **The in-app verification browser reports a 0×0 viewport**, which makes `lg:` layouts evaluate as *hidden*. Consequences: `take_screenshot` fails with `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`, pointer tools no-op, and Alpine's `x-transition` never receives `transitionend` inside the hidden subtree — which first looked like a broken dropdown and is in fact a measurement artefact. Verified instead by reading `x-show`/`:class` state, inline styles and `Alpine.evaluate()`: the account menu goes `display:none → block → none` across open/click/close and reports `aria-expanded` correctly.

**Verification status and the honest gap.** Structural and computed-style verification is complete for every route above, in both colour schemes, in light of a real DOM. **Pixel-level verification is not**: because of (5), the desktop sidebar, the mobile drawer, and dialog overlays have not been *seen* rendered. The manual checklist below is what a human on a real display must confirm before this phase is called done. No seed data exists yet — the only row in the database besides the two pre-existing kit users is the dev-only `owner@fleet-guard.test` account created for this pass.

### 20.3 Phase 0 gate — 2026-10-26, dev database engine resolved (R1, sec.20.1 carry-over)

Owner chose **MySQL 9.1.0 on its own port**, closing the question sec.20.1 deferred. Chosen because the ledger DDL in sec.6.3 is the first place where engine fidelity is not negotiable, and because the switch turned out to cost nothing: MariaDB 11.8.2 keeps port 3306 and the seven other projects are untouched. Full operating notes in [docs/DEV-DATABASE.md](docs/DEV-DATABASE.md).

| Assertion | Reality on the new instance | Consequence |
|---|---|---|
| `CHECK` constraints are enforced (not parsed and ignored) | ✅ a negative minor-unit amount and a line carrying both debit and credit are both refused with `ERROR 3819` | sec.6.3 layer 2 is real. |
| `BEFORE UPDATE` / `BEFORE DELETE` triggers using `SIGNAL SQLSTATE '45000'` | ✅ both refuse the write with `ERROR 1644 (45000)` | sec.6.3 layer 3 is real. |
| `GENERATED ALWAYS AS (…) STORED` columns materialise and index | ✅ `Extra: STORED GENERATED`, readable | `base_amount_minor` can be a stored column where the expression is pure (sec.5.3). |
| `SELECT … FOR UPDATE` takes a row lock | ✅ lock held for the transaction's lifetime | ADR-0002's gap-free allocator works as designed. |
| Kit migrations run on MySQL with `utf8mb4_0900_ai_ci` | ✅ all five applied | Dev now uses a MySQL-only collation MariaDB cannot even parse. |
| **Unpublished discovery** | ❌→✅ `CREATE TRIGGER` by an account without `SUPER` fails with **`ERROR 1419 … binary logging is enabled`** until `log_bin_trust_function_creators=1`. Set to `1` locally and re-verified: the unprivileged app account then creates and *owns* the trigger (`Definer: fleetguard@127.0.0.1`). | **This is a Cloud risk, not a dev annoyance.** Cloud's injected DB account is read-only/read-write with no `SUPER` (sec.20.1, R2), so if managed MySQL ships `log_bin_trust_function_creators=0`, **the ledger migration cannot create the immutability triggers at all** and sec.6.3 silently loses layer 3. Added to the Phase −1 Cloud checklist as a blocking confirmation; if it comes back `0` and is not settable, ADR-0001 is amended to state that immutability rests on app guards + `CHECK` + `ledger:verify` alone. |
| Dev/prod strictness parity | ⚠ `ONLY_FULL_GROUP_BY` and `utf8mb4_0900_ai_ci` are now ON locally, which MariaDB was not | Expected effect: queries that were quietly wrong on MariaDB will now fail loudly here rather than in production. Treat new group-by errors as real defects. |

Two local-only facts worth recording: the July 2026 MySQL 9.1 install could not be reused because its existing data files are read-only to this user (`binlog.index … errno 13`), and `mysqld --initialize-insecure` plus `skip-name-resolve` leaves **no reachable account** (only `root@localhost`), which is why the instance boots with an `--init-file` that provisions one. A fresh, writable data directory also avoids the stale `highblossom` copy sitting in the old one.

**Reset note:** `fleet_guard_dev` on 3307 is a different database from the one on 3306, so the dev owner account from sec.20.2 does not exist here. It is recreated by the Phase 0 owner-first setup seeder rather than by hand.

### 20.4 Phase 0 slices 1–4 — 2026-10-26, tenancy, money, numbering

Built: the folder contract's enforcement ([scripts/lint-architecture.php](scripts/lint-architecture.php), rules D1–D6, `composer lint:architecture`), the tenancy Kernel (sec.8 T1–T5), the money/FX layer (sec.5, ADR-0004) and the numbering Kernel (sec.7, ADR-0002). `pint --test` PASS, `phpstan` (Larastan 7) `[OK] No errors`, architecture lint PASS, and `php scripts/verify-kernel.php` reports **33/33**.

| Claim in this document | Reality | Action taken |
|---|---|---|
| sec.7.2 step 1: "INSERT IGNORE the sequence row first, so the lock lands on an existing row" | ❌ **This deadlocks.** Measured with 4 concurrent workers on MySQL 9.1: `SQLSTATE[40001] Serialization failure: 1213 Deadlock found` on the `SELECT … FOR UPDATE`. `INSERT IGNORE` on an existing row takes a **shared next-key lock**, so the next statement is an S→X upgrade — two writers doing that is the textbook InnoDB deadlock, and it reproduced in the steady state, not only during row creation. | sec.7.2 amended. Allocation never inserts: rows are pre-created by `NumberSequenceRegistry` / `numbering:ensure` (scheduled 00:10 daily), a missing row is a loud error, and the Action wraps its transaction in `attempts: 3`. Re-measured: 60 concurrent allocations → 60 distinct, gapless, zero deadlocks. |
| sec.6.3 layer 2 is a DB `CHECK` constraint | ⚠ Works — but **Laravel's Blueprint cannot express it**: `Blueprint::check()` does not exist on 13.38, so the constraint is raw `ALTER TABLE … ADD CONSTRAINT … CHECK` DDL. | Recorded: every CHECK in the ledger slice is raw DDL, which must hold on both engines (sec.16 R1 subset). `CHECK (next_value > 0)` verified — an attempt to rewind the counter is refused with `ERROR 3819`. |
| sec.5.1 "brick/money so arithmetic never touches a float" | ⚠ Necessary but **not sufficient**: installed `brick/math 1.0` **silently truncates floats** — `BigDecimal::of(0.1)` → `0`, `of(1.5)` → `1`. It has `fromFloatExact()`/`fromFloatShortest()` precisely because `of()` does not accept floats safely. | `Money::ofMinor()` and the `MinorUnits` cast declare `float` in the signature **specifically to refuse it**, because strict typing is decided by the *calling* file and Blade/Livewire files are not strict — a float would otherwise arrive pre-truncated. Verified: float and decimal-string inputs both throw. |
| sec.5.2 the FX block, sec.5.3 which number wins | ✅ `base_amount == round(amount × fx_rate)` HALF_UP verified at the half-minor boundary (`1.00 × 1.005 → 1.01`, not banker's rounding); back-solve reproduces the edited base amount exactly; same-currency rate ≠ 1 is refused, which is what protects the "always 1 foreign = X base" convention from an inverted feed. | As designed. `Fx` is one object holding all eight columns rather than a `FxEditor`+DTO pair (sec.13 named both) — a single invariant-enforcing type is the smaller surface; noted as a naming deviation, not a design change. |
| sec.8 T3 "fail closed: throws instead of returning unscoped rows" | ✅ Verified on a real model: an unscoped read of `exchange_rates` with no context throws; `create()` auto-stamps the company; writing a row for company 2 under a company-1 context is refused; company 2 sees 0 of company 1's rows. | As designed. |
| sec.8 T2 "cached per request" | ⚠ A **tenant leak** was found while testing this: `CurrentCompany::forget()` cleared the memo but not the forced id, so a queued job that pinned company 1 would run the *next* job on the same worker against company 1. | `forget()` now clears the pin too, `force()` clears only the memo (calling forget() there would undo its own work), and `DomainServiceProvider` listens for `JobProcessed`/`JobFailed` to drop the context between jobs. Re-verified: the unscoped read throws again. |
| sec.9.1 mandatory MFA, sec.10 state machines, sec.6 ledger | ⬜ Not started — the ledger slice is next and is the reason sec.20.3's engine gate was opened. | — |

**Two facts about the app shell that will bite Phase 1+ and are therefore written down here:**

1. **`Date::use(CarbonImmutable::class)` is on** (`AppServiceProvider`), so `now()` returns a `CarbonImmutable`, **which is not a subclass of `Carbon`**. Every Kernel signature that took `Illuminate\Support\Carbon` rejected the application's own clock. All date parameters now take `DateTimeInterface|CarbonImmutable` and normalise through `Carbon::instance()`. New domain code must follow this or it will fail at runtime, not at analysis.
2. **Larastan's relation generics are invariant**: `@return BelongsTo<X, self>` and `<X, static>` both fail on `$this->belongsTo()`. The accepted form is `@return BelongsTo<X, $this>`.

**Environment note.** `.env` in this project was rewritten by something outside the agent's commands mid-session (its mtime postdated every operation, and it came back internally inconsistent — `fleetguard` username with the old `root` port). The DB block has been re-applied and verified stable, and `.env.example` now documents the 3307 default **without** a password, since that file is committed and the brief forbids secrets in the repository.

### 20.5 Phase 0 slice 5 — 2026-10-26, identity, invitations, field gates, audit columns

Built between sec.20.4 and the ledger slice: the permission catalogue as the machine-readable sec.9.3 matrix, the RBAC roles with the company team key, the sec.9.4 field gate, the sec.9.5 encrypted-column trio with its key version, the invitation lifecycle (sec.9.1) and the bank/cash register that gives those rules a real field to refuse. `pint --test` PASS, `phpstan` `[OK] No errors`, architecture lint PASS, `php scripts/verify-identity.php` **33/33**.

| Claim in this document | Reality | Action taken |
|---|---|---|
| sec.9.5 "the row that stores the secret also records the key" | ✅ ciphertext, mask and key version are written together by one method; a wrong key version refuses to read, a tampered ciphertext fails authentication, and a list renders without decrypting anything. | As designed. |
| sec.9.4 "an export is an authorised read, not a side door" | ✅ reveal returns `null` rather than throwing when the gate is closed, so a component that forgot to ask prints the mask instead of the secret. | As designed. |
| sec.9.1 "the link itself is the proof of the address" | ✅ the invitation row stores only the token's hash; single use; expiry enforced; the Owner role is never grantable by invitation. | As designed. |
| sec.8 roles are company-keyed | ⚠ a **stale `roles` relation** is the only way a cross-company grant can appear: the permission check consults a relation loaded before the context changed. | Documented as a known shape; the identity verifier asserts it, and the guard is "reload rather than reuse" on context change. |
| Kit's `⚡` SFC filenames survive the toolchain (sec.13's checkpoint) | ✅ Tailwind scanning, Pint, Larastan and the architecture lint all behave on the UTF-8 names; `git status` quotes them, which is cosmetic. | Checkpoint closed: keep the `⚡` form. |

### 20.6 Phase 0 slice 6 — 2026-10-26, the ledger

Built: `gl_accounts`, `gl_periods`, `gl_ledger_barrier`, `gl_journal_entries`, `gl_journal_lines` with the sec.6.3 CHECKs and triggers on MySQL 9.1; the four Kernel/Ledger models with layer-1 immutability; `LedgerService::post()` as the only write door; `PostManualJournal` as the screen-facing door; COA v1 (41 rows, 32 postable); the read-only journal browser and the manual journal screen behind the sec.9.1 re-auth gate; `ledger:verify` in the daily schedule. Gates: `pint --test` PASS, `phpstan` `[OK] No errors`, architecture lint PASS, `verify-kernel` 33/33, `verify-identity` 33/33, `scripts/verify-ledger.php` **35/35**. The sec.15.2 definition of done was exercised in the browser: a balanced journal posted through the screen (`JV-FY26/27-000005`), an unbalanced attempt refused on screen with the difference in the message, and an audit row carrying before/after values for a real edit.

**sec.6.3's four layers, each measured rather than assumed:**

| Layer | Measurement on MySQL 9.1 |
|---|---|
| 1 — models refuse | `JournalEntry` update refused (`only draft -> posted|void is legal`), delete refused, `JournalLine` update/delete refused. |
| 2 — entry CHECK | posted-and-unbalanced, posted-with-one-line, draft-carrying-totals and same-currency-rate-≠-1 all refused with `ERROR 3819`. |
| 3 — triggers | raw `UPDATE`/`DELETE` on lines and entries refused with `ERROR 1644 (45000)`; the barrier row lifts them and lowering it again restores them. |
| 4 — period lock | `BEFORE INSERT` refuses both a `locked` period and a period row that does not exist. |

**Findings that changed the design, the code, or the way a claim must be worded:**

| Claim or assumption | Reality | Consequence |
|---|---|---|
| sec.6.7 `UNIQUE(company_id, source_type, source_id, source_event_id)` is the replay guard | ⚠ **As written it is not.** MySQL treats NULLs as distinct inside a UNIQUE index, so a nullable `source_event_id` lets any number of duplicate postings through the constraint that exists to stop them. | The three source columns are `NOT NULL` with `''` defaults. Measured: a replay of the same triple is refused with `ERROR 1062`. A manual journal must mint its own `source_event_id` (a ULID) — if every screen posted `''`, the second journal a clerk typed would silently return the first clerk's entry. |
| sec.6.3 layer 3 covers "someone edits a posted row" | ⚠ It did not cover the more likely mistake: **a line appended under a posted entry**. Every CHECK still passes (the entry's own totals do not move), so the header would claim to balance while its lines did not. | A fifth guard, `gl_journal_lines_bi`: lines may only be added to a `draft` entry, with the barrier row as the audited exception. Asserted in the verifier. |
| Lifting the barrier makes the ledger editable | ⚠ It lifts the **triggers**, not the **CHECKs**. `UPDATE … total_debit = 999999` on a posted entry is still refused, unbalanced. | Recorded so the emergency procedure is described accurately: the hatch changes rows that still balance, and a genuinely unbalanced header needs two statements inside one barrier window. |
| sec.6.1 `allowed_dimension_mask` declares which dimensions are *permitted* | The stated purpose — catching "a fuel line lost its truck and quietly landed in overheads" — is a **requirement**, not a permission. A permitted-set would let the line post with a null truck. | Implemented as required-set: every bit on the account must be present on the line. Naming deviation kept (`allowed_dimension_mask` is the column name the migration and the seeder already use); the semantics are documented in the enum. |
| sec.5.1 the screen passes minor units | Phase 0's manual journal needed a person to type "2 400,50". The obvious normalisation — strip commas and spaces — reads SADC comma-decimal as **a hundred times too large** (`1 234,56` → P123 456.00). | `Money::ofMajor()` added: the *last* separator is the decimal point, and which separator that is gets decided by the currency's own minor-unit count, not a locale table. Measured: `1 234,56`, `1.234,56`, `12,345.67` → 240050; `1,234` → 123400 (three trailing digits cannot be a pesma fraction); `1.234` refused; a float input refused. |
| sec.13 `Kernel/Audit/AuditRecorder` is needed | ✅ Not needed yet: `owen-it/laravel-auditing` 14 works, with the model implementing the contract and `$auditExclude` keeping `number_encrypted` out of the audit payload. | **But it is silent in the console**: `isAuditingEnabled()` returns `config('audit.console')` when `runningInConsole()`, which is false. So seeder- and Action-driven edits produce no audit rows, and a CLI check asserting the audit trail fails for the wrong reason. Any audit assertion must be made over HTTP, or `AUDITING_CONSOLE=true` in dev. |
| `booted()` is an instance hook | ❌ `Illuminate\Database\Eloquent\Model::booted()` is **static** on Laravel 13. Declaring it as `protected function booted()` is a fatal error at class load, not a warning. | Ledger models use `protected static function booted()`. |
| A Livewire 4 page component supplies its own layout (`<x-layouts::app>` in its template) | ❌ `config('livewire.component_layout')` is `layouts::app`, so every component is **already** wrapped. Nesting the layout again renders the whole shell twice inside `<main>` and trips `MultipleRootElementsDetectedException` — the page 500s in debug. | All page SFCs return a single content root and let the config wrap them; the guest-only invitation screen overrides with `#[Layout('layouts::auth')]`. **This defect was live in the app before this slice**: `⚡bank-accounts` and `⚡users` both 500'd, and neither had been loaded in a browser. The rule is now: a page is not done until it has been served. |
| `scripts/build-icon-registry.php` can add an icon | ❌ Its source is `vendor/livewire/flux/stubs/...`, which sec.20.2 uninstalled. Any name not already in the generated registry throws `Unknown ui-icon [...]` at render time. | Found because `ui/badge.blade.php` cited `bolt` for the accent tone, which had never rendered. Re-pointed at `signal`. **Before Phase 1**: vendor the Heroicons artwork into the repo or the icon set is frozen at what Flux happened to ship. |
| sec.6.3 layer 2 needs raw DDL because Blueprint has no `check()` | ✅ Confirmed again; also true of triggers, and Laravel exposes no trigger helpers. | Every CHECK and trigger in this slice is `DB::statement`, which must hold on both engines until dev matches prod (R1). |
| sec.6.3's trigger sample SQL | ✎ `SIGNAL SQL STATEMENT '45000'` is not the syntax; it is `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = …`. | Corrected in sec.6.3 below. |

**Two environment facts worth keeping:** the 3307 dev instance grants `ALL PRIVILEGES` on `fleet_guard_dev` to `fleetguard` and ships `log_bin_trust_function_creators=ON`, so the app account can own its triggers — which is exactly what sec.20.3 flagged as the Cloud risk: if Cloud's injected account cannot set that variable, the migration does not "degrade", it **fails**, and ADR-0001's layer 3 has to be restated as app guards + CHECK + `ledger:verify`.

**Phase 0 after this slice** — still open, in the order they now block the owner's review: mandatory-TOTP enforcement middleware (sec.9.1 says enforced, not prompted — the enrolment flow and recovery codes exist, the refusal does not); documents + versions + private disk + signed URLs; `security_events` / `operational_events`; the Alert skeleton and reminder engine; the queue health and failed-jobs screen; `docs/api-surface.md` with Sanctum installed.

---

### 20.7 Phase 0 slice 7 — 2026-10-26, documents, signed downloads and the two event trails

Built: `documents`, `document_versions`, `security_events`, `operational_events` (sec.4.2, sec.4.4) with the sec.10.8 one-current mechanism; `Kernel/Documents` — `DocumentType` (per-type MIME allowlist and size cap from `fleet.documents.types`), `Lifecycle`, `Validity` (derived, never stored), `RetentionClass`, `ScanVerdict`, `ContentSniffer`, `DocumentStore` (the one door onto `documents_private`, `serve => false`), `Scan/{VirusScanner,NoScanner,ClamAvScanner}`, and the Actions `RegisterDocument`, `UploadDocumentVersion`, `TransitionDocument`, `DeleteDocumentVersion`, `DownloadDocument`; `Kernel/Audit` — the two event models, `SecurityEventKind`, `ActorKind`, `AuditRecorder` as their only writers; `DocumentPolicy`; the signed download route with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`; the register and filing screens; `documents:verify-integrity`, scheduled weekly. Gates: `pint --test` PASS (193 files), `phpstan` `[OK] No errors`, architecture lint PASS (141 files, D1–D6), `verify-kernel` 33/33, `verify-identity` 33/33, `verify-ledger` 35/35, **`scripts/verify-documents.php` 120/120**.

sec.15.2's remaining definition-of-done item was exercised in the browser, not only in the verifier: a document registered through the screen, a real PDF uploaded through Livewire's own upload path, **a second copy filed and the first superseded with the reason the clerk typed**, a signed URL fetched and returned `200 application/pdf` with the stored bytes, a plain-text file named `scan.pdf` refused with the sniffed type in the message, and the delete refused until the password was re-entered (redirected to `/user/confirm-password`, nothing removed, no audit row written). The trail those requests wrote — `document_uploaded` ×2, `document_downloaded` ×4 carrying actor, IP, document id, version id, MIME, size and digest — was read back from the database and then **removed with the fixture**, because the account that performed them is a temporary one that cannot be left in the database and its rows must not be re-attributed to the Owner to keep them: a register whose first audit row names the wrong person is a worse artefact than an empty register. Phase 0's document screen is therefore empty by design, and the measurements above are the record.

After the fixture was removed, both screens were served again — `/documents` answers the empty register ("Nothing on file yet" plus the pointer to `Register a document`), and `/documents/register` renders all ten types with the per-type allowlist and cap line under the file input ("Accepted: application/pdf, image/jpeg, image/png, image/webp · maximum 8.0 MB — the content is sniffed, not the extension") and the seven visibility checkboxes. The account that loaded them was a second, non-Owner user with `operations_manager`, so the list and the form are shown to be reachable by a granted role rather than only by the Owner; that account and its session were deleted afterwards, and `/documents` with the stale cookie answered `/login` — the guard, not a 500.

**Findings that changed the design, the code, or the way a claim must be worded:**

| Claim or assumption | Reality | Consequence |
|---|---|---|
| sec.10.8's `STORED` generated column is written with `->generatedAs(…)->stored()` | ❌ **`MySqlGrammar` has no `Generated` modifier** — its list is `VirtualAs, StoredAs, Nullable, …`. `generatedAs()` is Postgres-only and is silently discarded on MySQL: the column came back as an ordinary writable `char(26) null`, the unique index across it became a unique index over a column nothing wrote, and the migration, the model and the verifier's DDL dump all looked correct. | `->storedAs()` is the MySQL/MariaDB modifier, and it must be `->nullable()`: the expression yields NULL for every non-current version and a NOT NULL generated column would turn the *second* version of any document into an insert error. Measured on MySQL 9.1: the emission order `char(26) as (…) stored null` is accepted; `char(26) null as (…) stored` is a 1064. **Rule added: read `SHOW CREATE TABLE` after every migration. A guard that exists in the file and not in the engine is the most expensive kind of green test.** |
| A CHECK on a nullable column enforces the rule | ⚠ **Only if it is written null-safe.** `to_state not in ('voided',…) or char_length(trim(reason)) > 0` evaluates to UNKNOWN when `reason` is NULL, and MySQL accepts a CHECK that evaluates to UNKNOWN — so the exact row sec.4.4 exists to forbid inserted cleanly, and the first run of the verifier proved it. | Both terms now: `… or (reason is not null and char_length(trim(reason)) > 0)`. The same trap was checked in every other CHECK added in this slice — each remaining one compares only NOT NULL columns or uses an explicit `is not null`. `documents_void_explained` was added on the same shape so a void cannot be recorded without a reason in the register either. **The verifier now measures every CHECK in both directions**, because a constraint that refuses everything also passes every "bad row refused" assertion. |
| sec.10.8 `current → superseded` "happens automatically when a new version is published" describes the document | ✎ Read literally it does: the truck's licence would become `superseded` the moment somebody re-uploaded a clearer scan, the register would show no live copy for it, and nothing would alert. | Implemented as: **the version** is superseded (`is_current` moves, `replaced_reason` records why); **the document** becomes `current` when its first version is published and reaches `superseded` only when the paper it evidences is replaced by another document — a decision, therefore stored, therefore not automatic. `acceptsNewVersion()` allows draft and current only, so new bytes cannot be slipped into a closed record. **Owner confirmed 2026-10-27:** sec.10.8 now names the version as the subject of the supersede, so the document and the design agree. |
| sec.14.4's scan step protects the disk | ✅ With an adapter seam, because sec.17 Q8 is genuinely open. `NoScanner` answers *pending* and says so in the row; `ClamAvScanner` speaks INSTREAM and answers **pending** — never clean — on every transport failure. `ScanVerdict::isServable()` refuses an infected file everywhere, and refuses an unscanned file only where a scanner exists to scan it. | An unrecognised `fleet.documents.scanner` value falls to `NoScanner`: a typo in an environment variable means "nothing judged these bytes", not "assume the thing you meant to configure did". |
| `sha256` on the version row is an integrity control | ⚠ It was a claim nothing re-read. A row whose object had been replaced or truncated served happily and looked healthy. | `documents:verify-integrity` re-hashes every stored object against its row and reports **digest / size / missing / no current copy / two current versions / orphan bytes**, exit 1, scheduled `30 1 * * 0`. Measured by corrupting a stored object, restoring it, dropping an unowned file on the disk, and inserting a row pointing at a path that was never written. The command reads through the sanctioned scope-free path because a per-tenant sweep would certify the tenants it never looked at — and because a scoped relation inside it would call `CurrentCompany::id()` in a command with no request to answer it. |
| The upload Action's transaction protects the disk | ❌ It cannot. A rollback removes the row and leaves the object: invisible to every screen, and permanent. | Bytes are deleted in the `catch`, and the sweep now reports orphans as a finding so a missed one cannot accumulate silently. Measured by aborting a run mid-transaction: zero orphans on disk afterwards. |
| sec.9.3's ⚑ on documents.delete belongs on the route | ⚠ The register, the filing panel and the delete are one screen. `reauth` on the route would demand a password to *look* at the list. | The window is enforced on the action, against the same `auth.password_confirmed_at` and the same `fleet.identity.reauth_window_seconds` the manual-journal route uses, so there is one clock and two doors. Measured in the browser: the delete redirected to `/user/confirm-password` and changed nothing. |
| A version row is immutable | ✅ At layer 1 — and the guard's own behaviour needed fixing: refusing an edit left the rejected values **dirty in memory**, so a caller that caught the exception and saved something legitimate was refused again, naming a column nobody was touching. | The rejected attributes are restored from their originals before the throw. Found by the verifier, not by reading the code. |
| `text/csv` is not a sniffed type (my own first claim in this slice) | ❌ Measured on PHP 8.4.27: a comma-headed CSV reports **`text/csv`**; a single-column one reports `text/plain`. An allowlist naming only either is a rule that breaks on next month's ticket. | The weighbridge allowlist names both, with the measurement recorded in the config comment. Also measured: `%PDF-1.4` → `application/pdf`, a GD PNG → `image/png`, and PHP source behind a `.pdf` name → `text/x-php`, which no type accepts. A fixture must be a *real* container — a PNG signature followed by nonsense sniffs as `application/octet-stream` and would have made the allowlist check pass for the wrong reason. |
| `document_versions` has timestamps | ❌ It has `uploaded_at` and `scanned_at` only (sec.4.4). The supersede is a bulk `update()`, and Eloquent appended `updated_at` to it: `Unknown column … updated_at` on the first real upload. | `$timestamps = false` on the version and the security trail; `UPDATED_AT = null` on the operational trail, whose `occurred_at` is the event's moment rather than the insert's. |
| `generatedAs()`/`storedAs()` writes are harmless from Eloquent | ✅ Confirmed the other way: a generated column never enters `getDirty()`, so an ordinary `save()` of a mutable column does not try to write it. No model changes needed — but it is the reason `current_slot` is documented as read-only rather than guarded. |
| The cleanup at the end of the verifier removes its own rows | ❌ It filtered `meta->document_id like '%verify%'` — a column that holds a ULID, never the word. **Six runs left their audit rows behind**, and every check above them still passed. | Cleanup now matches on the ids it created and **asserts its own work**: two new checks fail the run if the rows survive. 31 residue rows from the earlier runs were removed by hand, which also removed some genuine-but-still-fixture rows from earlier slices' browser passes; nothing real was lost, and it is recorded here because the honest number matters. |
| `Model::query()->whereIn(...)` can be iterated directly | ⚠ Iterating an Eloquent Builder in Laravel 13 yields **arrays**, not models, in this path — `Call to a member function hasCurrentVersion() on array`. | The sweep uses `cursor()` for versions and `chunk(200)` for documents (sec.14.3's no-unbounded-read rule applies hardest to the command that exists to read everything), and answers both lifecycle findings from one grouped query instead of one query per document. |
| A Livewire 4 page's JS proxy is `Livewire.find(id).$wire.set(…)` | ⚠ `$wire` lives on the **element** (`el.$wire.set/call/get`); `component.$wire.set` is not a function, and `document.querySelector('[wire:id]')` selects the *layout's* component, not the page's. | Browser probes must find their root by `__livewire.name`. Recorded so the next verification pass does not mistake a probe bug for an application bug. |
| sec.13's `Kernel/Audit` was empty scaffolding | ✅ Now real and narrow: `AuditRecorder` is the only writer, both trails refuse update and delete in code, and `SecurityEventKind` is a closed enum matching the DB CHECK — a trail that accepts any string is a log file, and "who disabled MFA" stops having an answer. | laravel-auditing is *not* attached to `Document` yet: there is no document-edit screen, and an audit guarantee nothing can trigger has no way to be tested. It goes on the list below, with the `$auditExclude` precedent already set on `BankAccount`. |
| The verifier's cleanup leaves nothing behind | ⚠ It removed every row and every object, and still left **47 empty directories** under `storage/app/documents` (`company/1/licence/unfiled/<version>/`), plus — from the run that crashed before reaching it — a second company. `delete()` takes files; nobody takes the path skeleton Laravel's `put()` created. | Cleanup now prunes the disk deepest-first with `rmdir`, which can only remove a directory that already holds nothing — so a directory containing someone else's real bytes is never touched — and asserts the result: *"the private disk holds no bytes without an index row behind them"* and *"after this run it holds nothing at all"*. Final run: 17 directories pruned, 120 checks, 0 failed, disk empty. |
| A scripted account is ready to browse with | ❌ `User::create([... 'email_verified_at' => now()])` is **silently dropped** — the column is not fillable — so the first browser pass on `/documents` bounced to `/email/verify` and looked like an authorisation bug. | Set it with a direct update after create. The same pass produced two more facts worth keeping: `Storage::disk('…')->path()` **requires an argument** on Laravel 13 (`path('')` returns the root), and the shell's user menu renders the signed-in name through `x-ui.heading`, which defaults to level 2 — so **every page in the app carries three `<h2>`s naming the user** before the page's own content. Pre-existing layout behaviour, not this slice's; recorded for the accessibility pass. |
| The private disk is unreachable because it is not linked | ⚠ Worth measuring rather than believing: `public/storage` is not linked in this environment, and `/storage/{path}` answers **403** for both the local disk root (`storage/app/private`, which is where Livewire's *unscanned* temp uploads sit) and every guessed documents path. | Measured with a real file written into each root and fetched over HTTP: nothing returned the bytes. `documents_private.serve => false` is the durable reason, and the verifier asserts both the config and that `Storage::url()` on that disk throws. **The 403 on the Livewire temp area is the finding to keep an eye on**: it is the one place unscanned bytes exist, and it is the framework's default, not this project's choice. |

**Phase 0 after this slice** — mandatory-TOTP enforcement middleware; ~~the Alert skeleton and the reminder engine~~ (**built in slice 8, 2026-10-27 — sec.20.8**; this is what sec.10.8's `DocumentExpiryImminent` / `DocumentExpired` emissions needed: the derivation was done and live in `Validity`, and the job that raises against it is now the nightly `reminders:run`); `laravel-auditing` on `Document` when the edit screen exists; the queue health and failed-jobs screen; `docs/api-surface.md` with Sanctum installed; and the open decision that this slice made necessary — **sec.10.8's `current → superseded`, whose subject is the version and not the document** (settled by the owner on 2026-10-27; sec.10.8 amended to match the code).

**Still open from sec.14.4/sec.17, unchanged by this slice:** Q8 — what the scanner is on Laravel Cloud. The code answers it three ways (none / clamav sidecar / a future hosted API behind the same interface) and the download gate changes behaviour with the answer, which is the best that can be done before the account exists to test it.

### 20.8 Phase 0 slice 8 — 2026-10-27, the alert skeleton and the reminder engine

Built: `alerts`, `reminder_thresholds`, `notification_preferences` and the framework `notifications` table
(sec.4.4) — the last of those because `User` already used `Notifiable` and **nothing had ever created its table**,
so the first real `notify()` of the project would have failed at insert time; thirteen CHECKs on `alerts`, each
measured in both directions, plus the composite `alerts_company_cooldown_unique`. In `Kernel/Alerts` (sec.13):
`AlertType` / `AlertSeverity` / `AlertState` (the sec.10 machine, with `Muted → Open` so an expired mute reopens),
the Actions `RaiseAlert` and `TransitionAlert`, `Contracts/ReminderSource` as the only seam the engine knows,
`Sources/DocumentExpirySource`, `Support/{ThresholdRepository,RecipientResolver,AlertDelivery,ReminderRunner,SelfCheckAlerter}`,
`Data/{Threshold,ThresholdSet,ReminderCandidate,RaiseOutcome,ReminderRunReport,AlertCopy}` and
`Notifications/AlertRaised` (queued, `mail` + `database`). Scheduled: `reminders:ensure-thresholds` at 02:00 and
`reminders:run` at 02:30, both `withoutOverlapping()` (sec.14.1's "scheduler is the only driver"). The register
screen at `/alerts` with `can:alerts.view` on the route and the writes behind the gate; `User::unreadAlertsCount()`
as the only door the topbar bell may use. sec.14.6 wired: `ledger:verify`, `documents:number-integrity` and
`documents:verify-integrity` now raise and close their own findings.

Gates: `pint --test` PASS (226 files), `phpstan` `[OK] No errors`, architecture lint PASS (170 files, D1–D6),
section-reference lint PASS, `verify-kernel` 33, `verify-identity` 33, `verify-ledger` 35, `verify-documents`
120, **`scripts/verify-alerts.php` 185 checks / 0 failed**, run twice in a row so the cleanup and the
idempotence claims are measured and not hoped.

**Then the screen was loaded**, because §20.7's rule is that a claim about a screen is made in a browser, and
the eight verifier failures above had taught nothing about the page itself. A temporary `operations_manager`
account (created for the pass, deleted after it, with three expiring papers and their real deliveries behind
it) saw the register in severity order — critical, then warning, then info — under "1 critical · 3 open", with
the topbar bell's accessible name reading **"3 unread alerts"**. *Acknowledge* moved the row to
`Acknowledged`, named the person in *Decided by*, and removed its own button. *Resolve* with an empty reason
refused the submit — and after this slice's third fix, the field said so: `aria-invalid="true"` and its own
sentence. With a reason the row left the "Needs attention" filter, the counts went 3 open → 2 open, and on the
next load the bell read **2**, which is §14.1's reconciliation between the register and the bell seen on a
screen instead of in a query. *Mute* refused an empty reason, then showed `Muted · until 2026-11-03` with the
reason in the same cell and a "1 muted" badge in the counts line. The *Resolved* filter showed the closed row
with its resolver and its reason and no buttons at all. The same account re-roled to `dispatcher` kept the
register and the reversible writes and lost **Resolve on every row**, and with no unread deliveries of its own
the bell showed no number. `/documents` was re-checked after the `Validity` fix below: the licence sixty days
out now reads *Expiring soon*, where it had read *Valid*. Teardown asserted 0 alerts, 0 deliveries, 0 queued
alert jobs, 0 alert trail rows, 0 documents and 0 probe accounts, with the real accounts still present.

**Findings that changed the design, the code, or the way a claim must be worded:**

| Claim or assumption | Reality | Consequence |
|---|---|---|
| sec.10.8's `Validity` derivation compared the business day and the expiry date | ❌ **It compared two different zones.** `expires_on` is a `date` cast, so it arrives as midnight **UTC**; `$businessToday` is midnight **Africa/Gaborone** (+2). Measured: a licence exactly sixty days out derived `valid` — "2026-12-26 00:00 UTC" is two hours *after* "sixty days from today" when the latter means Gaborone midnight — so **no candidate was produced at all** for the widest band, every band crossing fired two hours late, and `days_until_expiry` reported 59 beside a `expiring_soon` state. For a company west of UTC the same arithmetic returns `expired` for a paper that lapses tonight: the truck stood down a day early, which is the sec.2 error this enum's own docblock says it prevents. | `Validity::midnightOn()` re-anchors the **calendar date** in the business day's zone (converting the instant would not: it moves the date). `Validity::daysUntil()` is now public and shares it, so the payload's number and the payload's state cannot disagree. Measured by a boundary matrix (0/1/6/7/8/29/30/59/60/61/−1) and by an explicit `America/Sao_Paulo` business day — the verifier had only ever used 59/29/13-day offsets, which is precisely why the bug survived slice 7. **The derivation slice 7 built had no measurement of its own; it now has five.** |
| `json_type(recipients) = 'array'` is a CHECK that accepts a JSON array | ❌ MySQL returns **`ARRAY`** (uppercase, binary collation) from `json_type()`, so the constraint refused every legitimate row. The fallback `json_extract(recipients,'$[0]) is not null` is also wrong: measured, it returns the scalar itself, and `'"operations_manager"'` at `$[0]` is not NULL for the *empty* array case it was meant to catch. | `upper(json_type(recipients)) = 'ARRAY' and json_length(recipients) >= 1`. A threshold row that cannot deliver to anyone is the sec.14.1 defect, so the empty array is refused at the engine rather than by a resolver that would have to remember it. |
| sec.4.4's `cooldown_key UK` is a global unique | ⚠ Two companies' `ledger:verify` failure name the same subject (`self_check_failure:-:-:ledger:verify`), so a global unique lets the first tenant's alert **swallow the second's** — the second company's drift would be recorded nowhere and reported nowhere. | `unique(company_id, cooldown_key)`. Measured by creating the same finding for both tenants and asserting two rows. |
| A resolved alert holds its cooldown key forever | ❌ It does not, and must not: sec.10 says `resolved` is final, so a check that was fixed and broke again is a **second occurrence** needing its own row. The episode suffix (`base#2`) is what makes that legal under the unique index. | `RaiseAlert` probes the episode keys **exactly** — never `LIKE`, because sec.14.1's own type names contain underscores and `_` matches any character in a pattern; a prefix search would inflate episode counts for unrelated rows. `Alert::Episodes` (12) caps it and the sweep throws rather than filing a thirteenth. |
| `SelfCheckAlerter::clear()` closes the check's open finding | ❌ It matched **the base key only**. A second occurrence sits on `base#2`, so the alert that a clean run was supposed to retire stayed live forever — sec.14.6's wallpaper, and it was found by the *clean-database* check failing, not by reading the method. | Episode keys are derived in one place (`Alert::episodeKeys()`) and both ends read it: the raise door for the next free slot, the clear step for wherever the live row sits. Found while this file was being written; recorded because the previous row's own cap (12) is what makes the miss unbounded. |
| sec.14.4's role recipients resolve with `Role::findByName($key)` | ❌ With `permission.teams` on, an **unprovisioned tenant** (a company in its first week: thresholds, no staff) throws `RoleDoesNotExist`, which aborted the whole nightly sweep. Every company after it got no reminders — quietly, because the scheduler marks the job failed and nobody reads failed jobs at 02:30. | `RecipientResolver` distinguishes the two empties: a catalogue role with no holder **skips** (contributing no recipients, reported per tenant as "told nobody … in company #2", `reminders:run` exits 1) and a name outside sec.9.2's catalogue still **throws**. One tenant's missing staffing is a finding, not an abort. |
| A queued notification may take the model | ❌ `AlertRaised` was queued with the `Alert`, and the worker runs with **no company context** (`DomainServiceProvider` drops it after every job — sec.20.4's leak, closed). Re-reading the company-scoped model threw `ModelNotFoundException`; the finding stayed accurate in the table and nobody was ever told. `failed_jobs` is where a slice-8 reminder went to die. | `Data/AlertCopy` snapshots the words **while the tenant is pinned** (`title()`, severity tone, band, subject) and the notification carries the snapshot. A named `alerts` queue keeps an email backlog off the ledger postings, and the verifier asserts **zero `failed_jobs` rows on that queue** — for the reminder path and, after this slice's last fix, for the self-check path too. |
| `CurrentCompany::forget()` in a `finally` is the safe cleanup | ❌ It erases a pin the **caller** set. `ReminderRunner` did exactly that, and `verify-documents` fell from 120/120 to `MissingCompanyContext`: a command that ran a sweep inside a pinned context came back out of it. | `CurrentCompany::pin()` added; the sweep, the alerter and `reminders:ensure-thresholds` all **restore the previous pin** rather than clearing it. The scheduler arrives unpinned and leaves unpinned; a caller that pinned stays pinned. |
| `alerts.acknowledge` is the register's one write permission | ❌ It was also what authorised **Resolve**, which the engine cannot undo (`alerts_resolved_is_final`), and whose catalogue label read "acknowledge or mute an alert". A permission whose label under-describes it is a matrix a reviewer cannot audit, and the slice's own verifier asserted both "the Ops Manager may act" and "only the Owner holds it" in the same file. | `alerts.resolve` added to the catalogue and split at the machine's boundary: sec.9.3 now carries the row (Owner/Ops Mgr all three; Dispatcher and Workshop Mgr see and acknowledge, never resolve; every other role read-only), the register's Resolve button and `resolve()` ask the new name, and the verifier measures all three roles. sec.14.1's own sentence — the Ops Manager *and* the Dispatcher both acting on one alert — is what settled it, not a guess about who should. |
| A migration file that ran is the schema that exists | ⚠ `alerts.resolve_reason`-adjacent columns were edited into the migration **after** it had run; the database kept the old shape and `resolved_by_kind` was simply absent, while every file read looked correct. | Rollback of that batch (verified by batch id, not by assumption) and re-run. Slice 7's rule repeats and still needs repeating: **read `SHOW CREATE TABLE`** — this verifier's first section is the DDL dump, not the model. |
| A passing check proves the thing it names | ❌ Five of them in this file did not, all found by the eight failures rather than by reading: `Artisan::output()` read **after** `queue:work` (the call replaces the buffer, so every claim about what the command *said* was measured against the worker's log, and the "it did not throw" check passed vacuously); `Alert::query()->where('company_id', 2)` while pinned to company 1 (a tenant-isolation assertion that could only ever fail); a `whereRaw('1 = 0')` tautology standing in for "no phantom alert"; a bell assertion of `$unread - 2` measured on the person who had just read the delivery, i.e. nobody; and a loop asserting the real `documents:verify-integrity` **passes** on a register whose fixtures are deliberately current-with-no-copy. | Output is captured before draining; cross-tenant reads use `withoutGlobalScopes()` and say why; the phantom-alert check counts rows and compares; the bell is measured on **the second recipient** (two holders of one role, which is the only way to assert "the dots are per person"); and the integrity sweep is now asserted to **fail** on the fixtures, raise for both tenants, and pass on a clean database after they are removed — three claims where there was one false one. |
| sec.14.6's alerts reach a person like any other alert | ⚠ They reach the **Owner**, and the cleanup's `whereIn(notifiable_id, $createdUserIds)` did not cover that account. Three delivery rows for alerts the run had already deleted survived on the real Owner's bell, and a `queue:work` still running on this machine can pick up an undrained job at any moment and make more. | Cleanup deletes by notification **type**, drains the alerts queue before it deletes anything, and removes leftover `jobs` rows for that queue in both the pre-clean and the cleanup, with a new assertion: *"no delivery is still queued for an alert that no longer exists"*. |
| A command's alert title describes its finding | ⚠ `documents:verify-integrity` titled every finding "1 stored object no longer matches the index row" — including `no current copy`, where there is no object at all. The register's first word sent the operator to the wrong shelf. | The title is now "N integrity findings — the register and the private disk disagree", which is true for all six finding kinds. |
| A route, a middleware and a gate that all read correct mean the screen works | ❌ **`/alerts` had never rendered.** It defined `private function transition()`, and `Livewire\Component` carries a **public** `transition()` from its SupportTransitions feature — a compile-time fatal, so the page 500ed before its first query while `Route::has('alerts.index')`, `can:alerts.view` and every `Gate::forUser()` assertion stayed green. | Renamed `applyTransition()`, and the verifier's §9.4 section now sweeps **every** Blade file in the application for three shapes it cannot see from a query: a page shadowing a public `Livewire\Component` method, a static `icon="…"` name absent from the registry, and a `wire:model`-bound `x-ui.input` with no `name`. |
| An unknown icon draws nothing | ❌ `x-ui/icon` **throws** `InvalidArgumentException: Unknown ui-icon [speaker-wave]`. The Muted badge used it, and the badge renders only when some row is muted — so the screen worked for every state the fixtures had never reached. | `eye-slash`, which is the registry's existing "suppressed" glyph. **Flagged for the owner:** `scripts/build-icon-registry.php` builds from `vendor/livewire/flux/stubs/...`, which slice 2 removed, so the exception's own instruction ("add it and re-run") cannot be followed — the 178-name list is frozen until the builder gets a real source. |
| `x-ui.input` shows validation errors | ⚠ Only when it is given `name`: the component looks `$errors->first($name)` up, and derives the control's `id` from it too. Both reason fields on the register omitted it, so §10.1's mandatory reason refused the submit **in silence**; the sweep found the same on three screens this slice never touched (`⚡profile` name + email, `⚡security`'s three password fields, `⚡delete-user-modal`'s password) — six inputs where a validation failure was invisible, each with a `Str::random(6)` id that changed on every render and so broke its own label association. | All six carry `name` now, and the guard is app-wide so a seventh cannot be written. Found by pressing the button, not by reading the code. |
| The gate can render the page itself | ❌ Attempted and dropped. An in-process `$kernel->handle(Request::create('/alerts'))` with `Auth::guard('web')->setUser(...)` returns 500 with `MissingCompanyContext`: `AuthenticateSession` rightly drops a guard that has no session row behind it, and `ResolveCurrentCompany` then has no user to derive a company from — the application behaving correctly, and a script pretending to be a browser failing for it. | Recorded so nobody re-attempts it and reads the failure as an app bug. The static sweep above is what the gate owns; the render is the browser pass's job, and the paragraph before this table is it. |
| The verifier's pre-clean removes what the verifier creates | ❌ A run that died in the new render block left its `insurance / 25` threshold row behind, and the *next* run aborted on the unique index in a section three hundred lines later — a schema error reported for a crash that had nothing to do with it. | The pre-clean now deletes every fixture threshold by its unique key, listed next to the rows that create them, and the run that crashed leaves nothing that can misattribute the next one. |
| Thresholds are the 60/30/14/7 ladder | ✅ Rows, as sec.14.1 says — measured by inserting a **45-day** band and seeing it answer. The ladder is the seed, not the type; and `days_before` is bounded by nothing in the engine, which is the Owner's decision to make. | The rename to `family` (sec.4.4) and the additive-only `ensureDefaults()` (never re-creating a row the Owner deleted) both follow from that measurement. |

**Also recorded, because they are the kind of fact that saves an hour:** the `Module::forceCreate()` shape of the
fixtures silently dropped `company_id` until `BelongsToCompany` filled it (so a fixture created while pinned to
company 2 belongs to company 2 — the isolation check is real, not the query's fault); `checkRefuses()` in this
harness committed its bad row whenever a constraint failed to fire, which turned a later, unrelated section into
a `TypeError` blamed on the model — it now runs inside a rolled-back transaction; and a `head -1` on a probe's
output produced SIGPIPE, killed the probe halfway, and left company 2 behind, which is why both the probe and
the verifier pre-clean **before** measuring anything. Two harness facts from the browser pass, so the next one
does not mistake them for application bugs: Alpine dropdowns (the account menu, the mobile nav drawer) do not
respond to the in-app browser's synthetic clicks — the register's *Livewire* buttons do — so a role switch was
done by re-roling the signed-in account in the database rather than by logging out; and the topbar bell keeps
its server-rendered number until the next full page load, which is correct for a layout Livewire does not
re-render, but reads as a stale count to whoever is watching the dot.

**Known and deliberately not fixed in this slice:** a §14.6 raise that reaches nobody in a tenant with no Owner is
not reported with an exit code the way a reminder is (the command's own non-zero exit still says the check failed,
so the finding is not silent — but "and nobody was emailed about it" is not said); a mute created in the register
stores `muted_until` at `endOfDay()` in the **storage** zone, so in Gaborone it reopens up to two hours after
local midnight on the day after the date the operator chose — late, never early, and the nightly sweep reopens it
that night regardless; `laravel-auditing` on `Document` still waits for a document-edit screen; and
`scripts/build-icon-registry.php` still builds from the Flux stubs that slice 2 deleted, so the icon list is
frozen at 178 names and the exception message that points at it cannot be obeyed.

**Phase 0 after this slice** — mandatory-TOTP enforcement middleware; the queue health and failed-jobs screen
(this slice produced both a `failed_jobs` table worth reading and a real residue of 17 rows on the `default`
queue, plus two `StaffInvitationMail` jobs that have been sitting there since slice 5); `docs/api-surface.md`
with Sanctum installed; `service_due`, `loan_due` and the `overdue_invoice` sources when their modules land (the
seam is `Contracts/ReminderSource` and the container tag `reminder_sources`, so none of them needs the Kernel to
change); and the first non-`documents` subject for `DocumentExpirySource`'s sibling, which is a truck's licence
in Phase 1 rather than a paper in the register.

### 20.9 Phase 0 slice 9 — mandatory TOTP

`EnsureMultiFactorAuthentication` now enforces enrolment from the user's actual financial permissions (including direct grants), with an explicit Owner rule. A secret **and** its confirmation are required; all seven seeded roles currently qualify. Fortify's password-confirmation POST and enrolment routes remain reachable. Required MFA cannot be disabled through either Fortify's DELETE or the Livewire action.

Livewire's outer update request defers this gate to persistent middleware, which runs against the original route **after snapshot checksum verification**. A local HTTP test replays a signed alerts snapshot after removing the factor and measures a redirect, rather than trusting a route-name assertion. `verify-identity` passes 47 checks; the local MySQL-backed FoundationsTest passes 5 tests / 37 assertions; Larastan and architecture lint pass. Test transactions roll back; no real user factor was changed.

Browser: password confirmation reached Security; an invalid code produced a visible field error; a valid TOTP enabled the factor and recovery codes; the Disable control disappeared; Dashboard then loaded. The enrolment pass found native dialog open-state being removed by Livewire morphing: `wire:ignore.self` now preserves the dialog's own attributes while allowing its contents to update. Pixel-level review remains subject to the in-app browser's display limitations.

Re-checked in slice 10 because the dialog component is shared and this page holds both of the application's dialogs: with a confirmed factor the page reads "required for your role and cannot be disabled" and offers no Disable control, and the delete-account dialog still opens on its `open-<name>` event, renders its password field, and closes.

---

### 20.10 Phase 0 slice 10 — 2026-10-27, queue health, the failed-jobs screen and the residue

`jobs`, `job_batches` and `failed_jobs` are not tenant rows. §8's tenancy cannot scope them, so the screen answers a deployment question and is gated to the deployment operator: `fleet.queue_health.company_id` (default 1) **and** the Owner role **and** `queues.view`, with `queues.retry` behind §9.1's re-authentication window and made a persistent Livewire middleware so a stale component cannot skip it. A second company's Owner measures 403 rather than being assumed to.

`QueueHealth::summary()` reads `pendingSize`, `delayedSize`, `reservedSize` and `creationTimeOfOldestPendingJob` off the queue connection rather than hand-writing SQL against `jobs`, so the same screen answers correctly on this environment's database driver and on Cloud's managed backend. A connection that will not answer reports `unavailable` with null counts — "0 ready" and "I could not look" are different claims, and the scheduled check treats them differently. Failed counts come from the configured failer's own table, filtered to the current connection and the monitored queue list, and sit outside that try/catch: a broken failed-job store fails the read rather than showing a healthy queue.

The reference list selects `uuid`, `queue`, `connection` and `failed_at` and nothing else — payload and exception stay in the table, which a marker test proves never reaches the HTML or the Livewire snapshot. Two findings came out of writing the retry:

- `operational_events.aggregate_id` is `char(26)` and a failed job's uuid is 36 characters. Strict mode refuses the insert and non-strict mode silently truncates the key that identifies the job, so the trail now names `failed_job` with the row's numeric id and carries the uuid, queue and connection in `context`.
- `queue:retry` reports an unfindable job by *printing*, and its exit code stays 0. The action therefore re-reads the row inside its own transaction and refuses to audit a retry that did not happen; `lockForUpdate` plus uuid validation means one click moves one job and `all` cannot arrive as an id.

`queues:check` runs every five minutes and raises through `SelfCheckAlerter`, so a queue finding lands on the same register as a drifting digest. Run for real against the residue it exited 1 with "default: 17 failed jobs; default: oldest ready job waiting 32750 seconds" and opened alert 702 — critical, both findings in its payload. The approved purge then deleted the 17 failed rows, the 6 pending on `default` and the 4 on `alerts`, after host, port and database name were asserted and before the first delete ran; no worker ran during the slice, so none of the queued invitation mail was sent. Re-running the command against clean queues exits 0 and resolves its own alert as `scheduler`.

11 tests / 64 assertions in the new `QueueHealthTest` against the real dev database inside a rolled-back transaction; `FoundationsTest` still passes 5 / 37; PHPStan, Pint, architecture lint (174 files) and the section-reference lint (855 citations) pass. Browser: 403 as Accountant; the re-auth wall and then the screen as Owner, with counts identical to the database; retrying an artificial probe failure removed its row, moved Ready 5 → 6 and wrote the audit row attributed to the signed-in Owner; after the purge both cards read Healthy and the table reads "No failed jobs on the monitored queues." Two things the browser pass did not prove: the toast's pixels — it had dismissed before the DOM read, so the dispatch is carried by the component test instead — and the sidebar entry's visibility, since `offsetParent` is unreliable in the in-app browser's viewport, though its link, href and icon are in the DOM.

---

### 20.11 Phase 0 slice 11 — 2026-10-27, Sanctum installed and the API surface written

`laravel/sanctum` **v4.3.3** installed and migrated: the §12.1 compatibility row was a Packagist reading, and it is now an observed one — it resolved and installed against `laravel/framework` 13.38 with no conflict, `Auth::guard('sanctum')` resolves to a `RequestGuard` with only the `web` guard declared in `config/auth.php`, and `personal_access_tokens` exists. The published migration was renamed into this project's module ordering (`2026_10_27_200000_security_create_personal_access_tokens_table`) with its column set left exactly as the package writes it, and `config/sanctum.php` keeps `expiration` at the package default with a prefix set for leak scanning. One stock line needed a local fix: `explode(',', env(...))` fails static analysis because `env()` is `bool|string`, so the `stateful` list now casts.

Deliberately **not** added: `HasApiTokens` on `App\Models\User`, `routes/api.php`, an `api:` group in `withRouting()`, and any of `sanctum:install`'s scaffolding. Measured, not asserted: `route:list` reports 63 routes and none under `/api`. A trait whose only effect today is a token surface with no consumer, and an endpoint that freezes an interface nobody has needed, are both exactly what §14.9 forbids.

`docs/api-surface.md` is the contract instead. It records the guard sequence in order — bearer, then tenant from `$tokenable->company_id` and never from a path segment, then the factor gate (a token must not launder §9.1), then the same `Gate` abilities with no `api.*` vocabulary — and the one decision that would otherwise be made under deadline: **⚑ re-authenticated abilities are not exposed as token endpoints at all**, because password confirmation is a session concept. Money is the `Money::jsonSerialize()` shape already in the code, minor units as a string, with the inbound rule stricter than the constructor. Idempotency names the natural keys the design already has and the rule the others miss: the replay check runs **before** `NumberAllocator`, because a re-allocated number is a hole in a gap-free sequence. It also states the open question plainly rather than resolving it with a guess — `expiration` is null today, which is a default nobody has argued for, and it must be answered before a first token is issued.

Gates after the install: 16 tests / 101 assertions, PHPStan, Pint, architecture lint (175 files) and the section-reference lint (859 citations across 299 files) pass; the production build is unchanged at 46.14 kB CSS / 295.58 kB JS.

---

### 20.12 Phase 0.5 — 2026-10-28, legacy intake and the opening trial balance

Scope approved by the Owner: **0.5a — the cutover framework plus the opening trial balance**, on a **Livewire screen with a downloadable error CSV**, with **the accountant validating and the Owner posting**. Master-data importers arrive with Phase 1, parties and loans with Phase 5, and the full rehearsal at the end of v1 — those are not built yet, by decision, not by omission.

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Batch and row tables | `2026_10_28_100000_cutover_create_cutover_batches_table`, `…100010_…_cutover_rows_table` | Statuses `validated\|rejected\|posted\|reversed`; CHECKs refuse a "valid" row without an account, a row paid on both sides or on neither, an error row with no message, and a posted batch that is unbalanced or has no journal (`cutover_batches_posted_is_balanced`, `…_posted_has_entry`). |
| Permissions | `PermissionCatalogue` | `cutover.import` for Owner + Accountant (upload, validate, preview, download the report); `cutover.post` Owner-only and marked ⚑ re-auth. Policy `CutoverBatchPolicy` owns `create/view/post/reverse`. |
| Actions | `ImportCutoverBatch`, `PostOpeningTrialBalance`, `ReverseOpeningTrialBalance` | Validation writes the batch and every row; commit posts **one** journal from the valid rows; reversal posts a mirror at today with `reverses_entry_id` + `netting_group`. Nothing else touches the ledger. |
| Parsing and reporting | `OpeningTrialBalanceValidator`, `CsvTable`, `CutoverErrorReport` | 2 MB / 5 000 rows; amounts through `Money::ofMajor()` (grouping allowed, never a float); header errors, unbalanced totals, an exact re-upload of a posted file and dimension-requiring accounts are refused with their reason; the CSV prefixes `= + - @` with an apostrophe so a description cannot execute in the accountant's spreadsheet. |
| Screen | `/accounting/cutover` (`⚡cutover.blade.php`) + `/accounting/cutover/{batch}/errors.csv` | Upload or paste, batch summary (rows/valid/problems, Dr–Cr, balanced), batch-level alert, row table filtered to problems with an "All rows" tab, error-report download, commit form with a date, reversal form with a required reason, and a history of every upload with an "Open" link. |

**Decisions that close gaps rather than follow the prose literally:**

1. **Route permission is `cutover.import`, not `cutover.post`.** The whole screen is an ordinary read for the accountant, so gating the route on the ⚑ ability would send an accountant to the password prompt for a button they never see. `post` and `reverse` are asked of the policy inside `commit()`/`reverse()`, and only then is re-auth demanded (`auth.password_confirmed_at`, the clock Fortify's `RequirePassword` writes — the same one the documents delete reads).
2. **Idempotency is minted at import, not at commit.** `public_id`, `posting_event_id` and `reversal_event_id` are created with the batch, so a double-submitted commit or a retried reversal is refused by the ledger's natural key instead of doubling the opening balances.
3. **A rejected file is kept.** Rows survive with their physical line numbers because the error report is the deliverable, and the batch CHECK `validated_is_clean` means a batch can only be "ready to post" when every row is valid.
4. **Dimension-requiring accounts are a row error today.** An opening TB has no truck/customer/driver columns until the Phase 1/5 importers land, so the validator names the account and suggests a clearing-account control total rather than letting the ledger refuse it at commit with a less useful message (sec.17.2 carries the rehearsal decision).

**Verification.** `php scripts/verify-cutover.php` — 47 checks, 0 failures — proves against the live MySQL 9.1 instance: header and row validation, persisted line numbers, batch-level vs row-level errors, the exact-re-upload refusal, idempotent commit and reversal (mirror nets to zero), the CSV content, and that the DB CHECKs refuse malformed rows even when the application is bypassed. `php scripts/render-cutover.php` — 15 checks, `RENDER OK` — drives the real HTTP/Livewire stack: Owner and Accountant see the screen, a Dispatcher gets 403, the accountant's paste becomes a validated batch, the commit form is shown to the Owner and not to the accountant, the report downloads, commit without a recent password redirects to `password.confirm`, commit with one posts on the chosen date, and a blank reversal reason is a field error while a stated one reverses. Both scripts leave the database clean and assert the ledger barrier is lowered.

Browser pass (temporary MFA-enrolled accountant — `browser-check@fleetguard.local`, created and deleted in the same session; the Owner's credentials were not used): the uploaded fixture validated as **5 rows · 5 valid · 0 with problems**, **Dr P 812 500,00 · Cr P 812 500,00**, balanced; "All rows" rendered lines 2–6 with their accounts and amounts; the report URL returned `text/csv` with attachment `cutover-errors-batch-34-…csv`; a pasted garbage file produced **Rejected — fix the file**, 1 valid / 2 problems, the batch alert quoting the difference, row errors naming account `9999` and the empty-amount line, and a report whose line 0 is the batch error and whose lines 2–3 are the rows; the history listed both batches and "Open" switched between them. The accountant correctly saw "Validated and waiting for the Owner to commit it" and no commit or reversal form.

**Defect the browser pass caught and fixed.** At a 590 px viewport the page was **211 px wider than the screen** (`scrollWidth` 786 vs `clientWidth` 575): the page root's implicit `auto` grid column was sized by the widest card — the eight-column history table — so every card widened, the batch summary ran off the right edge and the table never scrolled inside its `overflow-x-auto` wrapper. `grid-cols-1` (`minmax(0, 1fr)`) on the root fixed it exactly (575 = 575) and the table now scrolls in its card. The same implicit-column pattern is on `⚡journal` (676 vs 575) and other table screens; **not** changed in this phase, recorded so a single sweep can fix the shell rather than each page.

**Honest gaps.** Owner commit and reversal were exercised through the Livewire component in `render-cutover.php` — including the password-confirm redirect and the posted/reversed states — but the Owner's forms were not *seen* in a browser, because that would require the Owner's password; a Dispatcher's 403 was likewise proved in-process. The in-app browser's viewport is fixed at 590 px, so the desktop layout was verified by computed styles, not by eye. The dev server and the two scripts are local-only and gitignored, as with every other phase.

**Gates.** `pint --test` clean, `lint-architecture` PASS (205 files, D1–D6), `lint-section-refs` PASS, `verify-cutover` 47/47, `render-cutover` RENDER OK.

---

### 20.13 Phase 1 — 2026-10-29, the master data the operational phases count on

Scope as approved: fleet (trucks, trailers, equipment, odometer, assignments), people (employees, drivers with masked licence and national ID, their machines and policies), the commercial master (customers, routes with rate-card windows), the parts register, the nine master-data importers, and the document register's subject links — everything §15.2 lists before a trip can exist.

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Fleet | `app/Domain/Fleet`, `/fleet`, `/fleet/trucks/{truck}` | `FleetStatus` machine, `AssignVehicle` with a row lock and overlap refusal, odometer readings guarded by §4.3's monotonic rule (a backwards reading is accepted as a *correction* only through the projection's own rule, and warns). |
| People | `app/Domain/People`, `/people` | Employees and drivers, `DriverStatus` machine, §9.5's encrypted + masked column trios, and `DriverDirectory`/`DriverOption` as the only door other modules may read through (§13 D5). |
| Commercial master | `app/Domain/Operations` (routes), `/routes`, `/customers`, `/parts` | Corridor + rate card on one screen because a route with no price is a plan, not an offering; `RouteRateWindow` refuses overlapping windows; the parts register takes money through `Money::ofMajor()`, never a float. |
| Importers | `app/Domain/Kernel/Cutover` + `Import*` actions | Nine datasets, each with a create→update round trip, under the Phase 0.5 batch schema. |
| Documents | `/documents?subject_type=truck\|trailer\|driver\|employee&subject_id=` | A paper filed against a subject is found from the subject's own page, and expiry drives the §14 alert. |

**Defects the phase caught in itself** (each fixed and then covered by a check): `FleetSeeder` had drifted from the DTO contracts and was rewritten; the importers' update path silently accepted a row that could not exist on create; the masked column was being re-derived from plaintext on read instead of stored at write.

**Verification.** `php scripts/verify-master.php` — **127 checks, 0 failed** — proves §4.3's engine CHECKs on live MySQL, both state machines, odometer monotonicity, §10.2's dispatch refusals at the seam, §9.5's masking, all nine importers, the expired-licence alert, route gates and tenancy. `php scripts/render-master.php` — **`RENDER OK`, 30 labels** — drives the real HTTP/Livewire stack: the drivers tab prints the mask and never the plaintext, a dispatcher sees no purchase amount, a refused assignment surfaces as an `assignment` error beside the button, and a foreign tenant's truck id answers 404.

**Honest gaps.** The live browser pass was blocked by the environment, not the phase: stale `APP_KEY`-era server processes plus a session cookie that is not port-scoped made every web-written session undecryptable. The diagnosis and the rule that follows it (`scripts/*.php` must never start a server) are in `docs/DEV-DATABASE.md`; the screens were proved in-process instead. Upload-progress percentage on the importer forms is still a spinner.

**Gates.** `lint-architecture` PASS, `verify-master` 127/127, `render-master` RENDER OK.

---

### 20.14 Phase 2 slices 2a and 2b — 2026-10-29, the job, the trip and the board that dispatches them

Scope taken so far: **2a** the job and trip spine (§10.2's machine, §10.3's job states, §7's `JOB-`/`TRP-` numbering), **2b-i** the loading schedule and its gate, **2b-ii** the three screens — dispatch board, jobs board, trip detail. Kiosk POD, trip costs and chargeables, crossings and GPS are the rest of the phase and are not built.

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Tables | `…170000_create_freight_jobs_table`, `…170010_create_trips_table`, `…180000_create_loads_table` | `freight_jobs` — **not** `jobs`, which the queue driver owns and the first migrate proved by colliding. 7 + 9 + 6 CHECKs, including `trips_plan_is_named`, `trips_departed_has_odometer`, `trips_frozen_is_closed`, `loads_waybill_document_fk`, and composite `(id, company_id)` keys so a child row cannot point across tenants. |
| States | `JobStatus`, `TripStatus` | Storable states only; §10.3's four derived badges (`all_trips_completed`, `ready_to_invoice`, `invoiced`, `settled`) are answered from the children by `JobProgress` and have no column — the sweep asserts the columns do not exist. |
| Gates | `Support/DispatchGate`, `Support/LoadingGate` | §10.2's `planned → assigned` tests the truck, the crew's two papers **as of the trip's start date**, expired *filed* documents and driver double-booking, and returns every finding at once. Loading measures tonnage against the **weaker** of truck and trailer, refuses a missing or voided waybill, refuses an empty manifest, and refuses a null capacity rather than passing it. |
| Actions | 24 in `app/Domain/Operations/Actions` | `Create/Quote/Accept/Decline/Close Job`, `Create/Plan/Dispatch/Advance/Depart/Complete/Close/Reopen/Hold/Stop Trip`, `Add/Remove Load`, `MarkLoaded`. Cross-module writes go through Fleet's `BeginTruckTrip` / `EndTruckTrip` / `ReleaseTripAssignment`, which take ids — no model crosses the boundary (§13 D5). |
| Screens | `/dispatch`, `/jobs`, `/trips/{trip}` | The board is a week × the fleet with findings on the cell; the jobs register runs the machine through quote → accept → decline → close; the trip detail carries the schedule, the gate's refusal, and §4.4's trail in the order the engine wrote it. |

**Decisions that deviate from the prose, deliberately:**

1. **Click-to-assign instead of §3.2's drag-drop day × vehicle grid.** A cell opens a dialog that names the driver, lists what is wrong and refuses with the reason. A gesture that drops a truck into a day it cannot serve teaches the operator to distrust the board; this is the phase's DoD surface, and it is now the board's.
2. **A job's own quote is printed for anyone who may read the register** (`jobs.view`), *not* behind §9.4's `seeCustomerTerms`. The Dispatcher writes that quote (§9.3's row is C / C), so hiding it would mean quoting blind. What stays hidden from that seat is rate **versus** cost — `jobs.rate_cost.view` — which becomes a real figure when trip costs land. The route rate card keeps the field gate: it is the standing price list, not the number being typed.
3. **`trips_clock_order` no longer requires `departed_at >= planned_departure_at`.** Leaving early is a normal fact of the road; the engine refused it, and an engine that refuses reality gets worked around.
4. **`HoldTrip` releases the vehicle reservation**, so a held truck reads as free on the board (§10.2), and the edge is one control in both directions — labelled from the trip's own state, because a button that says "Put on hold" on a held trip is how an operator gets a release they did not ask for.

**Defects this slice caught in itself**, each now covered: `CreateTrip` wrote `truck_id = 0` placeholders (columns made nullable plus `trips_plan_is_named`); `ReopenTrip`/`CloseTrip` set the cost-freeze flag after the move, so the engine rejected a half-write; `ReleaseTripAssignment` counted a refused release as success and left a held truck reserved while the board said free; a zero-width reservation then blocked re-dispatch; `CompleteTrip` never released the assignment, so a completed truck could never be dispatched again; `AdvanceTrip` allowed `assigned → loaded`, bypassing the gate its own docblock said was unreachable; `Trip::tonnes()` was memoised and returned a stale total after `AddLoad`; `Job.rate_type` was read as a string where `RouteRate` casts the same column to an enum (the cast is now on both models).

**Verification.** `php scripts/verify-operations.php` — **182 checks, 0 failed** — including the weaker-of-two-limits case (30.5 t against a 34 t truck and a 28 t trailer), the DoD refusal, and completion releasing the reservation; it aligns the `job`/`trip` counters before and after the run, because `documents:number-integrity` compares the counter to the surviving rows. `php scripts/render-operations.php` — **30 named assertions, `RENDER OK`** — drives the real stack: the board's 8 column headers, the §10.2 finding count printed on the cell, dispatch refused from the dialog with the trip still `planned` and no reservation written, a foreign tenant's trip neither listed nor actionable, the quote's amount nowhere in the board's HTML, a read-only seat with no `openPlan` affordance, `jobs.view` refused with 403, a blank decline reason as a field error, and the trip detail walked from `assigned` through the loading refusals, the odometer refusal, every road edge, completion, close and re-open with its reason on the trail.

**Honest gaps.** POD capture, trip costs and chargeables, crossings and GPS remain (§15.2 Phase 2). The cross-phase dashboard rule is met: the overview carries a fourth band — jobs waiting on a decision, planned-but-not-dispatched, on the road, proof of delivery outstanding — counted from `freight_jobs`/`trips` rows, masked to the ability rather than deleted for a seat without `jobs.view` (`render-operations.php` proves both). Nothing has been committed: the repository still holds one commit and the whole build dirty, and no remote exists.

**Gates.** `lint-architecture` PASS (359 files, D1–D6), `lint-section-refs` PASS, `verify-operations` 182/182, `verify-master` 127/127, `render-master` RENDER OK, `render-operations` RENDER OK.

---

### 20.15 Phase 2 slice 2c — 2026-10-29, the operations band on the overview

The cross-phase dashboard rule gives every phase its own cards, and 2a–2b's band is the one the dispatcher opens before anything else: **jobs waiting on a decision** (quoted, counted from `JobStatus::Quoted`), **planned, not dispatched**, **on the road**, and **proof of delivery outstanding** (arrived or `pod_pending` with no submitted proof). Each card is counted from surviving rows at render time — no cached counters to drift — and links to the register that answers it (`/jobs?status=quoted`, `/dispatch`).

The masking is the part worth recording. A seat without the ability does not get the card deleted — it gets `lockedCard()`, which names the figure's label and the ability that would show it, so a dispatcher can see *why* health is hidden rather than assuming the dashboard is empty. `render-operations.php` proves both halves: the owner reads the planned count, the workshop seat reads the lock, and no quoted amount reaches the HTML (§9.3 — the band counts work, it does not value it).

**Gates at the time.** `render-operations` RENDER OK (the dashboard assertions are part of that sweep), `verify-operations` 182/182.

---

### 20.16 Phase 2 slice 2d — 2026-10-29, the kiosk and the proof of delivery

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Tables | `…190000_create_trip_access_tokens_table`, `…190010_create_proof_of_deliveries_table`, `…190020_operations_relax_kiosk_scope_check` | `trip_access_tokens` keeps SHA-256 of the secret and of `sha256(pin|secret)` — never the plain values — with `token_hash` UNIQUE, `expires_after_issue`, and composite `(id, company_id)` keys. `proof_of_deliveries` carries `client_uuid` UNIQUE (offline dedupe), the §10.2 gate's CHECKs (`pod_approved_is_signed`, `pod_dated_once_submitted`, `pod_quantity_positive`, `pod_receiver_not_blank`), and FKs to the signature document and the capturing token. The corrective migration widens `trip_access_tokens_scopes_not_blank` to 1..64 characters: the first draft required four, and refused `pod` — the only scope this build ships. |
| Actions | `IssueTripAccessToken`, `RevokeTripAccessToken`, `RedeemKioskToken`, `CaptureProofOfDelivery`, `ApprovePod`, `RejectPod` | Issue refuses an empty scope list, a scope no Action reads yet (`TripAccessScope::implemented()` is `pod` alone), a driver who is not the trip's, and a driver with `kiosk_enabled = false` — then returns the plain secret and PIN exactly once. Redeem forces the tenant from the path and stamps `last_used_at`/`last_ip`. Capture refuses everything that is not evidence before writing a row: no signature, no photo, no receiver, no quantity, or an unanswered exceptions question is a refusal with the list of what is missing. |
| Kiosk door | `routes/kiosk.php`, `EnsureKioskToken`, `⚡capture.blade.php` | `/kiosk/{company}/{secret}` under `web`, `throttle:kiosk` and `kiosk` — no `auth`, because a driver has no account (ADR-0006). The company is in the path because a driver request has nothing to resolve one from, and every dead link (guessed, revoked, expired, wrong company) answers 404 with no reason. The page asks the four §10.2 questions and nothing else; the signature is drawn on a canvas and stored through the document module as a PNG, never as a base64 column. There is no draft. |
| Office panel | `⚡detail.blade.php` | One section, two permissions: issue a scoped link and close it, and accept or send back the proof. The one-time reveal (URL, PIN, horizon) is held in the **session**, not in a Livewire property, because a property travels back in the client-readable snapshot; the row shows only hashes and a state (Live). |
| Trail | `IssueTripAccessToken`, `RevokeTripAccessToken` | §14.4's `kiosk_token_issued` / `kiosk_token_revoked` security events name the token, trip, driver and scopes, so "who could have written that POD at 03:12?" is answered by the issuance. |

**Decisions worth recording.**

1. **A rejection leaves the trip at `pod_pending`.** §10.2's rule is that the delivery is unproven, not unmade: sending the paper back must not un-arrive the truck, and moving it backwards would rewrite the road. The driver's link stays live and the second capture creates a second row; the rejected one keeps its reason under `exceptions.rejected_for` for review.
2. **`pod_pending` accepts a capture.** The first draft allowed only `arrived`, which made §10.2's own rejection loop impossible: a driver told to photograph it again could not submit again.
3. **Signatures are documents.** The canvas hands over a data URL; the page decodes it into a temporary PNG and passes a `DocumentInput` through the same sniffer, MIME-truth and retention rules as any other upload, then deletes the carrier file at shutdown. A base64 column would have put the one artefact a POD must never lose outside §10.8.
4. **Scopes are promised only where an Action reads them.** `TripAccessScope::implemented()` returns `pod` today; odometer arrives with telematics, fuel and expense in Phase 3. Issuing `fuel` now would hand the driver a form that cannot save — that is refused at issue time, on the office screen, rather than at the pump.

**Defects this slice caught in itself**, each now covered: the scopes CHECK refused the only live scope; `ProofOfDelivery::load()` shadowed Eloquent's `load($relations)` and fataled on boot (renamed `loadRecord()`); `blank([])` is true in PHP, so "nothing went wrong" — recorded as an empty list — read as an unanswered question (now a strict `null` check); the reveal route was generated without the `{company}` segment and would have thrown on the first issuance; and the panel's `at()` helper was typed `?Carbon` while the date casts hand back `CarbonImmutable`, which the render sweep hit the first time a token row existed.

**Verification.** `php scripts/verify-kiosk.php` — **110 checks, 0 failed** — covers hash-only storage and hidden serialisation, the PIN bound to the secret, the security trail, path tenancy, pre-arrival and wrong-PIN refusals, the unanswered-exceptions gate, the full capture with two sniffed documents, `client_uuid` idempotency landing on the same row and documents, §10.2's kiosk-cannot-deliver rule, approval crossing to `delivered`, double-approval, capture after delivery, the rejection/resubmission loop, and revocation/expiry as separate doors. `php scripts/render-kiosk.php` — **17 named assertions, PASS** — drives the real stack: a GET with no session opens the driver page, a guessed, foreign-company or revoked secret is 404, the trip screen issues a link and prints the same URL the session holds, that URL opens the capture form for the right trip, the reveal can be dismissed while the row stays, a workshop seat sees neither door nor decision, the submitted proof renders with both decisions, accepting moves the trip to `delivered` and removes the controls, rejection shows its reason and leaves the trip at `pod_pending`, and the driver's link reopens the form — then approval shows "Check again" instead.

The full set re-run after the slice: `lint-architecture` PASS (374 files, D1–D6), `lint-section-refs` PASS (1 675 citations, 528 files), `pint` PASS (67 files), `verify-operations` 182/182, `verify-kiosk` 110/110, `render-operations` RENDER OK, `render-kiosk` PASS, `documents:number-integrity` 27 checked / 0 drifting.

**Honest gaps.** Only `pod` is open to the kiosk; odometer, fuel and expense scopes wait for the Actions that read them. The photo budget is the document module's 8 MiB MIME-checked limit — no client-side downscaling, so a bad camera on a bad link is a refused upload rather than a slow one. The offline retry is idempotent only for the lifetime of the page's `client_uuid`; a driver who reloads and retypes is a second delivery note, deliberately. Trip costs, crossings and GPS remain (§15.2 Phase 2). No browser pass has been run on this slice yet — the in-process render sweep is the stand-in, and the shared-phone layout has only been proved at the markup level.

---

### 20.17 Phase 2 slice 2e — 2026-10-29, trip costs, earnings and the rollup that keeps them honest

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Tables | `…200000_operations_add_trip_rollups`, `…200010_create_trip_costs`, `…200020_create_chargeables` | The rollup migration adds §6.6's five bucket columns plus `revenue_base`, `direct_cost_base`, `contribution_base`, `ledger_posted_at` and `costs_frozen` to `trips`, with a CHECK that the buckets sum to `direct_cost_base` and that `contribution_base` is revenue minus that sum. `trip_costs` stores the §5.2 eight-column money block, the bucket type, who was paid, the optional source pair `(source_type, source_id)` UNIQUE for retried captures, and the trip as parent. `chargeables` stores what a delivered trip has earned: type, basis, amount/base amount, status, `source_uuid` UNIQUE. |
| Actions | `RecordTripCost`, `BuildChargeablesFromPod`, `ApprovePod` | `RecordTripCost` validates before writing (positive amount, 160-character label, the driver a driver-cost needs), converts with `Fx::foreignToBase`, posts one debit to the bucket's account and one credit to the account paid from, then rewrites the rollup in the same transaction. `BuildChargeablesFromPod` turns the job's quote and the POD's tonnage into the earned row, idempotent on `pod:{id}`. `ApprovePod` calls it after the state move, so approval is the earning event. |
| Rollup | `TripCostRollup`, `LedgerService::totalsByAccount`, `TripsRecost` | The projection is **derived from the ledger, never incremented**: `totalsByAccount(['trip_id' => …])` sums the lines per account and side through the ledger's own door (D3), the bucket map decides which account joins which column, and `trips:recost` recomputes it later — reporting `DRIFT` with exit 1, repairing under `--fix`, and naming an unknown `--trip`. |
| Screen | `⚡detail.blade.php` | Three abilities, none widening another: `expenses.create` shows the cost table and the record dialog, `trips.margin.view` shows revenue/direct/contribution and the bucket chips, `invoices.view` shows the billing rows. The frozen rule greys the form on a closed, cancelled or aborted trip and the Action refuses as well. |

**Decisions worth recording.**

1. **Contribution is costs alone until Phase 4.** Revenue stays at zero because no invoice has been raised, so the screen says so in place rather than printing a negative figure that reads like a company losing money on every trip. The card cites this entry.
2. **An advance is not a cost.** Fuel money handed to a driver posts to 1160, which joins no bucket; the bucket map returning null for it is what keeps the rollup a direct-cost report rather than a cash report.
3. **`chargeables` is the earning, not the invoice.** A delivered trip earns a row the billing screen can count before Phase 4 owns invoicing; `per_km` builds nothing because a POD carries tonnage and no measured distance, and a silent guess would be a made-up price.
4. **The rollup columns are a cache with a warrant.** They exist because the dashboard reads them per trip; `trips:recost` and the CHECK constraints are the warrant, so the cache cannot drift silently.

**Defects this slice caught in itself**, each now covered. `JournalLine` casts `direction` to the `Side` enum, and the first rollup compared it to the string `'debit'`: every sign inverted, and every real cost threw "net credit on account 5100" on the first verification run. The `trips_plan_is_named` CHECK refused the frozen/cancelled fixtures that named no crew; the fixtures now name one, which is the rule working. The kiosk sweeps could not delete a trip once approval had built its earning, and their crashed runs had left orphan POD documents behind; both scripts now remove chargeables before trips and sweep orphan proof documents at start.

**Verification.** `php scripts/verify-costs.php` — **151 checks, 0 failed** — covers the schema constraints, the FX invariant, eight costs across five buckets and two currencies, balanced entries with their dimensions, exact bucket sums, the advance's exclusion, retried captures landing on one row and one entry, every refusal (zero and negative amounts, an over-long label, a foreign amount below one minor unit, a driverless driver-cost, a frozen trip, a cancelled trip, and a dispatcher with no `expenses.create`), `trips:recost` clean/drift/fix/unknown-trip, chargeable math including HALF_UP rounding, `per_km` building nothing, tenancy, and `ledger:verify`. `php scripts/render-operations.php` — **RENDER OK** — drives the real stack: an accountant records through the dialog (zero refused, then 250.00 posted, projection moved, HTML carrying the cost and the margin), a dispatcher reads the billing row and never the margin or the form, the POST is refused at the domain gate as well, and the sweep leaves no cost, earning, entry or line behind.

**Gates at the time.** `lint-architecture` PASS (D1–D6; the rollup's first draft was a D3 violation and now reads through `LedgerService::totalsByAccount`), `lint-section-refs` PASS, `pint` PASS, `verify-operations` 182/182, `verify-kiosk` 110/110, `render-operations` RENDER OK, `render-kiosk` PASS, `documents:number-integrity` 27 checked / 0 drifting.

**Honest gaps.** Nothing captures a border crossing yet — 2f owns the table and the screen that will call `RecordTripCost` with its source pair. Fuel issued from stock (Phase 3) and payroll-allocated driver time will post costs this screen did not raise; the rollup handles them by construction, but no such poster exists to prove it. No browser pass has been run on this slice: the in-process render sweep is the stand-in until the shared server is free. `chargeables` has no screen of its own; the trip panel is the only place it is read before Phase 4.

---

### 20.18 Phase 2 slice 2f — 2026-10-29, the gate the truck stands at, and what it charged

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Tables | `…210000_create_border_posts_table`, `…210010_create_border_crossings_table`, `…210020_create_border_costs_table` | `border_posts` is master data — name, country, coordinates and opening hours — with `(company_id, name, country_code)` UNIQUE so one gate is one row. `border_crossings` pins a trip to a post and a direction, `(company_id, trip_id, border_post_id, direction)` UNIQUE so a retried plan returns the row it made; CHECKs carry §10.9's machine, `exited_at ≥ entered_at`, and a past-entered state must hold both times. `border_costs` is one charge at one gate: kind, the §5.2 eight-column money block, `trip_cost_id` UNIQUE tying it to the trip cost it posted. Its key is a ULID although §4.4's sketch writes `bigint`, because the row is the `source_id` of its own trip cost and that column is `char(26)`. |
| Actions | `CreateBorderPost`, `PlanBorderCrossing`, `MarkCrossingEntered`, `MarkCrossingExited`, `CaptureBorderCost`, `FinishBorderCosts` | Plan refuses another company's gate, a closed/cancelled/aborted trip, and a duplicate (trip, gate, direction) — returning the first row rather than a second. Enter takes the transit document and stamps its own time; exit takes the clearance word and refuses to precede the entry. Capture posts through `RecordTripCost` with source `(border_cost, ULID)`, then creates the border row **copying** the eight FX columns from that posting, so there is one conversion and one authoritative figure. Finish is a person's claim that the gate is done being charged, and refuses a crossing with nothing on it. |
| §10.2 completion | `CompleteTrip` | The checklist line that waited for this table: a crossing still `planned` or `entered` refuses completion and names the gate and its state; a `costs_captured` crossing does not. Fuel reconciliation still waits for Phase 3, absent rather than assumed. |
| Screens | `⚡index.blade.php` (crossborder), `⚡detail.blade.php` | The register lists every crossing with trip, gate, times, state and charge total, filters by trip/gate or status, and carries the gate list and a `crossborder.manage` dialog to register one. The trip page carries the acts — plan, enter, exit, charge, finish — readable with `crossborder.view`, actionable only with `crossborder.manage`. |

**Decisions worth recording.**

1. **A crossing does not move the trip.** The trip's state is the truck's; a crossing is a fact at a gate. Coupling them would let a form in an office un-arrive a truck, and §10.9's machine is rich enough on its own. The dispatcher still advances the trip; the gate is recorded beside it.
2. **Capture is open at `entered` and `exited`, sealed at `costs_captured`.** A fee is often paid before the paper reaches the office, and back-filling from the paper after the truck has left is the common case. Once a person declares the gate finished, a late charge is a correction, not a capture.
3. **One conversion, then a copy.** The trip cost is the posting record: it holds the rate, its source and its date, and the border row copies them. Recomputing on the border side would let two rows disagree about what the bank said, which is the one thing §5.2 exists to prevent.
4. **Phase 6's two states are named but unreachable** (`agent_reconciled`, `closed`): the enum and the CHECK carry the full vocabulary, and `isReachableHere()` plus the absent Action keep this build out of them, the same way §15.2 keeps Phase 4's invoice shut.
5. **The register is a register.** Entering, exiting and charging happen on the trip where the truck, driver and money are on one page; the cross-border screen answers "which gates do we use, what is still open, and what did they cost" and registers master data, nothing more.

**Defects this slice caught in itself**, each now covered. `border_crossings` inserted nothing — no id generation on the model — and its ULID key was then bound as an integer on read (`ModelNotFoundException`); `border_costs` had the same missing key setup plus `id` filtered out by mass assignment. `fx_rate_source` had no enum cast, so "the rate and its source are frozen" failed on the first assertion. The register's status filter was a `static fn` closing over `$this`, and the first request with a filter threw *Using $this when not in object context* without moving any data; the jobs register had carried the same bug since 2a, and both are fixed and now exercised by the render sweep. Re-running the architecture gate — which had not been run since 2e's last edit — refused `RecordTripCost`'s import of the `Account` model (now reads ids through `ChartOfAccounts`) and `TripsRecost`'s command description naming `gl_journal_lines` (now prose). It also banned `LineDto`, which made the sanctioned `PostingDto` impossible to build for any module; the rule now bans the ledger's models and barrier, not the door's input vocabulary.

**Verification.** `php scripts/verify-crossing.php` — **99 checks, 0 failed** — covers the schema promises on live MySQL, gate registration refusals, the plan/retry/two-directions/closed/cancelled paths, enter and exit guards with their trail and the schema's own time CHECK, charge capture in base and USD (450.00 → 5400/1120; 100.00 at 13.50 → 135 000 minor, every FX column equal to the posting's), capture-on-planned and capture-after-finish refusals, the zero-charge finish refusal, §10.2 completion blocked by planned and entered crossings and passing a finished one, dispatcher permissions and cross-company scoping, `ledger:verify`, and a cleanup that proves every fixture gone. `php scripts/render-operations.php` — **RENDER OK** — drives the real stack: the gate is registered through its dialog and a duplicate refused beside it; the trip plans a crossing, enters it, exits it, captures a 450.00 duty through the dialog (ledger 5400 → 1110 at 45 000, rollup border 45 000 / direct 70 000, three trail events in order) and finishes it; the register lists it with its charge and its filter narrows past it; a dispatcher reads the same section and the register, is offered no control, and a hand-made POST is refused at the domain gate.

**Gates at the time.** `lint-architecture` PASS (408 files, D1–D6), `lint-section-refs` PASS (1 874 citations across 566 files), `pint` PASS (495 files), `verify-operations` 182/182, `verify-costs` 151/151, `verify-crossing` 99/99, `render-operations` RENDER OK, `ledger:verify` clean, `documents:number-integrity` 27 checked / 0 drifting.

**Honest gaps.** The tax side of a border charge is deferred (§15.2): `recoverable_input_tax`, `tax_rate_id`, `receipt_document_id` and `payable_to_agent_id` ship with Phase 6's reconciliation, as the migration records. `agent_reconciled` and `closed` have no door, and nothing compares the agent's account to the charges yet. The kiosk cannot plan or charge a crossing; the driver's phone is for the POD and later the odometer. `weighbridge_ticket_id` is a column without a foreign key because the weighbridge table is Phase 6's. No browser pass has been run on this slice — the in-process render sweep is the stand-in until the shared server is free.

### 20.19 Phase 2 slice 2g — 2026-10-30, the fix the truck reported, and the map that reads it

**What exists now.**

| Piece | Where | Shape |
|---|---|---|
| Tables | `…220000_create_gps_provider_accounts_table`, `…220010_create_gps_positions_table`, `…220020_create_latest_positions_table` | `gps_provider_accounts` is one feed per adapter per company, `(company_id, adapter)` UNIQUE, with a CHECK that a stored credential and its `key_version` arrive together (§9.5) and **no CHECK on `adapter`** — Phase 7 adds a provider with a config line, not a migration. `gps_positions` is §2's high-volume shape: `bigint` auto-increment, `UNIQUE(company_id, provider, provider_message_id)` as the webhook retry guard, both indexes a reader needs (truck × clock, trip × clock) plus the clock index Phase 7's retention sweep will use, and CHECKs on the coordinate ranges, the bearing range and a non-blank message id. `latest_positions` is §14.3's projection — `UNIQUE(company_id, truck_id)`, one row per truck, citing `position_id` `on delete set null` so a prune cannot leave it claiming a fix it no longer has. |
| The seam | `Telematics/Contracts/GpsProvider`, `Adapters/SimulatorProvider`, `Adapters/Registry` | An adapter's only job is **parsing** a provider payload into `PositionDto`s: it does not write, resolve tenancy or decide what a trip is. The Registry resolves `gps_provider_accounts.adapter` through `fleet.telematics.adapters` and refuses an unregistered name rather than ingesting into a silent dead end. |
| Ingest | `Actions/IngestPositions`, `Actions/EnsureSimulatorAccount` | One webhook batch, one transaction: `insertOrIgnore` in chunks of 500 (the dedupe is the unique key, not a pre-flight SELECT), the newest fix per truck moved into the projection, `last_sync_at` stamped on the feed. A malformed *batch shape* throws — the provider sent something unreadable — while a message that parses but is nonsense (91° north, a truck not in this company's fleet, a heading of 400°, a clock six hours fast) is refused **per message** and logged, so one broken device does not blind the map. A payload naming a trip is believed only if that trip is that truck's; one naming nothing is attached to the trip the truck is on. |
| Simulator | `Console/Commands/GpsSimulate` | `gps:simulate [trip] [--from= --to= --pings= --speed= --company=]` walks a truck along its own corridor — progress read back from the odometer of the last fix it stored, so each run continues instead of restarting — and sends what it generates through `IngestPositions`, the same door a gateway will use. Coordinates come from `fleet.telematics.simulator.places` matched against the route's endpoint names; a corridor that is not in that table is **skipped with its name in the message**, never guessed at, and `--from/--to` is the escape hatch. |
| §13 D5 doors | `Operations/Queries/TripsInMotion` + `Data/TripInMotion`, `Telematics/Queries/LiveMap` | Telematics may not import `Trip`, so Operations answers with a DTO (`truck_id`, label, corridor, distance, opening odometer, departure clock) and `currentForTruck()` for the attribution rule above. `LiveMap` reads the projection for "where is the fleet" and the history for one trip's track, capped at 200 fixes. |
| Screen | `⚡live.blade.php` at `/telematics` (`telematics.live`, `gps.view`) | Markers are plotted straight from latitude and longitude into an inline SVG over a fixed Southern-Africa box, with the selected trip's breadcrumb drawn beneath, one row per truck beside it, and a track table that prints the fix clock, both coordinates, speed, odometer and the provider's message id. The transport is `wire:poll` at `fleet.telematics.poll_seconds` (§12.2 defers Reverb), and a fix older than `stale_after_minutes` is badged stale **and still shown** — "last heard from four hours ago at Kazungula" is the useful sentence. |

**Decisions worth recording.**

1. **The map is a schematic in Phase 2 and that is the plan, not a shortfall.** §15.2 puts Leaflet/OSM tiles, planned-vs-actual, ETA, geofences and deviation alerts in Phase 7; what Phase 2 owes is ingest + simulator + trip map, and a coordinate-true drawing needs no key, no network and no vendored JS. The data contract is already the Phase 7 one: Phase 7 swaps the drawing surface, not the ingest.
2. **A stale fix is shown, not hidden.** §16 R6 says no business invariant depends on GPS; the corollary is that the screen must not pretend silence is absence.
3. **Per-message refusal.** A webhook that rejects a whole 200-row backlog because one device woke up with a broken RTC is the failure mode that makes a fleet manager distrust the map. The batch shape is the provider's contract; the values are the device's.
4. **The projection moves only forwards.** Devices buffer, so fixes arrive out of order. Rewinding the map to an hour-old position would be a worse lie than dropping nothing.
5. **The simulator has no coordinates of its own.** It reads the corridor the route already names, or it refuses. An invented coordinate would put a truck somewhere it never drove, which is the one thing a simulator must not do; §17 Q7's depot and geofence coordinates still belong to Phase 7.

**Defects this slice caught in itself.** The map's own panel found an **app-wide one**: `x-ui.table` renders `$slot` and never `head`, so the `<x-slot name="head">` that every list screen has passed since Phase 0 was silently discarded — **no table in the application was printing its column headers**. The component now emits `<thead>` for that slot and `<tbody>` for the rows, and the live map's track panel lost its `<x-slot name="header">` on the card because a named slot inside a named slot is where the head slot went missing a second time. `gps:simulate` initially attributed its truck's state event to whoever the previous render assertion had left on the guard (the sweep now logs the owner in before it creates fixtures), and the purge then died on `operational_events.actor_id`'s real FK to `users`: a probe seat's rows must go with the probe seat, including the truck-level events no trip-scoped sweep reaches. Two verifier arithmetic traps were fixed the same way: a 30 s ping at 80 km/h is two-thirds of a kilometre, so *continuation* is proved by the coordinate and the odometer is only asserted not to run backwards, and a latitude bound written as `≤ origin` is backwards when the corridor runs north.

**Verification.** `php scripts/verify-telematics.php` — **126 checks, 0 failed** — covers the schema promises on live MySQL (including §2's bigint-not-ULID shape for the high-volume table), adapter resolution and the unknown-name refusal, an inactive feed refusing messages and the ensure-door being idempotent, a three-fix batch read back column by column with `raw_json` intact, the retry guard (same batch twice → 0 stored / 3 duplicates), a repeated message id inside one request, four refused messages in a batch whose fifth still landed, five batch-shape refusals, the projection refusing to rewind, 600 fixes arriving as **2 INSERT statements** and replaying as 600 duplicates, trip attribution both when the payload says nothing and when it names the wrong truck's trip, the simulator advancing a real corridor and skipping the one it has no coordinates for, `--from/--to`, both non-road refusals, `LiveMap`'s two reads, `gps.view` held by dispatcher and workshop and not by the stores clerk, company-2 scoping with an empty map, `ledger:verify` and `trips:recost` still clean with 600-odd fixes on file, and a cleanup proving every fixture gone. `php scripts/render-operations.php` — **RENDER OK** — puts a truck on the road, files five fixes through the command, opens `/telematics` over the real stack (truck, fix, speed, `wire:poll`), selects the trip and reads the breadcrumb table with its provider message ids, proves the toggle closes, gives a dispatcher the same map, gives a seat without `gps.view` a 403, gives a tenant with no feed the empty state instead of another company's trucks — and asserts the job's quote (`555555`, in any of its printed shapes) reaches none of it.

**Gates at the time.** `lint-architecture` PASS (426 files, D1–D6), `lint-section-refs` PASS (1 973 citations across 587 files), `pint` PASS (515 files), `verify-telematics` 126/126, `render-operations` RENDER OK. **Not re-run:** `render-master`, `render-kiosk`, `render-cutover` and the other `verify-*` scripts — EnvKit's MySQL stopped mid-session after the two above passed. Because this slice edited the shared `x-ui.table` primitive, those sweeps must be re-run before Phase 3 opens; the render-operations sweep covers five table screens (dispatch board, jobs register, trip detail, cost table, crossing register, live map) and they came out right, but the fleet, documents and security registers have not been re-rendered since.

**Honest gaps.** No webhook route is registered: the ingest door is called by the simulator and, from Phase 7, by a provider endpoint with its own authentication (§14.9's API surface). Retention and archiving are Phase 7's, so `gps_positions` currently grows without a prune — the migration records why a prune with no archive would be data loss. No geofences, deviation detection or ETA yet; §14.1's alert vocabulary for `geofence`/`route_deviation` still has no producer. The projection is rebuilt by ingest only — there is no `positions:rebuild-projection` command, which is the first thing Phase 7 needs after a prune. `api_key_encrypted` is never written by anything in this build, and the config's place table is simulator reference data, not the depot/geofence coordinates §17 Q7 still asks for. The driver column of §9.3's GPS row ("own trip") has no seat to apply to, since drivers are kiosk tokens rather than users. No browser pass has been run on this slice either.

---

**End of design document.**