# Backup, verify and restore

Two copies exist, and they answer different questions.

| Copy | Where it lives | What it proves | Restore path |
|---|---|---|---|
| Cloud daily backups + manual snapshots | Laravel Cloud, 30-day retention | The whole database, at a point in time | Cloud console: restore into a new cluster |
| Monthly off-site export (`.fbk`) | A different provider/region, AES-256-GCM | The ledger-critical tables, readable off-site without the database host | `backup:verify-offsite --drill` proves the logic; a real restore still starts from a schema |

The Cloud snapshot is the primary restore. The off-site export exists because Cloud snapshots **cannot
be downloaded** and a copy in the same account is not off-site (ADR-0005, `DESIGN.md` ). Neither
is a backup until it has been restored.

## Scheduled behaviour

| Job | When | What it does on failure |
|---|---|---|
| `backup:export-offsite` | 1st of the month, 02:10 | Writes a red `backup_health` row for every tenant and raises a self-check alert |
| `backup:verify-offsite` | nightly, 02:20 | Decrypts the newest copy, checks every table against its manifest, alarms if the copy is older than 35 days |
| `backup:verify-offsite --drill` | quarterly, by the operator | Everything the nightly verify does, plus a scratch rebuild and a trial-balance comparison |

The nightly verify is deliberately not monthly: a scheduler that stopped exporting in March must be
loud in April. Age is a finding, not a reason to skip the read.

## Restore from a Cloud snapshot

1. **Choose the moment.** The pre-release snapshot for a bad deploy; a backup date for a data-loss
   incident. Write it down before opening the console.
2. **Restore into a new cluster.** Cloud restore does not overwrite the existing database; it creates
   a new one. Keep the old cluster untouched until the restore is accepted — it is the only copy of
   anything written after the snapshot.
3. **Test on staging first.** Point a staging environment at the new cluster and run the checks in
   [verification](#verification-after-any-restore). Do not cut production over to a database nobody
   has read.
4. **Cut over.** Update the production `DB_*` values, restart workers, and sign in. Keep the previous
   cluster for at least one backup cycle so a second restore is possible if the first was to the wrong
   moment.
5. **Reconcile.** Compare the trial balance to the last close and to the off-site export's recorded
   trial balance. Ask the Accountant to sign the difference — a restore is not done when the app
   boots, it is done when the numbers are agreed.

The quarterly drill is the same procedure against a scratch cluster, run by a named operator rather
than discovered during an incident.

## Restore from the off-site export

The export is not a `mysqldump` of the live database. It is a sealed zip of per-table CSV files with a
manifest (table list, row counts, per-table digest, the journal's debit/credit totals, the export
time, and a key fingerprint). The table list is the 17 ledger-critical tables in
`config/fleet.php` → `fleet.backup.tables`; it is intersected with that list on read, because "the
archive told me to create this table" is not an authorization.

### The drill

```bash
php artisan backup:verify-offsite                 # read the newest copy, check it against its manifest
php artisan backup:verify-offsite --drill         # rebuild it and compare the books
php artisan backup:verify-offsite --key=backups/2026/11/export-20261101-095453-dtakhb.fbk
php artisan backup:verify-offsite --limit=20      # stop after this many findings
```

The drill:

1. decrypts and unpacks the object from the configured off-site disk;
2. recreates each table from the DDL recorded in the manifest, **with foreign keys removed** — the
   copy holds only the ledger-critical tables, so a parent table may be absent and keeping the
   constraint would fail for a reason that says nothing about the data;
3. inserts every row and compares each table's count and digest to the manifest;
4. recomputes the journal's debit and credit totals from the restored rows and compares them to the
   totals recorded at export time;
5. drops everything it created.

On a managed database user that cannot `CREATE DATABASE`, the drill rebuilds into
`fleet_guard_drill_*` tables inside the application schema and drops them afterwards. It refuses to
drop any name it did not mint. **No drill tables or schemas may remain after a run** — a leftover is a
finding, not a tidy-up.

Findings are reported, not thrown: one drill lists every disagreement rather than stopping at the
first. Any finding means the copy is not trusted; investigate before retiring older copies.

### What the export can and cannot restore

- It **can** prove that the ledger tables written months ago still decrypt, still unpack, and still
  add up to the trial balance recorded with them. That is the quarterly obligation.
- It **cannot** be pasted over a production database as a complete restore: it holds the ledger and
  billing tables, not trucks, trips, users, employees, documents or GPS history. Those come from the
  Cloud snapshot.
- **Document bytes are not in the export.** `documents` and `document_versions` rows are copied, but
  the files themselves live on the documents disk/bucket. A restore that rebuilds those rows without
  the bucket leaves downloads broken; the bucket's own versioning/lifecycle policy is what protects
  the bytes. This is a known gap, recorded in `DESIGN.md`.

## Verification after any restore

```bash
php artisan migrate:status          # schema is at the expected migration
php artisan ledger:verify           # the journal still describes itself
php artisan reports:tie-to-ledger   # operational totals match the ledger
php artisan security:audit --no-alert
php artisan queues:check
php artisan backup:verify-offsite
```

Then open the trial balance and the P&L, compare them to the last signed close, and have the Accountant
record the comparison. If a user cannot sign in, check `APP_KEY` and the sessions table before assuming
the restore failed — a restored session table with a different key is the usual explanation.

## Retention and disposal

- Cloud backups: 30 days. Manual snapshots: keep the pre-release and pre-close snapshots for the
  financial year.
- Off-site exports: keep at least the current financial year plus the retention the business promises
  its accountant. Older copies stay sealed with the key that made them; deleting a key deletes every
  copy it opened.
- A copy is never disposed of while it is the only evidence of a period that has not been reviewed.
