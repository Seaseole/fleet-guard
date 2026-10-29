# API surface — the contract before the endpoints

**Status: no HTTP API endpoints exist.** `bootstrap/app.php` registers `routes/web.php`, `routes/console.php`
and the `/up` health path — there is no `routes/api.php` and no `withRouting(api:)` entry. DESIGN.md §14.9
settles the order: the *surface* is written in Phase 0, the endpoints land when an integration needs them,
and the trip-scoped kiosk of §9.6 is that first consumer. Writing the contract now is what stops the first
integration from deciding it under deadline.

What Phase 0 slice 11 did install: `laravel/sanctum` **v4.3.3**, which resolved and installed against
`laravel/framework` 13.38 — the compatibility row in §12.1 is now an observed fact rather than a Packagist
reading. Its `personal_access_tokens` table is migrated, and `config/sanctum.php` is published with a token
prefix (§5.4 below). Nothing issues a token yet.

## 1. What makes this a thin layer rather than a rewrite

Two rules already hold in the code, and they are the whole reason an API can be added without touching
business logic (§2's "every business write goes through an Action"):

| Rule | Where it already bites |
|---|---|
| A write is one Action: immutable DTO in, model or DTO out. | `PostManualJournal::handle(...)`, `UploadDocumentVersion`, `InviteUser`, `TransitionAlert`, `RetryFailedJob`. Livewire components contain authorisation and presentation, never arithmetic. |
| A number is allocated, never composed. | `Kernel/Numbering/NumberAllocator` with the per-scope formats and restart policies in `config/fleet.php`. |

A controller therefore does: authenticate → validate into a DTO → call the Action → serialise the result.
If a future endpoint needs an `if` that decides a business outcome, that `if` belongs in the Action and the
UI gets the benefit of the fix too.

## 2. Principals, and what each one may reach

| Principal | Credential | Scope of what it can read | Factor requirement |
|---|---|---|---|
| Office staff integration | Sanctum personal access token on a `users` row | whatever that user's roles and permissions reach, inside their own company | inherits §9.1 — see §3 |
| Driver at a kiosk | `trip_access_token`: hashed, bound to one trip and one driver, scoped `pod \| odometer \| fuel \| expense`, `expires_at` = planned trip end + 24 h, revocable, redemption rate-limited (ADR-0006) | one trip, four screens, nothing else | none, deliberately — see ADR-0006's rejected-alternatives table |
| First-party UI | session cookie + TOTP | as today | enforced by `EnsureMultiFactorAuthentication` |

**Kiosk tokens are not Sanctum tokens.** `personal_access_tokens` morphs to a `tokenable` model; putting a
trip credential in it would imply a driver account (§9.6 / ADR-0006 say there is none) and would make the
trip binding a convention instead of a column. The kiosk table arrives with the dispatch module and carries
its own `expires_at`, `revoked_at`, `last_used_at` and `last_ip`.

## 3. The guard sequence, in order

Every `/api/v1` route runs these, in this order, and the order is the point: a permission check against a
null tenant is a check that passes.

1. **`auth:sanctum`.** Stateless bearer token. Sanctum's own first-party-session path is not used by this
   application — the first-party client is Livewire on the same host and it does not call the API.
   `config/sanctum.php`'s `stateful` list stays env-driven; an empty-ish list is the honest default until a
   second-host SPA exists.
2. **Tenant.** `ResolveCurrentCompany` is appended to the **web** group today, so an API route must add it
   explicitly (`bootstrap/app.php`'s `api:` group) or pin it from `$request->user()->company_id`. The
   company id is never taken from a path segment, a query parameter or a body field (§8, ADR-0003): a
   document id that belongs to another company must 404, not 403, because 403 confirms it exists.
3. **Factor.** `EnsureMultiFactorAuthentication` joins the API group as well. A bearer token must not
   launder the §9.1 requirement: an endpoint that would redirect its own user to enrolment is closed to
   that user's token. This is the one item a future implementation could silently forget, which is why it is
   written here rather than left to the middleware's default scope.
4. **Permission.** The same `Gate` abilities as the UI, from `PermissionCatalogue`. There is no parallel
   API vocabulary and no `api.*` permission namespace: `invoices.view` means the same thing over either
   transport, so one role edit changes both.
5. **⚑ re-authentication.** `PermissionCatalogue::requiresReauth()` marks the abilities that require a fresh
   password confirmation (`config/fleet.identity.reauth_window_seconds`). Password confirmation is a session
   concept, so **⚑ abilities are not exposed as token endpoints** until there is a first-party flow that can
   re-confirm mid-token. Posting a manual journal, closing a period or voiding a document over a long-lived
   bearer token is refused by design, not by oversight.
6. **Throttle.** Named limiters per token *and* per IP, the shape already used by the login, two-factor,
   passkey and invite limiters. Rate limiting is per credential: one tenant's burst must not exhaust
   another's budget, so the limiter key includes the company id.

## 4. Addressing

- Externally-referenced aggregates are addressed by their **ULID** (`char(26)`): trips, jobs, invoices,
  documents, journal entries, work orders. The ULID's timestamp half is the ordering and cursor key (§11.3),
  so `?cursor=` paginates append-only lists without an `OFFSET` scan.
- High-volume append-only rows (`gps_positions`, `audits`, `stock_movements`, `gl_journal_lines`) are
  `unsignedBigInteger` and are **not** addressable resources. A count that leaks volume is a smaller leak
  than an enumerable id, but there is no reason to offer either.
- A document number (`INV-…`, `WO-…`) is a human reference, not an address. Endpoints accept it only as an
  optional lookup parameter, and the response always carries the ULID. Gap-free numbering means a number is
  evidence of sequence, which makes it the last thing you want in a URL.

## 5. Money, errors and idempotency

### 5.1 Money leaves as strings

`App\Domain\Kernel\Money\Money::jsonSerialize()` is already the rule (ADR-0004 #2):

```json
{ "amount": "123456", "currency": "BWP" }
```

`amount` is **minor units as a string** — never a JSON number, because a float in a client is precision the
sender promised not to lose; never `"1 234,56 BWP"`, because `Money::format()` is for humans and `§14.8`'s
format rules are a presentation concern. The inbound side is stricter than the constructor: HTTP accepts a
digit string and an ISO-4217 code checked against `currencies`, and builds `Money::ofMinor()` from it.
`ofMinor()` tolerating `float` is an internal convenience for values already counted in this process, not a
licence for a client to send `1234.56` — a major-unit or fractional JSON number is a 422.

A response that shows both a foreign and a base amount shows the rate and the rate date too
(`PostingDto` carries `currencyCode`, `fxRate`, `fxRateSource`, `fxRateDate`). The frozen base amount is the
answer; the client never re-derives it.

### 5.2 Idempotency

| Mechanism | Rule |
|---|---|
| `Idempotency-Key` header | Required on every POST that creates or mutates. Stored per `(company, endpoint, key)` behind a UNIQUE constraint, so a retry replays the first response instead of performing the write twice. |
| Natural keys | Where the design already has one, it *is* the idempotency key: POD `client_uuid UNIQUE` (§9.6), postings `UNIQUE(company_id, source_type, source_id, source_event_id)` (§11.3), positions `UNIQUE(provider, provider_message_id)`. |
| Reuse with a different body | 409, not last-write-wins. A caller whose retry differs is a caller with a bug. |
| Number allocation | The idempotency record is checked **before** `NumberAllocator` runs. §7.4's gap-free promise makes a re-allocated number a visible hole in a legal sequence, so a replay must return the number the first call took. |

### 5.3 Errors

Laravel's JSON exception shape (`shouldRenderJsonWhen` already answers `api/*`), with: 401 unauthenticated,
403 forbidden, 404 not found *or not yours*, 409 conflict, 422 validation, 429 throttled. Validation errors
are keyed by the DTO property so a client can branch on a field name rather than parse prose, and every
non-2xx carries a stable `code` (e.g. `period_locked`, `number_sequence_exhausted`) because `message` is
localisation-facing and §14.8 makes the language a presentation concern.

### 5.4 Tokens

`config/sanctum.php` sets `token_prefix` to `fgp_`: free leak detection from the platforms that scan for
credential shapes. A token's abilities are named with the same `PermissionCatalogue` strings, so a token can
be narrowed (`invoices.view`) but never widened past its user. **Open question, answered before the first
token is issued, not after:** `expiration` is `null` (never) today, which is not a decision — it is the
package default nobody has argued for. Staff tokens grant whatever their user grants, so a lost token is a
lost laptop with financial access.

## 6. The first three consumers, named after the Actions they will call

This table is **not** a to-do list of routes and nothing in it is implemented. It exists so that the
integration which arrives in Phase 2 or 3 finds the contract already argued for.

| Consumer | Shape | Writes through |
|---|---|---|
| Kiosk (§9.6, ADR-0006) | four screen submits: POD, odometer + engine hours, fuel issue, trip expense | the same Actions the office UI calls; audited `actor_kind = kiosk` with token, driver and trip ids |
| Accountant's system | read trips, documents and journal entries; download a document via a signed URL at `config/fleet.documents.signed_url_ttl_seconds` (60 s), never a byte stream through the API | `DownloadDocument` for the grant, `RegisterDocument`/`UploadDocumentVersion` only behind the write the role allows |
| Telematics provider | batched position ingest | one statement per batch, deduped by `provider_message_id`, §14's raw-table retention applied |

Two things are deliberately absent: no `GET /api/user` scaffolding, and no token-issuing screen. An endpoint
with no consumer freezes an interface nobody needed, and a token-issuing screen with nothing to issue a token
*for* is the §9.1 kind of surface that gets left switched on.
