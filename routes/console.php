<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Schedule;

/*
 * Phase 0 schedule. Every entry is a check that raises an alert when it fails, because a
 * scheduled sweep whose problems land only in a rotating log is a control nobody operates. The times are
 * spaced so a failure in an earlier one is on the register before the later ones report.
 */

// Sequence rows must exist before the first document of a period is issued, because allocation
// deliberately never creates them (DESIGN.md amended). Running daily covers the
// fiscal-year and calendar-year boundaries without needing a deploy.
Schedule::command('numbering:ensure')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// Gap-free is detected, not merely intended: this is the only thing that catches a human with a
// SQL client editing a counter. It runs after ensure so a fresh period is not reported as a gap.
Schedule::command('documents:number-integrity')
    ->dailyAt('00:40')
    ->withoutOverlapping();

// Layer 5. The CHECKs and triggers stop a bad *write*, but the header totals are trusted
// derived values, so only a sweep proves the ledger still describes itself. On Laravel Cloud the
// INSERT-only DB grant is unavailable (ADR-0001 consequences), which makes this scheduled sweep
// the operational half of the immutability story rather than a nicety.
Schedule::command('ledger:verify')
    ->dailyAt('01:00')
    ->withoutOverlapping();

// /'S standing reviews, landed with Phase 9. A route added without its door, a ⚑ permission
// with no screen asking for the password, an encrypted column missing from a model's casts, or an FK
// added without an index is a finding here the morning after it lands — rather than in a review
// nobody scheduled. Each raises its own self-check alert when it fails.
Schedule::command('security:audit')
    ->dailyAt('01:10')
    ->withoutOverlapping();

Schedule::command('performance:audit')
    ->dailyAt('01:20')
    ->withoutOverlapping();

// The `sha256` is written from the bytes at upload and means nothing until something re-reads them.
// Weekly rather than daily: the files are immutable, so the finding this looks for — an object replaced
// or truncated underneath its own row — is a storage-layer event, and the archive grows with the fleet
// rather than with the working day. Runs before the Monday-morning review of the register.
Schedule::command('documents:verify-integrity')
    ->weeklyOn(Carbon::SUNDAY, '01:30')
    ->withoutOverlapping();

// The thresholds are rows, so "the company has none" and "the Owner turned them off" are the same
// query result. This runs first each night so the second reading is always the true one, and it is
// additive, so it cannot undo a decision the Owner made.
Schedule::command('reminders:ensure-thresholds')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// The nightly scan. Two hours after the ledger sweep so a self-check finding is already on the
// register when the reminder path reports — the one order in which a morning review of the register
// answers "what do I have to deal with today" completely.
Schedule::command('reminders:run')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// The scheduled reports: the nominations each company set. 06:05 server time, before the
// owner's first coffee; the company's own timezone decides which day a weekly nomination lands
// on, because `isDueOn` asks the company's clock, not the scheduler's (the rule that the
// business day is the company's).
Schedule::command('reports:run-scheduled')
    ->dailyAt('06:05')
    ->withoutOverlapping();

// The trust mechanism, landed with Phase 8: the operational screens and the statutory ledger
// are reconciled nightly after the ledger sweep has already vouched for the ledger itself. A
// difference is reported as a self-check finding naming both figures — the owner should learn
// about a drift here, not from a disagreement over dinner.
Schedule::command('reports:tie-to-ledger')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// The application-level copy. Monthly on the first, before anyone reads the register. The
// 35-day alarm means one failed run is caught by the nightly verify rather than by a quarter that
// passed without a copy. The quarterly drill (`backup:verify-offsite --drill`) is a runbook step,
// not a schedule entry: it creates and drops a scratch schema, which is a change to the database
// server that belongs on the operator's calendar with the backup it is proving.
Schedule::command('backup:export-offsite')
    ->monthlyOn(1, '02:10')
    ->withoutOverlapping();

// Nightly proof that the newest copy still decrypts, still matches its manifest, and is younger
// than the grace period. Deliberately not monthly: a scheduler that stopped exporting in March
// must be loud in April, and reading a copy costs nothing but the disk read.
Schedule::command('backup:verify-offsite')
    ->dailyAt('02:20')
    ->withoutOverlapping();

// Schedule::command('queues:check')->everyFiveMinutes()->withoutOverlapping();
