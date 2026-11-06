# User acceptance test

Phase 9's acceptance sentence: **"a staff member who did not build it completes a trip-to-invoice
without help."** This script is how that is tested, and how its result is recorded. It is written for
the person doing the work, not for a developer.

The tester must not have built the system, must not be given a walkthrough during the test, and must
record what happened — including where they got stuck. Getting stuck is the finding; a test that only
records successes proves nothing.

## Before the test

| | Requirement |
|---|---|
| Environment | Staging (or a restored copy of production), never the live ledger for a first run |
| Data | One customer, one route with a rate, two trucks, two drivers, one supplier, the chart of accounts, at least one open accounting period |
| Accounts | Owner (with TOTP), Dispatcher, Accountant, and one read-only role. The tester uses the role the task belongs to |
| Devices | A laptop for the office and a phone for the driver kiosk |
| Help | The tester may ask a builder **only** to confirm an environment problem (site down, wrong URL). Every "how do I…" question is recorded as a finding |

**Stop rule.** If a step fails because of a defect, record it and continue to the next independent
step. Do not let a builder fix the system mid-test; a moving target produces a result nobody can
reproduce.

## Part A — Trip to invoice (the golden path)

Expected result is what the tester should see without asking. Tick **Pass**, or write **Fail** and the
exact screen, time and message.

| # | As | Do this | Expected |
|---|---|---|---|
| A1 | Owner | Sign in, enrol the authenticator, save the recovery codes | Dashboard opens; codes are shown once |
| A2 | Owner | Invite the Dispatcher, Accountant and a read-only user | The invitation email arrives; the link is single-use |
| A3 | Dispatcher | Accept the invitation and set a password | No Owner-level screens appear |
| A4 | Dispatcher | Add or pick a customer's route with a rate | The rate is visible when quoting |
| A5 | Dispatcher | Create a job for that customer and quote it | The quote uses the route rate; a reason is required for a manual price |
| A6 | Dispatcher | Assign a truck and driver on the dispatch board | A conflicting booking, expired licence or expired permit is refused, and the refusal says which |
| A7 | Dispatcher | Depart the trip and enter the opening odometer | The reading is required; an implausible reading is flagged |
| A8 | Driver (phone) | Open the driver link, capture a POD photo, signature and quantity | The token works only for this trip; no office screens are reachable |
| A9 | Dispatcher | Record a fuel purchase and issue it to the trip | Litres and cost land on the trip; a consumption variance asks a question and changes nothing |
| A10 | Dispatcher | Arrive and complete the trip | Completion is refused while the POD is missing; succeeds once the evidence is complete |
| A11 | Accountant | Invoice the completed trip, then issue it | The number is allocated at issue and is sequential; the issued document cannot be edited |
| A12 | Accountant | Download the invoice PDF and email it to the customer | The PDF matches the issued figures |
| A13 | Accountant | Record a partial payment and allocate it | The invoice shows the remaining balance; ageing puts it in the right bucket |
| A14 | Accountant | Record the balance and allocate it | The invoice reads zero; nothing is left unallocated |
| A15 | Accountant | Try to void the paid invoice | The void is refused |
| A16 | Accountant | Issue a credit note against the invoice | The credit note is numbered and linked; the invoice balance reflects it |
| A17 | Accountant | Download the customer statement | The statement lists the invoice, payment, credit note and the closing balance |
| A18 | Dispatcher | Open the reports the Dispatcher is allowed to see | No margin or cost report appears; the screen does not error |
| A19 | Auditor | Open the same reports | The auditor reads but cannot edit or issue |
| A20 | Nobody | Sign out, then open a document download link copied earlier after it expires | The link is refused; the security trail records the attempt |

**Part A result:** _____ of 20 passed. The test passes only if **every** step passed without a
"how do I…" question to a builder.

## Part B — One real month closes

This part needs production-shaped data and the Accountant. It is the Phase 5 acceptance item and the
reason UAT and the first real close are the same exercise.

| # | As | Do this | Expected |
|---|---|---|---|
| B1 | Accountant | Reconcile the month: every bank/cash account matches its statement | The differences are zero, or each one has an explained item |
| B2 | Accountant | Check the trial balance balances and the P&L agrees with the accountant's figures | The two figures match; any difference is understood and written down |
| B3 | Accountant | Run the period's reports and export them for the accountant | The export opens; totals equal the screen |
| B4 | Owner | Lock the month | Nothing can post into it afterwards; a correction posts at today's date as a reversing entry |
| B5 | Owner | Re-run the nightly checks manually: `ledger:verify`, `reports:tie-to-ledger` | Both clean, and the alert register shows them resolved |
| B6 | Accountant | Sign the close | The signed trial balance is filed with the month |

**Part B result:** _____ of 6 passed.

> **Known gap at the time of writing (2026-11-01):** the ledger enforces period status and DB triggers
> refuse postings into a `locked` period, and the journal screen shows each period's status, but the
> build has **no on-screen "close/lock month" control**. B4 cannot be completed through the UI yet.
> This is recorded in `DESIGN.md` and must be closed before Part B can be signed off.

## Part C — Restore and recovery (operator, not the end user)

| # | Do this | Expected |
|---|---|---|
| C1 | `php artisan backup:verify-offsite` | Clean, with the newest copy's age under 35 days |
| C2 | `php artisan backup:verify-offsite --drill` | Clean; row counts and trial balance match the manifest; no `fleet_guard_drill_*` tables or schemas remain |
| C3 | Cloud console: restore a snapshot into a scratch cluster, point staging at it | Staging signs in and opens the dashboard |
| C4 | On the restored staging: `ledger:verify`, `reports:tie-to-ledger`, `security:audit --no-alert` | All clean |
| C5 | Compare the restored trial balance to the last signed close | The Accountant agrees the figures, and signs the comparison |
| C6 | Rotate the column key on staging: add v2, set the version, dry run, rotate, audit, rotate back | The dry run names v1; the rotation reports zero failures; `security:audit` is clean; the rollback to v1 also succeeds |

**Part C result:** _____ of 6 passed. C3 and C5 are the Cloud half of Phase 9's definition of done and
cannot be performed from a development machine.

## Recording findings

For each failed or stuck step, write:

```
Step:       A7
Role:       Dispatcher
Screen:     Operations → Trip
Time:       2026-11-__ __:__
Expected:   ...
Actual:     ...
Message:    ...            (copy the text, not a description)
Asked for help?  no / yes — what was asked
```

## Sign-off

| Item | Tester | Date | Result |
|---|---|---|---|
| Part A — trip to invoice, unaided | | | |
| Part B — one real month closed | | | |
| Part C — off-site drill (C1–C2) | | | |
| Part C — Cloud restore (C3–C6) | | | |
| Accountant agrees the close | | | |
| Owner accepts the build | | | |

The build is accepted when Parts A and B pass as written, Part C passes with the Cloud steps recorded
against a named operator and date, and every finding above is either fixed or explicitly accepted in
writing by the Owner.
