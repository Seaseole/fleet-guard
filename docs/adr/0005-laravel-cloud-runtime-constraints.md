# ADR-0005 — Laravel Cloud runtime constraints

**Status:** Accepted (owner chose Laravel Cloud on 2026-10-26)
**Date:** 2026-10-26
**Decides:** which platform capabilities the design may and may not assume.

## Context

Development happens on a Windows/Laragon box (PHP 8.4.27, **MariaDB 11.8**, Redis 8.8, Node 26). Production is Laravel Cloud. Several parts of the brief assume self-hosted Linux freedoms — `spatie/laravel-backup` with an encrypted off-site copy, Horizon for queues, a Chrome binary for PDF rendering, database-level grants for ledger immutability, WebSockets for live GPS. Four of those assumptions change on Cloud.

## What was verified (Cloud documentation, 2026-10-26)

| Fact | Consequence |
|---|---|
| Cloud offers **Laravel MySQL** (managed, 5 GB–1 TB, auto-upsize) or Serverless Postgres | Production is **MySQL**, not MariaDB. Every DB-level construct in the design must exist in both dialects. |
| PHP 8.2–8.5 runtimes; extensions are managed by the platform | Pin **PHP 8.4**. Confirm `bcmath` availability at Phase −1; `brick/money` falls back to gmp, and without either it is slow. |
| **Managed queues** with dedicated workers, autoscaling to zero, and a built-in failed-job retry dashboard | `laravel/horizon` is unnecessary (and cannot run on the Windows dev box at all). |
| **Worker clusters** exist for long-running processes | Reverb *is* possible later — so deferring it is a product decision, not a platform limitation. |
| **Scheduled tasks continue while the environment sleeps** | The reminder engine and verify commands are safe on scale-to-zero. |
| Automated daily DB backups, retention **1–30 days**, manual snapshots; **restore creates a new cluster**; backups are internal snapshots and **are not downloadable as raw `mysqldump`** files | Rewrites the brief's backup strategy (below). |
| Binary logs retained ~8 days automatically; reported together with data storage | Point-in-time recovery window for a bad deploy; write-heavy queue/session tables inflate the storage figure. |
| DB users can be **read-only or read-write only**; the default injected user cannot be edited or deleted | The "INSERT-only grant" layer of ledger immutability is **not available**. |
| Object storage available; Cloudflare edge in front; preview environments per PR | Private document bucket on S3-compatible storage; staging-per-phase is cheap. |

## Decision

1. **PDF:** `spatie/laravel-pdf` v2 with the **DOMPDF driver as the only driver**. v2 supports several renderers (Browsershot, Gotenberg, Cloudflare, WeasyPrint, DOMPDF, Chrome); DOMPDF needs **no external binary, no Node, no Docker**, so the same template renders identically on the Windows dev box and on Cloud. Print templates are written to a plain CSS subset (no webfonts requiring network, no Tailwind arbitrary values) so a future Chrome-based driver is an upgrade, not a rewrite.
2. **Queues:** Cloud managed queues. No Horizon. A **first-party queue-health screen** (failed jobs, oldest pending, retry) ships in Phase 0, because silent PDF/email/export failure is this app's main failure mode.
3. **Backups:** Cloud daily DB backups at **30-day retention** as the primary; a manual snapshot before every release and before every period close; **quarterly restore drill into a new cluster**; plus a **monthly application-level encrypted export** of ledger-critical tables (gzipped CSV, Sodium/AES-256-GCM) to a **different provider/region** using app credentials only — because that is the only way to get a genuine off-site copy without DB-level dump access. `spatie/laravel-backup` is dropped for the database (its released tag also declares Laravel ^12 only) and remains a candidate for the **documents disk only**. A `backup_health` row makes silence an alert.
4. **Ledger immutability:** app guards + DB `CHECK` + `SIGNAL` triggers + period-lock trigger + `ledger:verify`, and an *operational* (not technical) control against someone holding database credentials — stated as risk R2 rather than papered over.
5. **Live positions:** `wire:poll` 60–120 s in v1. `ShouldBroadcast` events are emitted from Phase 0 with `BROADCAST_CONNECTION=null`, so enabling Reverb later is configuration plus one Echo block on a Cloud worker cluster.
6. **Dev/prod engine parity (preferred):** switch local development to **MySQL 8** so migrations are exercised against the production engine. If MariaDB stays, then every migration touching CHECKs, triggers or generated columns runs against staging before merge, and MariaDB-only syntax is banned by the architecture lint (native `SEQUENCE` is already rejected in ADR-0002).
7. **Sessions/cache on Valkey**, object storage for the private documents bucket, and no reliance on a local filesystem — Cloud storage is ephemeral across replicas, which rules out `file` sessions and `public` disk assumptions.
8. **Repo path:** create the project at `C:\laragon\www\fleet-guard` (no space) with `APP_NAME="Fleet Guard"`, and `git init` in Phase −1.

## Rejected

- **Office Windows server as production:** no Supervisor, no Horizon, no Reverb, `mysqldump` paths, IIS/Apache quirks, and single-box failure with the trucks' paperwork on it.
- **Linux VPS + Forge:** fully viable and cheaper; rejected only because the owner chose Cloud. Nothing in the design assumes a Cloud-only feature that Forge could not provide, so this remains a reversible deployment decision.
- **Assuming a Chrome/Puppeteer binary on Cloud:** it is not there, and a PDF path that only works on one machine is a phase-4 surprise.
- **Claiming DB grants as an immutability layer:** unavailable, and pretending otherwise is worse than naming the gap.
