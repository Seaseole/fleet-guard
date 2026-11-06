# Fleet Guard — user guide

This guide is for the people who run the business in Fleet Guard: the Owner, the office, the workshop
and the drivers. It follows the work, not the database. Where a screen refuses something, it says why.

## Signing in

1. Open the site and enter your email and password.
2. Enter the six-digit code from your authenticator app. The first sign-in enrols the app and shows
   recovery codes — **print them and keep them off the machine**. They are the only way back in if the
   phone is lost.
3. Some actions ask for your password again (marked ⚑ below). That is deliberate: seeing a bank number,
   closing a period, changing a role and managing numbering all re-check that it is still you.
4. **Settings → Sessions** lists every device signed in as you. Revoke anything you do not recognise.
   **Settings → Security** is where MFA and your password live.

Your role decides what you see. If a screen or a column is missing, it is not a bug — ask the Owner.

| Role | What it is for |
|---|---|
| Owner | Everything, including staff, roles, settings and period close |
| Operations Manager | Runs the fleet and the money around it; cannot change staff or settings |
| Dispatcher | Plans and dispatches trips and captures PODs; sees litres, never margins |
| Workshop Manager | Work orders, parts and odometer on their trucks |
| Stores Clerk | Parts stock and bulk-fuel issues |
| Accountant | Bills, bank reconciliation, journals, statements and customer money |
| Read-only Auditor | Reads everything their gates allow, changes nothing |

## The shape of the work

```
customer + route/rate ──▶ job ──▶ quote ──▶ trip ──▶ dispatch ──▶ crossings / POD ──▶ completed
                                                    │
                          fuel, maintenance, costs ──┘
                                                    ▼
                                            invoice (per trip or a billing run)
                                                    ▼
                                        payment + allocation ──▶ statement
```

Everything that posts money does so through the ledger; the reports read the same rows the screens do,
which is why the nightly tie-out is meaningful.

## The golden path: one trip to one invoice

This is the path the UAT script follows. Each step names what success looks like.

### 1. The customer and the rate

- **Invoicing → Customers**: add the customer, its currency and payment terms.
- **Operations → Routes**: add the corridor and the rate the customer pays. A route rate is what makes
  a quote a price rather than a guess.
- **Accounting → Suppliers** (when the trip will buy from someone): add the fuel station, the border
  agent or the repairer.

### 2. The job and the quote

- **Operations → Jobs**: create the job for the customer. Quote it from the route rate; adjust lines
  only with a reason.
- Send the quote, then record acceptance. A quote that was never accepted cannot become a trip.

### 3. Plan and dispatch

- **Operations → Dispatch**: assign the truck and driver. The board refuses a conflicting booking, an
  expired licence, an expired permit, or a truck that is already committed.
- Depart: the odometer reading is required here and again at arrival. A reading outside plausible
  bounds is flagged, not silently accepted.
- **Operations → Trip**: the trip screen is the working file — crossings, costs, documents, POD,
  exceptions and the running margin.

### 4. The driver's side (kiosk)

A driver has no account. From the trip screen, **driver link** produces a QR/second screen code; the
driver opens it on a phone and receives a short-lived, trip-scoped token.

- Capture the POD: photo, signature, quantity delivered and any exception.
- Capture fuel slips and cash advances against the trip.
- The token ends with the trip. A driver cannot see another trip, a customer's margin, or the office.

### 5. Costs and cross-border

- **Fuel**: purchases (card, station, cash advance), issues to a truck/trip, and consumption per 100 km
  against the truck's expectation. A variance is an alert that asks a question — it never changes the
  litres.
- **Maintenance**: service schedules, work orders, parts issues, tyres, inspections, breakdowns. A work
  order over the approval threshold cannot start without the Owner.
- **Cross border**: crossings, permits, transit documents, road-user fees, agent advances and the
  per-country cost summary for the trip.

### 6. Complete the trip

The dispatcher marks the trip arrived, then completed. Completion is what lets the trip be invoiced.
If the POD is missing or the paperwork is incomplete, completion is refused — fix the evidence, not
the status.

### 7. Invoice

- **Invoicing → Invoices**: invoice the trip, or collect completed trips into a billing run for a
  customer.
- Issue: the number is allocated at issue, gap-free. Once issued it cannot be edited or deleted; a
  mistake is corrected with a credit note.
- VAT is applied from the tax rate the line names. The PDF and the email use the same issued figures.
- A void is refused once a payment exists.

### 8. Money in

- Record the payment and **allocate** it to invoices. An invoice with allocations summing to its total
  reads zero; anything less stays in the ageing bucket it belongs to.
- Unapplied cash stays visible until it is allocated or refunded.
- **Invoicing → Ageing** shows what is overdue and by how long; reminders go out from there.
- **Customers → statement** (PDF) shows the customer their position.

### 9. The books

- **Accounting → Bills** records what the business owes; approve, then pay. Supplier payments allocate
  the same way customer payments do.
- **Accounting → Bank accounts**: masked account numbers; the full number is behind a re-auth gate and
  the `seeBankDetail` permission. A payment is only settled when it is allocated to the invoice or bill
  it pays.
- **Accounting → Journal** is the record of every posting, and shows each posting's accounting period
  and whether that period is open, closed or locked. Manual journals need a reason and re-auth.
- **Accounting → Trial balance / Profit & loss** are the statements of record.
- A **locked** period refuses new postings. A correction into it posts at today's date as a reversing
  entry rather than editing history.

## Reports

**Reports** is the library. Every metric has a written definition and a source query, and every report
ties to the ledger — that is what the nightly tie-out checks.

- Filters choose the period, the truck, the customer or the route.
- Large ranges are queued: the export appears in **Security → Queues** progress and downloads from the
  exports list when ready.
- Scheduled reports are the nominations the Owner set; they arrive by email on the company's own
  calendar, not the server's.
- PDF and Excel are the same numbers in two layouts.

## Alerts and the dashboard

The dashboard is the daily screen: KPI bands for the month, the fleet, fuel, money owed and the
backup's health. A red card is a control reporting, not a decoration. **Alerts** lists the findings and
what raised them; the operations runbook explains what to do with each one.

## Documents

- **Documents → Register** lists the paperwork: licences, permits, certificates, PODs, invoices.
- Every version is kept; a current document always has a current copy.
- Downloads are signed, short-lived links, and every download is recorded in the security trail.
- Expiry reminders fire before a licence or permit lapses — act on them before dispatch does it for
  you by refusing the trip.

## Settings (Owner)

- **Settings → Application** holds the company's own facts: name, timezone, base currency, fiscal year.
- **Settings → Appearance** is the theme.
- **Security → Users** invites staff (never the Owner seat), changes roles and revokes sessions.
- Invitations are single-use and expire; an accepted invitation cannot be replayed.

## What the system promises about your data

- Every business edit is attributable: who, when, from where.
- Posted ledger entries are never edited; corrections are reversing entries.
- Bank numbers, national IDs, licence numbers and card references are encrypted in the database and
  shown masked. The unmasked value is only available behind a re-auth gate.
- Documents leave the system only through signed links, and every download is audited.
- A monthly encrypted copy of the ledger leaves the building; a nightly check proves it still opens.

If something does not behave the way this guide says, write down the screen, the time and what you
expected. That is exactly what the UAT script in [uat.md](uat.md) asks for.
