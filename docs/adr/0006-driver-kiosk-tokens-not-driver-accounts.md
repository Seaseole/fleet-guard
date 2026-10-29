# ADR-0006 — Trip-scoped kiosk tokens instead of driver user accounts

**Status:** Accepted (owner chose this on 2026-10-26)
**Date:** 2026-10-26
**Decides:** how drivers capture proof of delivery, odometer, fuel and expense data in the field.

## Context

The brief requires proof-of-delivery capture (photo, signature, delivery note) from a phone, plus trip settlement, driver performance and per-trip fuel/odometer readings — captured where the signal is worst: border camps at Beitbridge, Kasarito, Tuli, the Chirundu and Kasika approaches, on shared devices, with drivers who are not office users. The brief also requires MFA for anyone with financial access, and requires that a future driver app or integration reuse the same business logic through a versioned API.

## Decision

1. **Office staff** are Fortify accounts (starter-kit auth) with **mandatory TOTP** and recovery codes, invited by the Owner, no public registration.
2. **Drivers are not accounts.** Each dispatch issues a **`trip_access_token`**: a random token stored **hashed**, bound to one trip and one driver, with scopes (`pod | odometer | fuel | expense`), `expires_at` (planned trip end + 24 h by default), a revoke flag, `last_used_at` and `last_ip`, and redemption rate-limited. Presented once as a URL plus a 6-digit PIN for poor-signal entry.
3. **Four kiosk screens only:** POD capture (camera `capture="environment"`, signature canvas, quantity received, exceptions), odometer + engine hours, fuel issue (litres, tank, station, price), trip expense (toll, border petty cash, lodging). Nothing else in the application is reachable through a token.
4. **Every kiosk write goes through the same Domain Action as the office UI.** The token is an authentication principal, not a parallel implementation — which is what keeps the Sanctum API and a future driver app a thin layer rather than a rewrite.
5. **Attribution survives the shared phone:** audit rows carry `actor_kind = kiosk` plus the token id, driver id and trip id; state transitions record the same in `operational_events`.
6. **Offline-tolerant by idempotency:** the POD record carries a client-generated `client_uuid` with a `UNIQUE` constraint, so a retry after a dropped signal cannot duplicate a delivery note; photos upload on retry, and the token cannot read any trip other than its own.
7. **Drivers cannot complete work.** A driver may record facts; only `delivered → completed → closed` by office roles changes the money. This keeps the kiosk security-relevant surface small.

## Alternatives rejected

| Option | Why rejected |
|---|---|
| **Driver user accounts + TOTP enrolment** | The strongest identity, and unusable in practice: enrolment friction at camps, shared-device MFA pain, recovery-code support load on one owner, and a security surface (21+ accounts with fuel and expense write access) out of proportion to the benefit. |
| **Dispatcher enters everything; no driver input** | Removes the POD signature and photo — the two things that actually win a tonnage or shortage dispute — and makes driver performance metrics unfalsifiable. |
| **A full mobile app now** | Explicitly out of scope; and building one before the Actions/API contract exists would freeze the wrong interface. |
| **Shared "office" login at the depot** | Destroys attribution, cannot be revoked per trip, and leaks every trip's data to whoever holds the credential. |
| **Long-lived device tokens** | A lost phone at a border camp should not be a standing credential. Expiry + revocation + trip binding is the whole control. |

## Consequences

- **Positive:** field capture works on any phone with no enrolment ceremony; blast radius is one trip and one trip's scopes; the same Actions are the future API surface, so the driver-app decision stays open; every reading keeps an actor, a time, an IP and a reason.
- **Costs:** revocation and expiry become real features that must be visible on the dispatch board (a dispatcher must be able to kill a token in one click); the kiosk needs its own layout, its own rate limiting, and its own tests for "cannot see another trip"; identity is *weaker* than a password+MFA account — attribution is "the person holding this token", which is accepted deliberately; token issuance is another thing that can go wrong on the day of a trip, so it must be one click from the dispatch board.
- **Hard-to-reverse parts:** if a driver must ever be a *first-class authenticated user* (e.g. a driver-owned trailer doing contract work), the kiosk-to-account migration is additive (a `drivers.user_id` link), so this choice does not block that path.
