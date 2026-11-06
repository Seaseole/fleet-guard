# Local dev database

Fleet Guard's dev database is **MySQL 9.1.0, on the port in `.env` (`DB_PORT`)**. The engine is what
matters, not the port: local DDL has to be exercised against the same engine Laravel Cloud runs, which
is why this project does not use this machine's MariaDB. Decided 2026-10-26 (`DESIGN.md` R1,
, ADR-0005); the *instance* moved on 2026-10-29, below.

| | Engine | Port | Serves |
|---|---|---|---|
| EnvKit's `mysql` service, version **11.8.2** | MariaDB 11.8.2 | 3306 | the seven other projects (erpgo, ultimatepos, highblossom, skuul, panpharma, spinthewheel, takuwa) |
| EnvKit's `mysql` service, version **9.1.0** — **active since 2026-10-29** | **MySQL 9.1.0** | 3306 | `fleet_guard_dev` |
| The hand-started instance, `AppData\Local\fleet-guard-mysql` | MySQL 9.1.0 | 3307 | its own copy of `fleet_guard_dev`, superseded |

## Switched to EnvKit's own MySQL 9.1 (2026-10-29)

`.env` reads `DB_PORT=3306` again, and the database, the `fleetguard` account and every table were
restored into **EnvKit's** MySQL 9.1.0 data directory
(`C:\ProgramData\envkit\services\mysql\9.1.0\data`), which is managed by EnvKit's Services page rather
than by a terminal that dies with its tab. Reason: R1's dev/prod divergence is a property of the
*engine*, and the engine is now the one EnvKit runs and restarts.

Two consequences a reader must not be allowed to discover the hard way:

1. **Activating a MySQL/MariaDB version changes the data directory.** EnvKit keeps one per version, so
   `activate mysql 9.1.0` does **not** expose the MariaDB 11.8.2 data — the seven other projects read
   an empty server until `activate mysql 11.8.2` puts them back. Nothing is destroyed; nothing is
   shared. Switching is a service restart, so it is done from EnvKit's own UI, not by hand.
2. **`scripts/*.php` must not start servers.** The verification harnesses connect to whatever instance
   the developer has running; a script that spawns its own `mysqld` or `artisan serve` creates the exact
   two-key confusion described under "The finding that broke a browser pass" below.

The `mysql` client is still EnvKit's:
`C:/ProgramData/envkit/services/mysql/9.1.0/bin/mysql.exe --protocol=tcp -h 127.0.0.1 -P 3306 -u fleetguard -pfleetguard-dev -D fleet_guard_dev`.

### The finding that broke a browser pass (2026-10-29)

Two `mysqld`/`php -S` generations were alive at once — one started before `APP_KEY` was rotated, one
after — and the app's session cookie is **not port-scoped**, so a browser tab moved between dev-server
ports kept carrying a session id written with the old key. Every request that touched the session 500'd
inside `Encrypter::decrypt()` with `unserialize(): Error at offset 0 of 16 bytes`, which looks precisely
like a Phase 1 defect and is not one. The tells: `sessions` rows that no CLI process can decrypt, and a
500 on `/two-factor-challenge` after a *successful* password login. `php artisan tinker` proving the key
round-trips in-process is the decisive test. Fix: one server, one key, `delete from sessions`.

## Starting and stopping it

EnvKit's Services page owns the process. The command below is what that page runs, kept here for the
day someone needs to know what it does — **do not run it from a script or an agent turn**:

```bash
"C:/ProgramData/envkit/services/mysql/9.1.0/bin/mysqld.exe" \
  --defaults-file="C:/Users/Eugene/AppData/Local/fleet-guard-mysql/my.ini" \
  --init-file="C:/Users/Eugene/AppData/Local/fleet-guard-mysql/bootstrap-users.sql"
```

Confirm it is up (this one is safe anywhere — `ping` only reads):

```bash
"C:/ProgramData/envkit/services/mysql/9.1.0/bin/mysqladmin.exe" \
  --protocol=tcp -h 127.0.0.1 -P 3306 -u fleetguard -pfleetguard-dev ping
```

`mysqladmin` has no `-D`/`--database` option — the flag was in this command and made the check
fail with `unknown option '-D'` on a server that was running fine. Select the schema with `mysql
-D fleet_guard_dev -e 'select 1'` if you need that; `ping` only proves the listener and the account.

When the app answers `SQLSTATE[HY000] [2002] ... target machine actively refused it`, the service is
not running — check EnvKit's Services page before suspecting credentials. A *refused connection* and an
*access denied* are different failures: the first says no listener, the second says the account or the
data directory is not what `.env` thinks it is.

## Where things live

All outside the repository, because a database is not source:

- `C:\Users\Eugene\AppData\Local\fleet-guard-mysql\my.ini` — configuration
- `C:\Users\Eugene\AppData\Local\fleet-guard-mysql\bootstrap-users.sql` — creates the app
  and diagnostics accounts at startup (`--init-file`)
