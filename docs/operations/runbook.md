# Operations runbook

The daily question this page answers: **what is red, and what do I do about it?** Everything the
scheduler runs is a control; a control that fails writes an alert to the dashboard band and the alerts
screen, not to a log nobody reads (`DESIGN.md` ).

## The register is the morning report

Open **Dashboard → alerts** as Owner. A self-check alert names the check that raised it, the company
it concerns, and the finding. A later clean run resolves it automatically; a red slot with no detail
means the run itself failed.

| Check | What it watches | First response |
|---|---|---|
| `queues:check` | Failed jobs, oldest ready job, unreachable connection | Open **Security → Queues**; read the failed job, retry or discard. A growing failed count is the first sign SMTP or PDF rendering broke |
| `ledger:verify` | Journal header totals, lines, periods, barriers | Do not post into the period. Read **Accounting → Journal** for the named entry, and use [restore](restore.md) if the ledger itself is wrong |
| `documents:number-integrity` | Gaps, duplicates, burned numbers | Read **Documents → Register**; a gap means something was issued outside the allocator — escalate, do not renumber |
| `documents:verify-integrity` | Stored bytes against the `sha256` and size on the version row | A missing or changed object: check the documents bucket's lifecycle/versioning, then re-upload from the source paper |
| `reports:tie-to-ledger` | Operational totals against the ledger | Read the finding, which names both figures. The ledger is the statement of record; find which screen disagrees |
| `security:audit` | Route doors, re-auth coverage, encrypted columns | A new route without a gate, a `⚑` permission without a screen, or a column whose `key_version` cannot be read. Fix before the next release |
| `performance:audit` | Page budgets, N+1 guard, index coverage | A new page without a budget is a finding; an unbudgeted register or a missing FK index is a release blocker, not a nicety |
| `backup:export-offsite` | The monthly copy actually left the building | Read [restore](restore.md); check `FLEET_BACKUP_*` and disk credentials. A failed export leaves the previous copy's identity in place on purpose |
| `backup:verify-offsite` | The newest copy decrypts, matches its manifest, and is younger than 35 days | If it is age, the scheduler stopped exporting. If it is the manifest or the key fingerprint, the copy or the key changed — do not delete anything |
| `numbering:ensure` | A sequence row exists for the fiscal and calendar year | No alert of its own: a missing sequence refuses the next allocation by name. Check `php artisan schedule:list` and **Documents → Register** |
| `reminders:ensure-thresholds` | Every default reminder threshold a company has not overridden | Additive only — it cannot undo a decision the Owner made. A failure leaves the nightly scan reading fewer thresholds; re-run it, then `reminders:run` |
| `reminders:run` | Documents, licences, permits, service due, receivables | A business nudge, not a system failure. The alerts screen links to the record |
| `reports:run-scheduled` | The reports each company nominated, 06:05 company-local | Check **Reporting → Scheduled**; an unsent mail leaves the nomination's `last_sent_at` stale and lands in `failed_jobs` like any other job |

## Cadences

| Cadence | What | Command / screen |
|---|---|---|
| Continuously | Queue health | scheduler: `queues:check` every five minutes; **Security → Queues** |
| Daily | Numbering, ledger, security, performance, tie-out, reminders | scheduled; review the register |
| Weekly | Document digests | scheduled Sunday 01:30 |
| Monthly | Off-site export | scheduled 1st 02:10; verify the health card the next morning |
| Monthly | Review the month just closed | **Accounting → Trial balance / P&L**, compare to the signed close |
| Quarterly | Restore drill | `php artisan backup:verify-offsite --drill` ([restore](restore.md)) |
| Quarterly | Cloud restore rehearsal | Cloud console into a scratch cluster, then the verification list in [restore](restore.md) |
| Annual / on exposure | Key rotation | [key rotation](key-rotation.md) |
| Per release | Snapshot, migrate, smoke | [deployment](deployment.md) |

## Incident procedures

### The export has not run for more than 35 days

`backup:verify-offsite` reports the age as a finding. Check, in order: is the scheduler running
(`php artisan schedule:list`); does the environment still hold `FLEET_BACKUP_KEY`; can the disk
credentials still write. Run `php artisan backup:export-offsite` by hand once the cause is fixed, then
`php artisan backup:verify-offsite`. Do not clear the alert without a fresh verified copy.

### The off-site copy will not decrypt or verify

The nightly verify reports a manifest mismatch, a table digest mismatch, or a key-fingerprint mismatch.
A fingerprint mismatch means the deployment is using a different backup key than the one that sealed
the copy — check the escrow log before touching the object. Do not delete the object: it may be the
last good copy. Investigate with `--key=` against a known older object, and follow
[restore](restore.md) if the copy is genuinely corrupt.

### A restore has to happen

Follow [restore](restore.md). Snapshot first if the database is still reachable, keep the old cluster,
and have the Accountant compare the trial balance to the last close before declaring success.

### A key is suspected compromised

Rotate immediately: `APP_KEY` (forces re-authentication), the column key
(`keys:reencrypt`), and the backup key (new chain, old copies escrowed with the old key). Record the
time, the reason and the fingerprints in the escrow log. If the database credential leaked, rotate the
user in the database console and redeploy.

### The ledger verify or the tie-out is red

Stop posting. The finding names the entry or the two figures. `ledger:verify` failing means the
journal no longer describes itself; `reports:tie-to-ledger` failing means a screen and the ledger
disagree. Neither is fixed by re-running the command. Correct with a reversing entry through the
normal posting door (never by editing a posted row), or restore if the data is not trustworthy.

## Quarterly checklist

- [ ] `php artisan backup:verify-offsite --drill` — clean, and no `fleet_guard_drill_*` tables or
      schemas left behind.
- [ ] Cloud snapshot restored into a scratch cluster and the verification list passed.
- [ ] Trial balance compared to the last signed close and signed by the Accountant.
- [ ] Escrowed keys still readable by the two custodians; fingerprints match the environment.
- [ ] `php artisan security:audit --no-alert` and `performance:audit --no-alert` clean.
- [ ] Two staff members can sign in, complete TOTP, and reach their own screen.

## Still to be signed off

Two Phase 9 acceptance items cannot be completed from a development machine and are recorded as
pending rather than claimed:

- **Cloud restore rehearsal** — needs a Cloud environment and its snapshot. The local drill proves the
  application-level half; the Cloud half is the item above.
- **User acceptance test with one real month closed** — needs one real month of data and a staff member
  who did not build the system. The script is in [UAT](../uat.md).
