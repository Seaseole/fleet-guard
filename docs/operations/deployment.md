# Deployment

Production is **Laravel Cloud**: managed MySQL, managed queues, a scheduler that keeps running while
the environment sleeps, and ephemeral local disk. The consequences of that last point are why the
documents disk and the backups are environment selections rather than assumptions about a filesystem
(ADR-0005).

`.env.example` is the complete variable list; this page explains the ones a deploy gets wrong.

## Environment matrix

| Group | Variables | Production value |
|---|---|---|
| Application | `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` | `APP_URL` is the HTTPS host; `APP_KEY` is unique per environment (`php artisan key:generate --show`) |
| Database | `DB_CONNECTION=mysql`, `DB_*` | Cloud injects these; the engine is MySQL, not MariaDB |
| Session / cache | `SESSION_DRIVER`, `CACHE_STORE` | Valkey/Redis (or database). The local filesystem is ephemeral across replicas |
| Queue | `QUEUE_CONNECTION`, `QUEUE_*` | Cloud managed queues; no Horizon |
| Documents | `FLEET_DOCUMENTS_BUCKET`, `AWS_*` | The private bucket. Empty `FLEET_DOCUMENTS_BUCKET` means the local disk, which is wrong for Cloud |
| Backup | `FLEET_BACKUP_KEY`, `FLEET_BACKUP_DISK=offsite_s3`, `FLEET_BACKUP_PREFIX`, `FLEET_BACKUP_STALE_DAYS`, `FLEET_BACKUP_S3_*` | Different provider, region and credentials from the documents bucket |
| Column encryption | `DB_CRYPT_KEY`, `DB_CRYPT_KEY_VERSION`, `DB_CRYPT_KEY_V2` | Separate from `APP_KEY`; keys escrowed (see [hardening](hardening.md)) |
| Owner bootstrap | `FLEET_OWNER_EMAIL`, `FLEET_OWNER_NAME` | The humans who will sign in as Owner; the password is prompted, never stored in config |
| Mail | `MAIL_*` | Verified sender domain, SPF and DKIM; invitations and alerts depend on it |

`APP_KEY`, `DB_CRYPT_KEY`, `FLEET_BACKUP_KEY` and the two object-storage credential pairs must all be
different values. `php artisan security:audit` fails if a column key equals `APP_KEY`.

## Switching the off-site export to the Cloud bucket

Development writes the export to `storage/app/offsite`. Production must not: the copy has to survive
the account that holds the database.

1. Create a bucket at a **different provider or region** from the documents bucket, with a dedicated
   credential pair that can write and read objects under the backup prefix and nothing else.
2. Add the S3 driver: `composer require league/flysystem-aws-s3-v3:"^3.0" aws/aws-sdk-php`. The
   `s3` and `offsite_s3` disks are useless without it — this is the one dependency a file-only
   deployment never installs.
3. Set `FLEET_BACKUP_S3_KEY`, `FLEET_BACKUP_S3_SECRET`, `FLEET_BACKUP_S3_REGION`,
   `FLEET_BACKUP_S3_BUCKET`, and an endpoint only if the provider needs one. Leave
   `FLEET_BACKUP_S3_PATH_STYLE=true` for providers that address buckets by path.
4. Set `FLEET_BACKUP_DISK=offsite_s3`. No code change, no new deployment: the disk name is
   configuration.
5. Run `php artisan backup:export-offsite`, then `php artisan backup:verify-offsite`. The first
   proves the credentials can write; the second reads the object back through the same credentials.
6. Confirm the bucket is **private** and that lifecycle rules do not delete the object before the
   retention period the business promised. A lifecycle rule is as much a backup setting as the key.

The same rule applies to documents: set `FLEET_DOCUMENTS_BUCKET` and the `AWS_*` pair, keep the bucket
private, and leave the signed-download route as the only exit. `security:audit` checks that the disk
is not served.

## Release procedure

1. **Snapshot first.** Take a manual Cloud snapshot before every release (ADR-0005). A deploy that
   needs a rollback is not the moment to discover the last snapshot was three days old.
2. Deploy the commit. Cloud builds the assets and runs the deploy hooks.
3. Run migrations: `php artisan migrate --force`. Migrations are written to be safe against a live
   application — additive, with the slower backfill in a command where one is needed.
4. `php artisan config:cache` and `php artisan route:cache` if the deploy hook does not already do it,
   then restart the queue workers so no worker keeps the previous code or configuration.
5. Smoke test, in this order:
   - sign in as Owner (TOTP) and open `/dashboard`;
   - `php artisan security:audit --no-alert`;
   - `php artisan performance:audit --no-alert`;
   - `php artisan queues:check`;
   - `php artisan backup:verify-offsite` if this deployment changed storage or keys.
6. Watch the alert register, not the logs: every scheduled sweep writes its findings to the dashboard
   band and the alerts screen, and a green log with a red register is still a red release.

## First deployment

```bash
php artisan migrate --force
php artisan db:seed --force          # company, currencies, roles, chart of accounts, tax rate
php artisan fleet:setup-owner        # prompts for the Owner password; seeds the role set
```

Then sign in as the Owner, enrol TOTP, save the recovery codes off-box, and invite the first staff
users. `fleet:setup-owner` refuses to invent a placeholder address — set `FLEET_OWNER_EMAIL` and
`FLEET_OWNER_NAME` or pass `--email`/`--name`.

## Rollback

Code rolls back by redeploying the previous release. Data does not: migrations are forward-only, and
the ledger's triggers and `CHECK`s are part of the schema. If a release wrote bad data, restore into a
new cluster from the pre-release snapshot and follow [restore](restore.md) — do not attempt to reverse
a migration against a live ledger.

The scheduler is part of the release. If a deploy disables it, backups, reminders, tie-outs and the
security sweep stop silently; `security:audit`, `performance:audit`, `backup:verify-offsite` and
`reports:tie-to-ledger` all raise their own alerts on their next run, which is the safety net — but the
queue-health screen is the first place to look when the register goes quiet.
