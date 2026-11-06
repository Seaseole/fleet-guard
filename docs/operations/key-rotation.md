# Key rotation

Three keys rotate on different clocks, and confusing them is how a rotation becomes an outage. Read
the row for the key you are rotating before touching an environment variable.

| Key | What moves when it changes | Command that does the work |
|---|---|---|
| `APP_KEY` | Nothing in the data — only artefacts signed by it | none (Laravel) |
| `DB_CRYPT_KEY` | Every ciphertext in the columns | `php artisan keys:reencrypt` |
| `FLEET_BACKUP_KEY` | Nothing — it seals copies, it does not reread them | none (re-export) |

Cadence: rotate the column key and the backup key **at least annually**, and immediately on any
suspected exposure or staff departure. Rotate `APP_KEY` on the same schedule.

## `APP_KEY`

`APP_KEY` signs session cookies and document download URLs. It does **not** encrypt the ledger or the
 Columns — those have their own key, and that separation is the reason a compromised `APP_KEY`
does not open the bank numbers.

1. Generate a new key: `php artisan key:generate --show`.
2. Put the new value in `APP_KEY`, and the old value in `APP_PREVIOUS_KEYS` (comma-separated) so
   outstanding signed URLs and cookies still verify while they expire.
3. Deploy, then `php artisan config:cache` and restart the workers so no process keeps the old value.
4. Force re-authentication if the key is being rotated because of exposure: revoke the sessions on
   the security screen (or delete the `sessions` rows). Every user signs in again and completes TOTP.
5. Remove the old value from `APP_PREVIOUS_KEYS` after the longest-lived artifact has expired —
   `FLEET_DOCUMENT_URL_TTL` (default 15 minutes) plus one session lifetime.

## `DB_CRYPT_KEY` (the column key)

The shape that makes this safe: each row records the version that encrypted it, and the key ring can
hold two versions at once. Reads use the row's version; writes use the current version. A table may
legitimately hold a mixture during a rotation.

**Before you start:** both keys must be escrowed, and the oldest off-site export you intend to keep
must be readable. The export contains ledger tables, not the columns, so it is not affected by
this rotation — but the rule is the same: keep the old key until you are satisfied.

1. **Generate the new key** with `php artisan key:generate --show` — it prints `base64:…`. Never reuse
   `APP_KEY`, `FLEET_BACKUP_KEY`, or the staging key.
2. **Add it, do not replace it.** Set `DB_CRYPT_KEY_V2` to the new value and keep `DB_CRYPT_KEY`
   (v1) in place. Deploy the environment change; the app still reads v1 rows and still writes v1
   until the next step.
3. **Move the pointer.** Set `DB_CRYPT_KEY_VERSION=v2` and deploy. From this moment new and edited
   rows are written under v2 while every existing row stays readable under its recorded v1.
4. **Dry run.** `php artisan keys:reencrypt --dry-run`. The report names the old versions found and
   how many rows would move. It writes nothing. If it refuses with `MissingFieldKey`, the key ring is
   missing a version the data needs — fix the environment before going further.
5. **Rotate.** `php artisan keys:reencrypt`. It decrypts each row with its own version, re-encrypts
   with v2, reads the new payload back, and only then writes it. A row that will not decrypt is
   reported and left untouched; the command exits non-zero so a scheduled wrapper notices.
6. **Prove it.** `php artisan security:audit --no-alert` should show no row naming an unreadable
   version. `php artisan keys:reencrypt --dry-run` should report zero pending rows.
7. **Retire v1** only after the report has been clean for a full backup cycle and the escrow list has
   been updated. Remove `DB_CRYPT_KEY` from the environment (or leave it set and empty — an empty
   entry is dropped from the ring). Never reuse the v1 value as v2.

**Rollback.** Set `DB_CRYPT_KEY_VERSION=v1` and run `keys:reencrypt` again while v1 is still in the
ring. This is why step 7 waits: the old key is the rollback. Once v1 is removed, rotation back
requires the v1 key from escrow.

**One company at a time.** `--company=2` scopes the run. This is how a staged rollout is rehearsed,
and how a single tenant is fixed without touching the others. A table without `company_id` cannot be
scoped and is reported rather than silently skipped.

**What it does not touch.** The rotation writes the ciphertext and `key_version` only. It does not
change the masked display column, does not fire model events, and does not touch `updated_at` — a key
rotation is not a business edit.

## `FLEET_BACKUP_KEY`

The export cipher has **one** key, not a ring. A copy sealed with the old key can only be opened by
the old key; the manifest records a fingerprint so an operator can tell which copy needs which key.

1. Rotate immediately after a successful monthly export and its verification, so the freshest copy
   and the new key are in step.
2. Keep the old key with the old exports in escrow. The old copies are still valid evidence; deleting
   the key turns them into unreadable bytes.
3. Set the new `FLEET_BACKUP_KEY`, deploy, then run `php artisan backup:export-offsite` and
   `php artisan backup:verify-offsite`. Both must pass before the old key leaves the environment.
4. Record in the escrow log which fingerprints are sealed with which key.

If the backup key is lost outright, the existing archives are lost with it. A new key starts a new
chain — it does not recover the old copies.
