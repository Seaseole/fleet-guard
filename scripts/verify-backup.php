<?php

declare(strict_types=1);

use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Backup\BackupCipher;
use App\Domain\Security\Backup\BackupCorrupt;
use App\Domain\Security\Backup\BackupRestorer;
use App\Domain\Security\Backup\MissingBackupCopy;
use App\Domain\Security\Backup\Models\BackupHealth;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Verification — the off-site copy is written, read back, rebuilt and refused when it lies.
 *
 * Every run of this harness talks to a throwaway local disk whose root is deleted on the way out, so it
 * never overwrites the development copy a later restore rehearsal might be reading. It exercises the
 * two commands the scheduler runs and the two failure modes exists for: an altered frame and a
 * copy that has gone stale. The drill's scratch objects are checked for leftovers, because a drill that
 * leaves its rebuild behind is the same exposure it was supposed to rehearse.
 *
 * Run: php scripts/verify-backup.php
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  ok   {$what}\n";
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

function check(bool $condition, string $what, string $why = ''): void
{
    $condition ? ok($what) : failed($what, $why);
}

function healthRow(int $companyId, string $job): ?object
{
    return DB::table('backup_health')->where('company_id', $companyId)->where('job', $job)->first();
}

CurrentCompany::force(1);

$firstCompany = (int) Company::query()->orderBy('id')->value('id');

/*
 * A disk of this run's own. The prefix stays `backups` so the sweep and the export agree on the layout,
 * while the root underneath is somewhere no other run reads.
 */
$diskName = 'verify-backup';
$root = storage_path('app/verify-backup-'.Str::lower(Str::random(8)));

File::ensureDirectoryExists($root);

config([
    'filesystems.disks.'.$diskName => [
        'driver' => 'local',
        'root' => $root,
        'serve' => false,
        'throw' => true,
        'report' => false,
    ],
    'fleet.backup.disk' => $diskName,
    'fleet.backup.prefix' => 'backups',
    'fleet.backup.stale_after_days' => 35,
]);

Storage::forgetDisk($diskName);

$disk = Storage::disk($diskName);
$restorer = app(BackupRestorer::class);

// What this run found: the health register and any alert rows that existed before it. The export and
// the failing sweeps overwrite both, so the exit path puts them back rather than leaving the dev
// dashboard describing a test disk.
$healthBefore = DB::table('backup_health')->get()->map(static fn (object $row): array => (array) $row)->all();
$alertsBefore = DB::table('alerts')->get()->map(static fn (object $row): array => (array) $row)->all();
$alertIdsBefore = array_map(static fn (array $row): mixed => $row['id'], $alertsBefore);
$eventIdsBefore = DB::table('operational_events')->pluck('id')->all();
$notificationIdsBefore = DB::table('notifications')->pluck('id')->all();
$jobIdsBefore = DB::table('jobs')->pluck('id')->all();
$startedAt = now()->toDateTimeString();

$cleanUp = static function () use (
    $root,
    $diskName,
    $healthBefore,
    $alertsBefore,
    $alertIdsBefore,
    $eventIdsBefore,
    $notificationIdsBefore,
    $jobIdsBefore,
    $startedAt,
): void {
    CurrentCompany::force(1);

    File::deleteDirectory($root);
    Storage::forgetDisk($diskName);

    DB::table('backup_health')->delete();

    foreach ($healthBefore as $row) {
        DB::table('backup_health')->insert($row);
    }

    // Alerts this run raised are removed; alerts that existed are put back to the state they were found
    // in, because a clean sweep resolves a live failure and that resolution is not this harness's to keep.
    if ($alertIdsBefore === []) {
        DB::table('alerts')->delete();
    } else {
        DB::table('alerts')->whereNotIn('id', $alertIdsBefore)->delete();
    }

    foreach ($alertsBefore as $row) {
        DB::table('alerts')->where('id', $row['id'])->update($row);
    }

    $eventIdsBefore === []
        ? DB::table('operational_events')->delete()
        : DB::table('operational_events')->whereNotIn('id', $eventIdsBefore)->delete();

    $notificationIdsBefore === []
        ? DB::table('notifications')->delete()
        : DB::table('notifications')->whereNotIn('id', $notificationIdsBefore)->delete();

    $jobIdsBefore === []
        ? DB::table('jobs')->delete()
        : DB::table('jobs')->whereNotIn('id', $jobIdsBefore)->delete();

    DB::table('failed_jobs')->where('queue', 'alerts')->where('failed_at', '>=', $startedAt)->delete();
};