- `C:\Users\Eugene\AppData\Local\fleet-guard-mysql\data\` — the data directory
- `C:\Users\Eugene\AppData\Local\fleet-guard-mysql\mysql-error.log` — server log

Accounts and their passwords are in `.env` (gitignored). `fleetguard` owns only
`fleet_guard_dev`; `fgadmin` is for inspecting triggers and constraints. Both are
loopback-only (`127.0.0.1`) on a machine-local server.

## Why the `--init-file` is needed

`mysqld --initialize-insecure` creates only `root@localhost`. Because `my.ini` sets
`skip-name-resolve`, a TCP connection from `127.0.0.1` does not match `localhost`, so
there is no way to get in to fix that — the server must be started once with an init file
that provisions a reachable account. The file is idempotent (`IF NOT EXISTS`), so leaving
it on every start is harmless and self-healing.

## Config choices that are load-bearing

- **`sql_mode` includes `ONLY_FULL_GROUP_BY`** and `collation-server=utf8mb4_0900_ai_ci`:
  MariaDB is more permissive than Cloud, so dev is set to prod's strictness rather than
  the other way round. A query that passes here will pass there.
- **`log_bin_trust_function_creators=1`** — see the next section. Do not remove it.
- **Binary logging stays ON** so that statements MySQL rejects under binlog (e.g.
  `CREATE TABLE ... SELECT` on a table without a key) fail here too.

### Measured on EnvKit's MySQL 9.1 on 2026-10-29 — two of the three are NOT satisfied

`select @@global.sql_mode, @@global.collation_server, @@global.log_bin_trust_function_creators, @@global.log_bin`

| Setting | Needs to be | Actually is | Effect |
|---|---|---|---|
| `sql_mode` | includes `ONLY_FULL_GROUP_BY` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | a group-by that fails on Cloud passes here. Phase 8's reports and the ledger tie-outs are exactly where that bites. |
| `collation_server` | `utf8mb4_0900_ai_ci` | `utf8mb4_unicode_ci` | **mitigated, not fixed**: `fleet_guard_dev` was created with `DEFAULT COLLATE utf8mb4_0900_ai_ci`, so tables inherit the right one; only statements that name no schema default drift. |
| `log_bin_trust_function_creators` | `1` | `0`, with `log_bin=1` | **ERROR 1419.** Proven, not predicted: `CREATE TRIGGER … ON trips` as `fleetguard` was refused on 2026-10-29. Any migration that adds a trigger fails under the app's own account. |
| `log_bin` | ON | ON | as intended. |

Fix all three in **EnvKit's Services page → MySQL options** (they land in
`C:\ProgramData\envkit\config.toml` under `[services.mysql.options]`), then restart the service from
that page — the same place the service is started and stopped. `log_bin_trust_function_creators` is a
dynamic global, so `SET PERSIST` also works, but it writes `mysqld-auto.cnf` behind EnvKit's back and
does not survive EnvKit regenerating its config; the service options file is the durable answer. Until
it is set, ledger-adjacent DDL must run under an account with `SUPER`, and that is a migration-time
decision to make knowingly, not by accident.

## The finding that justified the switch

`DESIGN.md` layer 3 makes the ledger immutable with `BEFORE UPDATE` / `BEFORE DELETE`
triggers that `SIGNAL SQLSTATE '45000'`. Measured here on 2026-10-26:

```
ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is enabled
```

A MySQL account **without `SUPER` cannot `CREATE TRIGGER`** while binary logging is on and
`log_bin_trust_function_creators` is `0` — and that is exactly the account shape Laravel
Cloud provisions (read-only / read-write, no `SUPER`). MariaDB 11.8 does not raise this, so
staying on 3306 would have surfaced it in production instead.

**The same error was measured again on 2026-10-29**, this time against the *replacement* instance —
EnvKit's MySQL 9.1, where the setting is `0`. The lesson did not expire with the old server: switching
instance silently reset the three settings below, which is why they are stated as measurements rather
than as properties of "the MySQL instance".

Consequences carried forward:

1. Locally the setting is `1` in `my.ini`, and it was re-verified afterwards that the
   unprivileged `fleetguard` account can create and own an immutability trigger.
2. **Cloud confirmation is an outstanding Phase −1 item** (ADR-0005): whether managed MySQL
   already ships `log_bin_trust_function_creators=1`, or whether the ledger migrations must
   run under a higher-privilege account than the runtime account.
3. If neither is available on Cloud, loses its database-enforced layer and the design
   falls back to app guards + `CHECK` constraints + `ledger:verify`, which is what R2 already
   assumes for grant-level immutability. That would be an ADR-0001 amendment, not a silent
   substitution.

## What was verified against this engine

| Mechanism the ledger depends on | Result |
|---|---|
| `CHECK` constraints actually enforced | ✅ `ERROR 3819 ... Check constraint 'chk_side' is violated` for a negative amount and for a line with both sides populated |
| `BEFORE UPDATE` / `BEFORE DELETE` triggers with `SIGNAL '45000'` | ✅ `ERROR 1644 (45000)` — update and delete both refused |
| `GENERATED ALWAYS AS ... STORED` column | ✅ materialised, readable, `Extra: STORED GENERATED`, indexable |
| `SELECT ... FOR UPDATE` row locking (number allocator, ADR-0002) | ✅ lock acquired inside the transaction |
| Trigger creation by the unprivileged app account | ✅ with `log_bin_trust_function_creators=1`, definer `fleetguard@127.0.0.1` |
| Kit migrations on MySQL | ✅ all five applied, `utf8mb4_0900_ai_ci` |
