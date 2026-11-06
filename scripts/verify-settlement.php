<?php

declare(strict_types=1);

use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\RecordTripSettlement;
use App\Domain\People\Actions\SettleTripSettlement;
use App\Domain\People\Actions\VoidTripSettlement;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5 slice 5g verification — driver settlements (the `TripSettled`, the
 * witness).
 *
 * The sheet's arithmetic is the control, so the harness attacks it: over-clearing an advance is
 * refused, a zero-everything sheet is refused, a second live sheet for the trip is refused, an
 * advance with nothing cleared is refused as unreconciled cash (the rule, enforced early),
 * and the settle posts exactly the two-pair entry the design names — Dr 1110 / Cr 1160 for the
 * cleared money, Dr 5200 / Cr 2140 for the allowance, each with the driver dimension the masks
 * require. A settled sheet voids through a mirror entry; the voided sheet frees the trip.
 *
 * The trip id is a fabricated ULID: People never resolves it backwards into Operations (the
 * one-way edge), so the sheet is about the *numbers*, and the close gate is exercised where
 * real trips are built — the operations harnesses (the honest gaps).
 *
 * Run: php scripts/verify-settlement.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness posts settlement journals. Dev only.\n";
    exit(2);
}

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
    $checks++;
    echo "  ok   {$what}\n";
}

function check(string $what, ?string $why = null): void
{
    $why === null ? ok($what) : fail($what, $why);
}

function fail(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

function refuses(string $what, callable $fn): void
{
    global $checks, $failures;
    $checks++;

    try {
        $fn();
        $failures++;
        echo "  FAIL {$what} — nothing refused it\n";
    } catch (Throwable $e) {
        ok("{$what} (refused: {$e->getMessage()})");
    }
}

/**
 * Delete a sheet's journal pair mirror-first: the reversal row FK-points at the original, so a
 * single bulk delete order-throttles on the constraint (char26 `reverses_entry_id` is non-null
 * exactly on mirrors).
 */
function deleteEntriesOf(string $sheetId): void
{
    $ids = JournalEntry::query()->where('source_id', $sheetId)->orderByDesc('reverses_entry_id')->pluck('id')->all();

    foreach ($ids as $id) {
        DB::table('gl_journal_lines')->where('entry_id', $id)->delete();
        DB::table('gl_journal_entries')->where('id', $id)->delete();
    }
}

$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', 'owner')
    ->select('users.id')
    ->first();

/** @var User|null $owner */
$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner row. Run: php artisan fleet:setup-owner.\n");
    exit(2);
}

CurrentCompany::force($owner->company_id);
auth()->setUser($owner);

foreach (app(NumberSequenceRegistry::class)->ensureAll() as $created) {
    echo "  sequence row created: {$created}\n";
}

$driverId = (int) (Driver::query()->value('id') ?? 0);

if ($driverId === 0) {
    fwrite(STDERR, "No driver row exists — the settlement needs the driver dimension.\n");
    exit(2);
}

$tripId = (string) Str::ulid();

LedgerBarrier::withLiftedGuard('verify: sweep earlier settlement-harness rows', $owner->id, function (): void {
    foreach (TripSettlement::query()->where('notes', 'like', 'verify: settlement harness%')->get() as $stale) {
        deleteEntriesOf($stale->id);
        DB::table('trip_settlements')->where('id', $stale->id)->delete();
    }
});

$record = app(RecordTripSettlement::class);
$settle = app(SettleTripSettlement::class);
$void = app(VoidTripSettlement::class);

echo "The guards on the sheet (the arithmetic a signature may not break)\n";
echo "--------------------------------------------------------------------\n";

refuses('a sheet of nothing is refused', fn () => $record->execute($tripId, $driverId, 0, 0, 0, 0, 'verify: settlement harness', $owner->id));
refuses('more cleared than the advance issued is refused', fn () => $record->execute($tripId, $driverId, 500_000, 400_000, 200_000, 0, 'verify: settlement harness', $owner->id));
refuses('an advance cleared by nothing is unreconciled cash, not a sheet', fn () => $record->execute($tripId, $driverId, 500_000, 0, 0, 0, 'verify: settlement harness', $owner->id));

