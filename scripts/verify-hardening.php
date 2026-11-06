<?php

declare(strict_types=1);

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Backup\BackupCipher;
use App\Domain\Security\Backup\MissingBackupKey;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\MissingFieldKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Phase 9 verification — the release is configuration, and the guards that make the
 * configuration safe.
 *
 * Three questions, each one a claim the phase makes in prose somewhere else:
 *
 *   1. Can the deployment switches /the design describe be flipped by environment alone, and does
 *      every branch keep the properties (private, no framework serving) the design depends on?
 *   2. Do the key guards fail closed rather than falling back — a missing column key, a missing
 *      backup key, a key of the wrong length?
 *   3. Do the schedules, the runbook and the documents this phase wrote actually agree with each
 *      other? A scheduled control with no first response, or a README link to a file that moved,
 *      is the kind of release defect that is invisible in a test suite.
 *
 * READ-ONLY: this harness writes no row, raises no alert and leaves no file behind. The one
 * exception is config and environment values it sets and restores in a `finally`.
 *
 * Run: php scripts/verify-hardening.php
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

/**
 * Laravel's env() reads $_ENV, $_SERVER and getenv; setting all three is what makes a config file's
 * `env()` call see the drill value when it is re-required below.
 */
function setEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        return;
    }

    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$envNames = [
    'FLEET_DOCUMENTS_BUCKET',
    'FLEET_BACKUP_DISK',
    'FLEET_BACKUP_S3_KEY',
    'FLEET_BACKUP_S3_SECRET',
    'FLEET_BACKUP_S3_REGION',
    'FLEET_BACKUP_S3_BUCKET',
];

$envBefore = [];
foreach ($envNames as $name) {
    $value = getenv($name);
    $envBefore[$name] = $value === false ? null : $value;
}

$configBefore = [
    'fleet.crypto' => config('fleet.crypto'),
    'fleet.backup.key' => config('fleet.backup.key'),
];

CurrentCompany::forget();

$cleanUp = static function () use ($envBefore, $configBefore): void {
    foreach ($envBefore as $name => $value) {
        setEnv($name, $value);
    }

    config($configBefore);
    CurrentCompany::forget();
};