try {
    echo "\n== A: an empty off-site disk is a failure, not an empty success ==\n";

    $missing = null;

    try {
        $restorer->latestObjectKey();
    } catch (MissingBackupCopy $exception) {
        $missing = $exception;
    }

    check($missing !== null, 'a disk that holds nothing refuses to name a newest copy');

    $exit = Artisan::call('backup:verify-offsite');
    $output = Artisan::output();

    check($exit === 1, '...and the nightly sweep fails rather than passing silently', $output);
    check(str_contains($output, 'holds no'), '...saying the disk is empty, not that the copy is corrupt', $output);
    check((int) (healthRow($firstCompany, BackupHealth::JOB_VERIFY)?->ok ?? 1) === 0, '...and the health row says the last verify failed');

    echo "\n== B: the monthly export writes a sealed copy and a health row every tenant sees ==\n";

    $exit = Artisan::call('backup:export-offsite');
    $output = Artisan::output();

    check($exit === 0, 'backup:export-offsite exits 0', $output);
    check(str_contains($output, 'Off-site export written:'), '...naming the object it wrote', $output);

    $key = $restorer->latestObjectKey();

    check($disk->exists($key), 'the object is on the configured disk', $key);
    check(str_starts_with($key, 'backups/'), '...under the configured prefix, so the sweep finds it without listing the disk root', $key);
    check(str_ends_with($key, '.fbk'), '...with the sealed extension', $key);

    $bytesOnDisk = (int) $disk->size($key);
    $exportRow = healthRow($firstCompany, BackupHealth::JOB_EXPORT);
    $tableCount = count((array) config('fleet.backup.tables'));

    check($bytesOnDisk > 0, '...and it has bytes in it', (string) $bytesOnDisk);
    check($exportRow !== null, 'the export records a health row');
    check((int) ($exportRow->ok ?? 0) === 1, '...that says it succeeded', (string) ($exportRow?->detail ?? ''));
    check(($exportRow?->object_key ?? null) === $key, '...naming the object it wrote');
    check((int) ($exportRow?->bytes ?? 0) === $bytesOnDisk, '...with the size actually stored');
    check((int) ($exportRow?->tables ?? 0) === $tableCount, '...and every table the contract lists', sprintf('%s tables recorded, %d configured', $exportRow?->tables ?? '-', $tableCount));
    check((int) ($exportRow?->rows ?? 0) > 0, '...with its rows', (string) ($exportRow?->rows ?? '-'));

    $companies = array_map('intval', Company::query()->orderBy('id')->pluck('id')->all());
    $covered = array_map('intval', DB::table('backup_health')->where('job', BackupHealth::JOB_EXPORT)->where('ok', 1)->pluck('company_id')->all());
    $blind = array_values(array_diff($companies, $covered));

    check($blind === [], 'a success row was written for every tenant, so no dashboard is blind', json_encode($blind));

    echo "\n== C: the copy reads back, table by table, against its own manifest ==\n";

    $exit = Artisan::call('backup:verify-offsite');
    $output = Artisan::output();

    check($exit === 0, 'backup:verify-offsite exits 0 on a clean copy', $output);
    check(str_contains($output, 'reads back clean'), '...and says the copy reads back clean', $output);
    check(str_contains($output, 'earlier alert resolved'), '...and closes the failure the empty disk raised', $output);

    $report = $restorer->inspect($key);

    check($report['object_key'] === $key, 'the inspect names the object it read');
    check($report['findings'] === [], 'every table digest, size and row count matches the manifest', json_encode($report['findings']));
    check($report['tables'] === $tableCount, '...across every configured table', sprintf('%d read, %d configured', $report['tables'], $tableCount));
    check($report['rows'] === (int) ($exportRow?->rows ?? -1), '...and the row total the export recorded', sprintf('%d read, %s recorded', $report['rows'], $exportRow?->rows ?? '-'));
    check(array_map('intval', $report['company_ids']) === $companies, '...naming every tenant whose data left the building', json_encode($report['company_ids']));

    $sealedCopy = tempnam(sys_get_temp_dir(), 'fgbk').'.fbk';
    $plainCopy = $sealedCopy.'.zip';
    file_put_contents($sealedCopy, $disk->get($key));

    $decrypted = app(BackupCipher::class)->decryptFile($sealedCopy, $plainCopy);

    $zip = new ZipArchive;
    $opened = $zip->open($plainCopy) === true;
    $manifest = $opened ? json_decode((string) $zip->getFromName('manifest.json'), true) : null;
    $zip->close();

    check($opened && is_array($manifest), 'the decrypted archive carries a manifest');
    check(($manifest['key_fingerprint'] ?? null) === app(BackupCipher::class)->fingerprint(), '...naming the key that sealed it, not just that some key did');
    check(($manifest['app'] ?? null) === 'fleet-guard', '...and the application whose tables these are');
    check($decrypted['sha256'] === $report['sha256'], '...and the footer digest is the digest the report quoted');

    $wrong = new BackupCipher('base64:'.base64_encode(random_bytes(32)));
    $wrongRefused = null;

    try {
        $wrong->decryptFile($sealedCopy, $plainCopy.'.wrong');
    } catch (BackupCorrupt $exception) {
        $wrongRefused = $exception;
    }

    check($wrongRefused !== null, 'a different key cannot open the copy');

    @unlink($sealedCopy);
    @unlink($plainCopy);
    @unlink($plainCopy.'.wrong');

    $verifyRow = healthRow($firstCompany, BackupHealth::JOB_VERIFY);
    check((int) ($verifyRow?->ok ?? 0) === 1, 'the verify health row says the copy is good');
    check(($verifyRow?->object_key ?? null) === $key, '...naming the object that proved good');
    check((int) ($verifyRow?->tables ?? 0) === $tableCount, '...and the tables it counted', (string) ($verifyRow?->tables ?? '-'));

    echo "\n== D: the quarterly drill rebuilds the copy and drops the scratch ==\n";

    $drill = $restorer->drill();

    check($drill['findings'] === [], 'the drill restores the copy with no finding', json_encode($drill['findings']));
    check($drill['restored']['tables'] === $report['tables'], '...rebuilding every table', sprintf('%d rebuilt, %d exported', $drill['restored']['tables'], $report['tables']));
    check($drill['restored']['rows'] === $report['rows'], '...and every row', sprintf('%d rebuilt, %d exported', $drill['restored']['rows'], $report['rows']));
    check(in_array($drill['mode'], ['schema', 'prefix'], true), '...into a scratch namespace, schema or table prefix', (string) $drill['mode']);
    check(str_starts_with((string) $drill['schema'], (string) config('fleet.backup.drill.schema_prefix')), '...whose name carries the prefix that makes dropping it safe', (string) $drill['schema']);

    $leftoverTables = DB::select("select table_name from information_schema.tables where table_schema = database() and table_name like 'fleet_guard_drill_%'");
    $leftoverSchemas = DB::select("select schema_name from information_schema.schemata where schema_name like 'fleet_guard_drill_%'");

    check($leftoverTables === [], '...and dropped every scratch table it created', json_encode($leftoverTables));
    check($leftoverSchemas === [], '...and no scratch schema is left behind', json_encode($leftoverSchemas));

    $exit = Artisan::call('backup:verify-offsite', ['--drill' => true]);
    $output = Artisan::output();

    check($exit === 0, 'the quarterly drill exits 0 when the rebuilt book matches', $output);
    check(str_contains($output, 'rebuilt into') && str_contains($output, 'then dropped'), '...saying what it rebuilt and that it dropped it', $output);

    echo "\n== E: a copy that was altered or cut short is refused ==\n";

    $good = $disk->get($key);
    $at = intdiv(strlen($good), 2);
    $altered = $good;
    $altered[$at] = chr(ord($altered[$at]) ^ 0xFF);
    $alteredKey = 'backups/tampered-'.Str::lower(Str::random(8)).'.fbk';
    $disk->put($alteredKey, $altered);

    $exit = Artisan::call('backup:verify-offsite', ['--key' => $alteredKey]);
    $output = Artisan::output();

    check($exit === 1, 'an altered frame fails the sweep', $output);
    check(str_contains($output, 'AES-256-GCM authentication'), '...naming authentication, not a generic read error', $output);
    check((int) (healthRow($firstCompany, BackupHealth::JOB_VERIFY)?->ok ?? 1) === 0, '...and the health row goes red');

    $disk->delete($alteredKey);

    $truncatedKey = 'backups/truncated-'.Str::lower(Str::random(8)).'.fbk';
    $disk->put($truncatedKey, substr($good, 0, strlen($good) - 32));

    $exit = Artisan::call('backup:verify-offsite', ['--key' => $truncatedKey]);
    $output = Artisan::output();

    check($exit === 1, 'a copy cut short inside upload fails the sweep', $output);
    check(str_contains($output, 'sealed footer'), '...naming the missing footer rather than blaming the key', $output);

    $disk->delete($truncatedKey);

    echo "\n== F: a copy past the grace period is the finding, not a skipped read ==\n";

    touch($disk->path($key), time() - 40 * 86_400);

    $exit = Artisan::call('backup:verify-offsite');
    $output = Artisan::output();

    check($exit === 1, 'a copy older than the grace period fails the sweep', $output);
    check(str_contains($output, 'stale') && str_contains($output, 'days old'), '...saying how old it is, not that the bytes are wrong', $output);
    check((int) (healthRow($firstCompany, BackupHealth::JOB_VERIFY)?->ok ?? 1) === 0, '...and the health row goes red');

    $raised = DB::table('alerts')
        ->where('type', 'self_check_failure')
        ->where('cooldown_key', 'like', '%backup:verify-offsite%')
        ->when($alertIdsBefore !== [], static fn ($query) => $query->whereNotIn('id', $alertIdsBefore))
        ->count();

    check($raised > 0, '...and the Owner has an alert on the register, not just a log line');
} finally {
    $cleanUp();
}

echo "\n== G: the harness leaves no copy, no scratch and no health row behind ==\n";

check(! File::isDirectory($root), 'the test disk is gone');
check(DB::table('backup_health')->count() === count($healthBefore), 'the health rows are the ones the run found', sprintf('%d now, %d before', DB::table('backup_health')->count(), count($healthBefore)));
check(
    DB::table('alerts')->when($alertIdsBefore !== [], static fn ($query) => $query->whereNotIn('id', $alertIdsBefore))->count() === 0,
    'no alert from this run remains on the register',
);

echo "\n== result ==\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