$sheet = $record->execute($tripId, $driverId, 500_000, 400_000, 100_000, 200_000, 'verify: settlement harness', $owner->id);

check('the sheet opens with its outstanding exactly the remainder', $sheet->status === TripSettlementStatus::Open && $sheet->outstanding_minor === 0 ? null : "outstanding {$sheet->outstanding_minor}");
check('it carries a SET- reference from the allocator', str_starts_with($sheet->reference, 'SET-') ? null : $sheet->reference);
refuses('a second live sheet for the trip is refused', fn () => $record->execute($tripId, $driverId, 10, 10, 0, 0, 'verify: settlement harness dupe', $owner->id));

echo "\nSigning the sheet (the settlement is recorded)\n";
echo "------------------------------------------------------\n";

$settled = $settle->execute($sheet, $owner->id);

$entry = JournalEntry::query()->where('source_type', 'trip_settlement')->where('source_id', $settled->id)->first();

check('settled carries its entry, signer and instant', $settled->status === TripSettlementStatus::Settled && $settled->entry_id !== null && $settled->settled_by === $owner->id ? null : 'signature incomplete');
check(
    'the entry is 500 000 + 200 000 debit against the same credit',
    $entry !== null && (int) $entry->total_debit === 700_000 && (int) $entry->total_credit === 700_000 ? null : ($entry === null ? 'no entry' : "d {$entry->total_debit} c {$entry->total_credit}"),
);

$lines = DB::table('gl_journal_lines')->where('entry_id', $entry?->id)->get()->keyBy('account_id');
$accounts = DB::table('gl_accounts')->whereIn('code', ['1110', '1160', '5200', '2140'])->pluck('id', 'code');

foreach (['1110' => 'debit', '1160' => 'credit', '5200' => 'debit', '2140' => 'credit'] as $code => $side) {
    $line = $lines[(int) $accounts[$code]] ?? null;
    check(
        "the sheet posts {$side} on {$code}",
        $line !== null && $line->direction === $side ? null : 'line missing or wrong side',
    );
}

$driverLine = $lines[(int) $accounts['1160']] ?? null;
check('the cleared line names the driver (the 1160 mask demands it)', $driverLine !== null && (int) $driverLine->driver_id === $driverId ? null : 'driver dimension missing');

refuses('a settled sheet cannot be settled again', fn () => $settle->execute($settled->fresh(), $owner->id));

echo "\nVoiding the signed sheet (mirror)\n";
echo "----------------------------------------\n";

$voided = $void->execute($settled->fresh(), 'verify: correcting the signed figures', $owner->id);

check('the void stands and keeps its numbers', $voided->status === TripSettlementStatus::Void ? null : 'not voided');

$reversal = JournalEntry::query()->where('source_type', 'trip_settlement_reversal')->where('source_id', $voided->id)->first();
check(
    'a mirror entry reverses the settlement journal',
    $reversal !== null && (int) $reversal->total_debit === 700_000 && $reversal->reverses_entry_id === $voided->entry_id ? null : 'reversal missing',
);

$retry = $record->execute($tripId, $driverId, 500_000, 300_000, 150_000, 200_000, 'verify: settlement harness retry', $owner->id);
check('the void freed the trip for a corrected sheet', $retry->exists && $retry->outstanding_minor === 50_000 ? null : "outstanding {$retry->outstanding_minor}");
$void->execute($retry, 'verify: cleanup of the retry sheet', $owner->id);

echo "\nCleanup\n-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', $owner->id, function () use ($sheet): void {
    $ids = TripSettlement::query()->where('trip_id', $sheet->trip_id)->pluck('id')->all();

    foreach ($ids as $id) {
        deleteEntriesOf((string) $id);
    }

    DB::table('trip_settlements')->whereIn('id', $ids)->delete();
});

$left = TripSettlement::query()->where('trip_id', $sheet->trip_id)->count()
    + JournalEntry::query()->whereIn('source_type', ['trip_settlement', 'trip_settlement_reversal'])->count();

$left === 0 ? ok('no verify rows left behind') : fail('leftover rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — SETTLEMENT OK' : ' — SETTLEMENT FAILED')."\n";

exit($failures === 0 ? 0 : 1);
