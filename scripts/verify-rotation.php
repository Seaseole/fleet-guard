<?php

declare(strict_types=1);

/**
 * Key rotation verification (DESIGN.md "key rotation").
 *
 * Not a test suite — tests/ stays out of the repository by the owner's decision. This is the
 * runnable proof that `keys:reencrypt` actually moves a column from one key version to the next,
 * because the failure mode it guards is silent: a rotation that updates `key_version` without
 * re-encrypting, or re-encrypts with the wrong key, produces a column that reports the new version
 * and cannot be read.
 *
 * The run is company-scoped on purpose. Rotating the development database's real rows onto a
 * throwaway harness key would leave them unreadable, so every fixture lives in a company created for
 * this run and the rotation is invoked with `--company`.
 *
 * Run: php scripts/verify-rotation.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\DataGovernance\Actions\RotateFieldKeys;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\MissingFieldKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to run against a production database.\n");

    exit(1);
}

$failures = 0;
$checks = 0;

function check(string $label, callable $assertion): void
{
    global $failures, $checks;
    $checks++;

    try {
        $result = $assertion();

        if ($result === true) {
            echo "  ok    {$label}\n";

            return;
        }

        $failures++;
        echo '  FAIL  '.$label.(is_string($result) ? " — {$result}" : '')."\n";
    } catch (Throwable $exception) {
        $failures++;
        echo "  FAIL  {$label} — ".get_class($exception).': '.$exception->getMessage()."\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

$suffix = Str::lower(Str::random(6));
$companyId = (int) DB::table('companies')->insertGetId([
    'name' => "Verify rotation {$suffix}",
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => now(),
    'updated_at' => now(),
]);

$controlCompanyId = 1;
$controlId = null;

/** @var FieldCipher $v1 */
$v1 = app(FieldCipher::class);

$plain = [
    'bank' => '5555 6666 7777 8888',
    'national_id' => 'BW-123456789',
    'licence' => 'DL-998877',
    'card' => 'CARD-1111-2222-3333',
    'api_key' => 'gps-secret-key-value',
];

$bankId = (int) DB::table('bank_accounts')->insertGetId([
    'company_id' => $companyId,
    'name' => 'Rotation fixture bank',
    'institution' => 'Rotation Bank',
    'number_encrypted' => $v1->encrypt($plain['bank']),
    'number_masked' => '••••8888',
    'key_version' => $v1->currentVersion(),
    'currency_code' => 'USD',
    'type' => 'bank',
    'created_at' => now(),
    'updated_at' => now(),
]);

$employeeId = (int) DB::table('employees')->insertGetId([
    'company_id' => $companyId,
    'employee_no' => 'ROT-'.$suffix,
    'name' => 'Rotation fixture employee',
    'national_id_masked' => '••••6789',
    'national_id_encrypted' => $v1->encrypt($plain['national_id']),
    'key_version' => $v1->currentVersion(),
    'hired_on' => '2020-01-01',
    'created_at' => now(),
    'updated_at' => now(),
]);

$driverId = (int) DB::table('drivers')->insertGetId([
    'company_id' => $companyId,
    'employee_id' => $employeeId,
    'licence_no_masked' => '••••8877',
    'licence_no_encrypted' => $v1->encrypt($plain['licence']),
    'key_version' => $v1->currentVersion(),
    'created_at' => now(),
    'updated_at' => now(),
]);

$cardId = (int) DB::table('fuel_cards')->insertGetId([
    'company_id' => $companyId,
    'alias' => 'Rotation fixture card',
    'card_ref_encrypted' => $v1->encrypt($plain['card']),
    'card_ref_masked' => '••••3333',
    'key_version' => $v1->currentVersion(),
    'created_at' => now(),
    'updated_at' => now(),
]);

$gpsId = (int) DB::table('gps_provider_accounts')->insertGetId([
    'company_id' => $companyId,
    'adapter' => 'verify-rotation',
    'api_key_encrypted' => $v1->encrypt($plain['api_key']),
    'key_version' => $v1->currentVersion(),
    'created_at' => now(),
    'updated_at' => now(),
]);

