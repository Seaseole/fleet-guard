# Keys, secrets and escrow

Phase 9's review starts here: every secret this deployment depends on, what it protects, what
happens when it is lost, and how it is replaced. A key that is not on this page is a key nobody can
rotate.

The rule behind every row: **no secret is committed, and no two controls share one.** A leaked
documents-bucket credential must not also open the backups; a stolen database password must not
decrypt the columns inside it.

## The inventory

| Secret | Environment | Protects | If it is lost | Rotation |
|---|---|---|---|---|
| `APP_KEY` | all | Session cookies, signed URLs, encrypted cookies/payloads | Every session is invalid; every outstanding signed download URL stops verifying | [key rotation](key-rotation.md#app_key) |
| `DB_CRYPT_KEY` (`v1`) | all | The columns: bank numbers, national IDs, licence numbers, fuel-card references, GPS API keys | Those columns cannot be read; new writes fail closed | [column-key rotation](key-rotation.md#db_crypt_key-the-column-key) |
| `DB_CRYPT_KEY_V2` | when rotating | The next column-key version | Rotation cannot start (`MissingFieldKey`, before any write) | generate on rotation |
| `FLEET_BACKUP_KEY` | all | The AES-256-GCM seal on every off-site export | Existing exports cannot be opened; a new export refuses to run | [backup-key rotation](key-rotation.md#fleet_backup_key) |
| `DB_PASSWORD` | all | The application's database user | The app is down | rotate in the database console, then in the environment |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | production | The private documents bucket (`FLEET_DOCUMENTS_BUCKET`) | Document upload and download fail | rotate the IAM user's key pair |
| `FLEET_BACKUP_S3_KEY` / `FLEET_BACKUP_S3_SECRET` | production | The off-site backup bucket | Exports and nightly verification fail | rotate in the backup provider's console |
| Mail credentials | production | Invitations, alerts, scheduled reports | Mail stops; the queue records failures | rotate at the SMTP provider |

`DB_CRYPT_KEY` and `FLEET_BACKUP_KEY` are deliberately different keys held by different controls.
Losing the database key must not also make the only readable copy unreadable (`DESIGN.md`,
).

## Escrow

- Each key is stored in the owner's password manager **and** in a sealed offline copy held by a
  second named person. The two copies are the control: one is convenience, two are recovery.
- Every key is recorded with its **name, version, creation date and fingerprint** — the backup
  archive carries a key fingerprint, so an operator can tell which key opens which copy.
- Escrow is never the database server, the application repository, or the same account as the
  object storage it protects.
- The secrets are set per environment. Staging never reuses production keys: a restore rehearsed on
  staging must not be able to decrypt a production column.
- When a person leaves, keys they could read are rotated even if nothing suggests exposure.

## What the deployments must verify

`php artisan security:audit --no-alert` answers the machine-checkable half:

- every `*_encrypted` column has a `key_version` and a masked sibling;
- no row names a version the key ring cannot read;
- no `fleet.crypto.keys.*` value equals `APP_KEY`;
- the private document disk is not served by the framework.

What it cannot answer — that the escrowed copies exist, and that a named human can find them — is
the checklist in [the runbook](runbook.md#quarterly).