try {
    echo "\n== A: the deployment switches are environment, not a code edit ==\n";

    $filesystems = require base_path('config/filesystems.php');
    $documents = $filesystems['disks']['documents_private'];

    check(($documents['driver'] ?? null) === 'local', 'with no bucket named, documents are on the local disk');
    check(($documents['root'] ?? null) === storage_path('app/documents'), '...under storage/app/documents', (string) ($documents['root'] ?? '-'));
    check(($documents['serve'] ?? true) === false, '...and the framework serves nothing from it');
    check(($documents['throw'] ?? false) === true, '...and a missing file is an exception, not a silent false');

    $links = $filesystems['links'] ?? [];
    check(count($links) === 1, 'there is exactly one public symlink', json_encode($links));
    check(
        array_keys($links) === [public_path('storage')],
        '...the public disk link, and nothing else',
        json_encode(array_keys($links)),
    );
    check(
        ! str_contains(json_encode($links) ?: '', 'documents') && ! str_contains(json_encode($links) ?: '', 'offsite'),
        '...so no documents or off-site path is reachable through it',
        json_encode($links),
    );

    setEnv('FLEET_DOCUMENTS_BUCKET', 'drill-documents-bucket');
    $filesystems = require base_path('config/filesystems.php');
    $documents = $filesystems['disks']['documents_private'];

    check(($documents['driver'] ?? null) === 's3', 'naming FLEET_DOCUMENTS_BUCKET moves documents to object storage');
    check(($documents['bucket'] ?? null) === 'drill-documents-bucket', '...to the named bucket', (string) ($documents['bucket'] ?? '-'));
    check(($documents['visibility'] ?? null) === 'private', '...with private visibility');
    check(($documents['serve'] ?? true) === false, '...and still no framework route serving it');
    check(($documents['throw'] ?? false) === true, '...and still failing loudly');

    setEnv('FLEET_DOCUMENTS_BUCKET', null);
    $filesystems = require base_path('config/filesystems.php');

    check(($filesystems['disks']['documents_private']['driver'] ?? null) === 'local', 'removing the bucket names puts the disk back where it was');

    $offsite = $filesystems['disks']['offsite'] ?? [];
    check(($offsite['driver'] ?? null) === 'local', 'the development off-site disk is local');
    check(($offsite['root'] ?? null) === storage_path('app/offsite'), '...under storage/app/offsite', (string) ($offsite['root'] ?? '-'));
    check(($offsite['serve'] ?? true) === false, '...and nothing may download an export through the app');

    $fleet = require base_path('config/fleet.php');

    check(($fleet['backup']['disk'] ?? null) === 'offsite', 'the exporter defaults to the off-site disk');

    setEnv('FLEET_BACKUP_DISK', 'offsite_s3');
    $fleet = require base_path('config/fleet.php');

    check(($fleet['backup']['disk'] ?? null) === 'offsite_s3', 'FLEET_BACKUP_DISK moves the copy without touching code');

    setEnv('FLEET_BACKUP_S3_KEY', 'drill-key');
    setEnv('FLEET_BACKUP_S3_SECRET', 'drill-secret');
    setEnv('FLEET_BACKUP_S3_REGION', 'af-south-1');
    setEnv('FLEET_BACKUP_S3_BUCKET', 'drill-backup-bucket');

    $filesystems = require base_path('config/filesystems.php');
    $s3Offsite = $filesystems['disks']['offsite_s3'] ?? [];

    check(($s3Offsite['driver'] ?? null) === 's3', 'the production disk is S3-compatible');
    check(($s3Offsite['key'] ?? null) === 'drill-key', '...reading its own key, not the documents bucket\'s', (string) ($s3Offsite['key'] ?? '-'));
    check(($s3Offsite['secret'] ?? null) === 'drill-secret', '...its own secret');
    check(($s3Offsite['region'] ?? null) === 'af-south-1', '...its own region');
    check(($s3Offsite['bucket'] ?? null) === 'drill-backup-bucket', '...and its own bucket');
    check(($s3Offsite['visibility'] ?? null) === 'private', '...private');
    check(($s3Offsite['serve'] ?? true) === false, '...and unserved');

    $filesystemsSource = (string) file_get_contents(base_path('config/filesystems.php'));
    $offsiteAt = strpos($filesystemsSource, "'offsite_s3' =>");
    $offsiteBlock = $offsiteAt === false ? '' : substr($filesystemsSource, $offsiteAt);

    check($offsiteBlock !== '', 'the off-site S3 disk is defined in config/filesystems.php');
    check(str_contains($offsiteBlock, 'FLEET_BACKUP_S3_KEY'), '...wired to FLEET_BACKUP_S3_* in the file itself');
    check(! str_contains($offsiteBlock, 'AWS_ACCESS_KEY_ID'), '...and never to the documents bucket\'s AWS_* credentials');

    $envExample = (string) file_get_contents(base_path('.env.example'));

    foreach ([
        'APP_KEY',
        'DB_CRYPT_KEY_VERSION',
        'DB_CRYPT_KEY',
        'DB_CRYPT_KEY_V2',
        'FLEET_BACKUP_KEY',
        'FLEET_BACKUP_DISK',
        'FLEET_BACKUP_STALE_DAYS',
        'FLEET_BACKUP_S3_KEY',
        'FLEET_BACKUP_S3_BUCKET',
        'FLEET_DOCUMENTS_BUCKET',
    ] as $name) {
        check(str_contains($envExample, $name.'='), '.env.example documents '.$name);
    }

    echo "\n== B: every key guard fails closed ==\n";

    $fieldCipher = app(FieldCipher::class);
    $current = $fieldCipher->currentVersion();

    check($fieldCipher->supports($current), 'the column cipher reads its configured version');
    check(! $fieldCipher->supports('v999'), '...and refuses a version the key ring does not hold');

    config(['fleet.crypto.current_version' => 'v999']);
    $missingFieldKey = null;

    try {
        (new FieldCipher)->encrypt('secret');
    } catch (MissingFieldKey $exception) {
        $missingFieldKey = $exception;
    }

    config(['fleet.crypto' => $configBefore['fleet.crypto']]);

    check($missingFieldKey !== null, 'an unconfigured current version refuses to encrypt rather than falling back to APP_KEY');

    $backupCipher = BackupCipher::fromConfig();

    check($backupCipher->fingerprint() !== '', 'the backup cipher seals with FLEET_BACKUP_KEY');

    config(['fleet.backup.key' => null]);
    $missingBackupKey = null;

    try {
        BackupCipher::fromConfig();
    } catch (MissingBackupKey $exception) {
        $missingBackupKey = $exception;
    }

    config(['fleet.backup.key' => $configBefore['fleet.backup.key']]);

    check($missingBackupKey !== null, 'a missing backup key refuses the export rather than writing plaintext');

    $malformed = null;

    try {
        new BackupCipher('base64:not-base64!!');
    } catch (MissingBackupKey $exception) {
        $malformed = $exception;
    }

    $short = null;

    try {
        new BackupCipher('too-short');
    } catch (MissingBackupKey $exception) {
        $short = $exception;
    }

    check($malformed !== null, 'a malformed base64 key is refused');
    check($short !== null, 'a key of the wrong length is refused, not padded');

    $tables = array_values(array_map('strval', (array) config('fleet.backup.tables')));

    foreach (['gl_journal_entries', 'gl_journal_lines', 'invoices', 'invoice_lines', 'payments', 'payment_allocations'] as $required) {
        check(in_array($required, $tables, true), 'the export contract includes '.$required);
    }

    check(count($tables) === count(array_unique($tables)), 'the export table list names no table twice');

    $existing = array_map(
        static fn (object $row): string => (string) array_values((array) $row)[0],
        DB::select('select table_name from information_schema.tables where table_schema = database()'),
    );
    $missingTables = array_values(array_diff($tables, $existing));

    check($missingTables === [], 'every table the contract promises exists in this schema', json_encode($missingTables));

    echo "\n== C: the audits run clean, in both launcher and CI modes ==\n";

    $exit = Artisan::call('security:audit', ['--no-alert' => true]);
    $output = Artisan::output();

    check($exit === 0, 'security:audit exits 0 against the live schema', $output);
    check(str_contains($output, 'private disk not served'), '...and names the documents disk as unserved', $output);
    check(str_contains($output, 'encrypted column pairs'), '...and counts the encrypted columns it read', $output);

    $exit = Artisan::call('security:audit', ['--static' => true, '--no-alert' => true]);
    $output = Artisan::output();

    check($exit === 0, 'security:audit --static exits 0 without a schema', $output);

    $exit = Artisan::call('performance:audit', ['--no-alert' => true]);
    $output = Artisan::output();

    check($exit === 0, 'performance:audit exits 0 against the live schema', $output);
    check(str_contains($output, 'unbudgeted'), '...and reports every page against a budget', $output);

    $exit = Artisan::call('performance:audit', ['--static' => true, '--no-alert' => true]);
    $output = Artisan::output();

    check($exit === 0, 'performance:audit --static exits 0 without a schema', $output);

    echo "\n== D: rotation is a registered command, not only a document ==\n";

    check(array_key_exists('keys:reencrypt', Artisan::all()), 'keys:reencrypt is registered');

    $exit = Artisan::call('keys:reencrypt', ['--dry-run' => true]);
    $output = Artisan::output();

    check($exit === 0, 'keys:reencrypt --dry-run exits 0 on the development book', $output);
    check(str_contains($output, 'dry run'), '...and says it wrote nothing', $output);
    check(str_contains($output, $current), '...naming the version it would read from', $output);

    echo "\n== E: every scheduled control has a runbook first response ==\n";

    $exit = Artisan::call('schedule:list');
    $listing = Artisan::output();

    check($exit === 0, 'schedule:list renders', $listing);

    $scheduled = [];
    foreach (preg_split('/\R/', $listing) ?: [] as $line) {
        if (! str_contains($line, 'php artisan ')) {
            continue;
        }

        [$expression, $rest] = explode('php artisan ', $line, 2);
        $command = preg_split('/\s+/', trim($rest))[0] ?? '';

        if ($command !== '') {
            $scheduled[$command] = preg_split('/\s+/', trim($expression)) ?: [];
        }
    }

    check($scheduled !== [], 'the schedule has entries', (string) count($scheduled));

    foreach (['queues:check', 'ledger:verify', 'security:audit', 'performance:audit', 'reports:tie-to-ledger', 'backup:export-offsite', 'backup:verify-offsite', 'numbering:ensure', 'reminders:run', 'reports:run-scheduled'] as $command) {
        check(array_key_exists($command, $scheduled), $command.' is scheduled');
    }

    $exportCron = $scheduled['backup:export-offsite'] ?? [];
    check(($exportCron[2] ?? '') === '1', 'the export runs on the first of the month', implode(' ', $exportCron));

    $verifyCron = $scheduled['backup:verify-offsite'] ?? [];
    check(($verifyCron[2] ?? '') === '*' && ($verifyCron[4] ?? '') === '*', 'the verify runs daily, not monthly', implode(' ', $verifyCron));

    $queueCron = $scheduled['queues:check'] ?? [];
    check(($queueCron[0] ?? '') === '*/5', 'queue health runs every five minutes', implode(' ', $queueCron));

    $console = (string) file_get_contents(base_path('routes/console.php'));
    preg_match_all("/Schedule::command\('([^']+)'\)/", $console, $matches);
    $declared = array_values(array_unique($matches[1] ?? []));

    check($declared !== [], 'routes/console.php declares its commands', (string) count($declared));

    $runbook = (string) file_get_contents(base_path('docs/operations/runbook.md'));
    $undocumented = array_values(array_filter($declared, static fn (string $command): bool => ! str_contains($runbook, $command)));

    check($undocumented === [], 'every scheduled command is named in the runbook', json_encode($undocumented));

    echo "\n== F: the documents this phase wrote resolve and say what they promise ==\n";

    foreach ([
        'docs/operations/runbook.md',
        'docs/operations/deployment.md',
        'docs/operations/restore.md',
        'docs/operations/key-rotation.md',
        'docs/operations/hardening.md',
        'docs/user-guide.md',
        'docs/uat.md',
    ] as $path) {
        check(is_file(base_path($path)), $path.' exists');
    }

    $readme = (string) file_get_contents(base_path('README.md'));
    preg_match_all('/\]\(([^)#]+)\)/', $readme, $linksFound);

    $brokenLinks = [];
    foreach (array_unique($linksFound[1] ?? []) as $link) {
        if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
            continue;
        }

        if (! is_file(base_path($link)) && ! is_dir(base_path($link))) {
            $brokenLinks[] = $link;
        }
    }

    check($brokenLinks === [], 'every local link in README.md resolves', json_encode($brokenLinks));

    $rotation = (string) file_get_contents(base_path('docs/operations/key-rotation.md'));

    foreach (['APP_KEY', 'DB_CRYPT_KEY', 'keys:reencrypt', 'FLEET_BACKUP_KEY'] as $term) {
        check(str_contains($rotation, $term), 'key-rotation names '.$term);
    }

    $restore = (string) file_get_contents(base_path('docs/operations/restore.md'));

    check(str_contains($restore, '--drill'), 'restore documents the drill a person can actually run');
    check(stripos($restore, 'snapshot') !== false, '...and the Cloud snapshot path');
    check(str_contains($restore, 'documents'), '...and is honest about what the export does not carry');

    $deployment = (string) file_get_contents(base_path('docs/operations/deployment.md'));

    check(str_contains($deployment, 'FLEET_DOCUMENTS_BUCKET'), 'deployment documents the documents-bucket switch');
    check(str_contains($deployment, 'flysystem-aws-s3-v3'), '...and the package the S3 disk needs');
    check(str_contains($deployment, 'migrate --force'), '...and the release migration step');

    $uat = (string) file_get_contents(base_path('docs/uat.md'));

    check(stripos($uat, 'trip-to-invoice') !== false || stripos($uat, 'trip to invoice') !== false, 'UAT starts from the trip-to-invoice path');
    check(stripos($uat, 'known gap') !== false, '...and states the known gap rather than writing around it');

    $hardening = (string) file_get_contents(base_path('docs/operations/hardening.md'));

    check(stripos($hardening, 'escrow') !== false, 'hardening documents secret escrow');
} finally {
    $cleanUp();
}

echo "\n== G: the harness changed nothing it was not given ==\n";

check(getenv('FLEET_DOCUMENTS_BUCKET') === $envBefore['FLEET_DOCUMENTS_BUCKET'] || ($envBefore['FLEET_DOCUMENTS_BUCKET'] === null && getenv('FLEET_DOCUMENTS_BUCKET') === false), 'the environment is where it was found');
check(config('fleet.crypto') === $configBefore['fleet.crypto'], 'the column key ring is unchanged');
check(config('fleet.backup.key') === $configBefore['fleet.backup.key'], 'the backup key is unchanged');

echo "\n== result ==\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