// A row no key can read. Rotation must report it and leave it alone rather than overwrite the
// evidence with a new payload.
$corruptId = (int) DB::table('bank_accounts')->insertGetId([
    'company_id' => $companyId,
    'name' => 'Rotation fixture corrupt',
    'institution' => 'Rotation Bank',
    'number_encrypted' => 'this-is-not-a-ciphertext',
    'number_masked' => '••••0000',
    'key_version' => 'v1',
    'currency_code' => 'USD',
    'type' => 'bank',
    'created_at' => now(),
    'updated_at' => now(),
]);

// A row in another tenant: `--company` has to leave it where it is, or the option is a fiction.
$controlId = (int) DB::table('bank_accounts')->insertGetId([
    'company_id' => $controlCompanyId,
    'name' => 'Rotation control '.$suffix,
    'institution' => 'Control Bank',
    'number_encrypted' => $v1->encrypt('9999 0000 1111 2222'),
    'number_masked' => '••••2222',
    'key_version' => $v1->currentVersion(),
    'currency_code' => 'USD',
    'type' => 'bank',
    'created_at' => now(),
    'updated_at' => now(),
]);

CurrentCompany::force($companyId);

$cleanUp = static function () use (
    $companyId,
    $controlCompanyId,
    $controlId,
): void {
    foreach (['drivers', 'employees', 'fuel_cards', 'gps_provider_accounts', 'bank_accounts'] as $table) {
        DB::table($table)->where('company_id', $companyId)->delete();
    }

    if ($controlId !== null) {
        DB::table('bank_accounts')->where('id', $controlId)->where('company_id', $controlCompanyId)->delete();
    }

    DB::table('security_events')->where('company_id', $companyId)->where('kind', 'key_rotated')->delete();
    DB::table('companies')->where('id', $companyId)->delete();

    CurrentCompany::forget();
};

