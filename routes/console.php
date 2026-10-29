<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Schedule;

/*
 * Phase 0 schedule (§14.1, §14.6). Every entry is a check that raises an alert when it fails, because a
 * scheduled sweep whose problems land only in a rotating log is a control nobody operates. The times are
 * spaced so a failure in an earlier one is on the register before the later ones report.
 */

// Sequence rows must exist before the first document of a period is issued, because allocation
// deliberately never creates them (DESIGN.md §7.2 amended, §20.4). Running daily covers the
// fiscal-year and calendar-year boundaries without needing a deploy.
Schedule::command('numbering:ensure')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// Gap-free is detected, not merely intended: this is the only thing that catches a human with a
// SQL client editing a counter. It runs after ensure so a fresh period is not reported as a gap.
Schedule::command('documents:number-integrity')
    ->dailyAt('00:40')
    ->withoutOverlapping();

// §6.3 layer 5. The CHECKs and triggers stop a bad *write*, but the header totals are trusted
// derived values, so only a sweep proves the ledger still describes itself. On Laravel Cloud the
// INSERT-only DB grant is unavailable (ADR-0001 consequences), which makes this scheduled sweep
// the operational half of the immutability story rather than a nicety.
Schedule::command('ledger:verify')
    ->dailyAt('01:00')
    ->withoutOverlapping();

// §14.4's `sha256` is written from the bytes at upload and means nothing until something re-reads them.
// Weekly rather than daily: the files are immutable, so the finding this looks for — an object replaced
// or truncated underneath its own row — is a storage-layer event, and the archive grows with the fleet
// rather than with the working day. Runs before the Monday-morning review of the register.
Schedule::command('documents:verify-integrity')
    ->weeklyOn(Carbon::SUNDAY, '01:30')
    ->withoutOverlapping();

// §14.1's thresholds are rows, so "the company has none" and "the Owner turned them off" are the same
// query result. This runs first each night so the second reading is always the true one, and it is
// additive, so it cannot undo a decision the Owner made.
Schedule::command('reminders:ensure-thresholds')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// §14.1's nightly scan. Two hours after the ledger sweep so a self-check finding is already on the
// register when the reminder path reports — the one order in which a morning review of the register
// answers "what do I have to deal with today" completely.
Schedule::command('reminders:run')
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::command('queues:check')->everyFiveMinutes()->withoutOverlapping();