try {
    section('A. Running against the version already in use is a no-op');

    $same = app(RotateFieldKeys::class)($companyId, false, 2);

    check('nothing is pending', fn () => $same['pending'] === 0 ?: 'pending '.$same['pending']);
    check('nothing is rewritten', fn () => $same['rotated'] === 0);
    check('no audit row is written for a no-op', fn () => DB::table('security_events')->where('company_id', $companyId)->where('kind', 'key_rotated')->count() === 0);
    check('all five encrypted tables are discovered', fn () => $same['tables'] === 5 ?: 'tables '.$same['tables']);

    section('B. After the configured version moves, the dry run names the old one without writing');

    $v2Key = 'base64:'.base64_encode(random_bytes(32));
    config([
        'fleet.crypto.keys.v2' => $v2Key,
        'fleet.crypto.current_version' => 'v2',
    ]);

    $dryRunStatus = Artisan::call('keys:reencrypt', [
        '--dry-run' => true,
        '--company' => (string) $companyId,
        '--batch' => '2',
    ]);

    check('the command exits successfully', fn () => $dryRunStatus === 0 ?: 'exit '.$dryRunStatus);
    check('the report says it was a dry run', fn () => str_contains(Artisan::output(), 'dry run') ?: Artisan::output());

    $dry = app(RotateFieldKeys::class)($companyId, true, 2);

    check('it finds every row on an older version', fn () => $dry['pending'] === 6 ?: 'pending '.$dry['pending']);
    check('it names v1 as the version in use', fn () => $dry['from'] === ['v1'] ?: implode(',', $dry['from']));
    check('it rotates nothing', fn () => $dry['rotated'] === 0 && $dry['failed'] === 0);
    check('the database is untouched', fn () => DB::table('bank_accounts')->where('id', $bankId)->value('key_version') === 'v1');

    section('C. A run with no readable current key refuses before writing');

    $storedKey = config('fleet.crypto.keys.v2');
    config(['fleet.crypto.keys.v2' => null]);

    check('a missing current key throws MissingFieldKey', function () use ($companyId) {
        try {
            app(RotateFieldKeys::class)($companyId, false, 2);

            return 'the rotation ran with no v2 key configured';
        } catch (MissingFieldKey) {
            return true;
        }
    });

    config(['fleet.crypto.keys.v2' => $storedKey]);

    check('the refused run wrote nothing', fn () => DB::table('bank_accounts')->where('id', $bankId)->value('key_version') === 'v1');

    section('D. v1 to v2 moves every readable payload');

    $before = (string) DB::table('bank_accounts')->where('id', $bankId)->value('number_encrypted');

    $rotation = app(RotateFieldKeys::class)($companyId, false, 2);

    check('five rows rotated', fn () => $rotation['rotated'] === 5 ?: 'rotated '.$rotation['rotated']);
    check('the unreadable row failed rather than vanished', fn () => $rotation['failed'] === 1 ?: 'failed '.$rotation['failed']);
    check('the failure names the row', function () use ($rotation, $corruptId) {
        foreach ($rotation['findings'] as $finding) {
            if ($finding['table'] === 'bank_accounts' && $finding['id'] === (string) $corruptId) {
                return true;
            }
        }

        return 'the corrupt row is missing from the findings';
    });
    check('the corrupt row keeps its old payload and version', fn () => DB::table('bank_accounts')->where('id', $corruptId)->value('key_version') === 'v1'
        && DB::table('bank_accounts')->where('id', $corruptId)->value('number_encrypted') === 'this-is-not-a-ciphertext');
    check('the rewritten ciphertext is not the old string', fn () => DB::table('bank_accounts')->where('id', $bankId)->value('number_encrypted') !== $before);
    check('the mask is untouched by rotation', fn () => DB::table('bank_accounts')->where('id', $bankId)->value('number_masked') === '••••8888');

    /** @var FieldCipher $v2 */
    $v2 = app(FieldCipher::class);

    check('the bank number decrypts under v2', fn () => $v2->decrypt((string) DB::table('bank_accounts')->where('id', $bankId)->value('number_encrypted'), 'v2') === $plain['bank']);
    check('the national ID decrypts under v2', fn () => $v2->decrypt((string) DB::table('employees')->where('id', $employeeId)->value('national_id_encrypted'), 'v2') === $plain['national_id']);
    check('the licence decrypts under v2', fn () => $v2->decrypt((string) DB::table('drivers')->where('id', $driverId)->value('licence_no_encrypted'), 'v2') === $plain['licence']);
    check('the card reference decrypts under v2', fn () => $v2->decrypt((string) DB::table('fuel_cards')->where('id', $cardId)->value('card_ref_encrypted'), 'v2') === $plain['card']);
    check('the GPS API key decrypts under v2', fn () => $v2->decrypt((string) DB::table('gps_provider_accounts')->where('id', $gpsId)->value('api_key_encrypted'), 'v2') === $plain['api_key']);

    check('every rotated row names v2', function () use ($bankId, $employeeId, $driverId, $cardId, $gpsId) {
        $versions = [
            DB::table('bank_accounts')->where('id', $bankId)->value('key_version'),
            DB::table('employees')->where('id', $employeeId)->value('key_version'),
            DB::table('drivers')->where('id', $driverId)->value('key_version'),
            DB::table('fuel_cards')->where('id', $cardId)->value('key_version'),
            DB::table('gps_provider_accounts')->where('id', $gpsId)->value('key_version'),
        ];

        return count(array_unique($versions)) === 1 && $versions[0] === 'v2' ?: implode(',', $versions);
    });

    check('another tenant is left on v1', fn () => DB::table('bank_accounts')->where('id', $controlId)->value('key_version') === 'v1');

    $event = DB::table('security_events')
        ->where('company_id', $companyId)
        ->where('kind', 'key_rotated')
        ->first();

    check('one audit row records the rotation', fn () => $event !== null ?: 'no key_rotated event');
    check('the audit row names the version pair', function () use ($event) {
        if ($event === null) {
            return 'no event';
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) $event->meta, true);

        return $meta['from_versions'] === ['v1'] && $meta['to_version'] === 'v2' && $meta['rows'] === 5
            ?: json_encode($meta);
    });

    section('E. Re-running is a no-op, and a resolved failure clears');

    DB::table('bank_accounts')->where('id', $corruptId)->delete();

    $again = app(RotateFieldKeys::class)($companyId, false, 2);

    check('nothing is pending after the rotation', fn () => $again['pending'] === 0 ?: 'pending '.$again['pending']);
    check('nothing is rotated twice', fn () => $again['rotated'] === 0);
    check('no second audit row', fn () => DB::table('security_events')->where('company_id', $companyId)->where('kind', 'key_rotated')->count() === 1);
    check('the payloads still read after the second run', fn () => $v2->decrypt((string) DB::table('bank_accounts')->where('id', $bankId)->value('number_encrypted'), 'v2') === $plain['bank']);
} finally {
    $cleanUp();
}

echo "\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
