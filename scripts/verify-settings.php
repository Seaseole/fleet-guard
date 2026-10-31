<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\Actions\RecordBill;
use App\Domain\Accounting\BillCategory;
use App\Domain\Accounting\Data\BillLineInput;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\SetStandardVatRate;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\Queries\EffectiveVat;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Settings\CachedSettings;
use App\Domain\Kernel\Settings\Actions\UpdateCompanySettings;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Settings\SettingsCatalogue;
use App\Domain\Kernel\Settings\Theme;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
 * §20.37 verification — the two things an Owner can now set at runtime, and the odometer column.
 *
 *  - the standard VAT rate is a field on Application settings, and it is a **door** onto the dated
 *    register rather than a second copy in the settings blob: changing it closes a window and opens
 *    another, so a document issued under the old percentage still prints and posts the old one;
 *  - the theme every device opens in is the company's own setting, applied before first paint, with a
 *    personal choice still layered on top for the browser that made it;
 *  - and the truck's odometer table reports the kilometres between readings, which is the number a
 *    fleet person is actually asking when they look at a measurement.
 *
 * The register is restored from a snapshot on the way out, because this is the accountant's dev data and
 * a harness that leaves the standard rate at 15% is a harness that changed the tax position.
 *
 * Run: php scripts/verify-settings.php
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

CurrentCompany::force(1);

// The register as this run found it: every row, so the restore is exact rather than approximate.
$registerBefore = DB::table('tax_rates')->get()->keyBy('id');
$themeBefore = DB::table('companies')->where('id', 1)->value('settings');

function cleanUp(): void
{
    global $registerBefore, $themeBefore;

    CurrentCompany::force(1);

    // Bills, suppliers and the truck this run billed against.
    $supplierIds = DB::table('suppliers')->where('code', 'like', 'SET-VERIFY%')->pluck('id')->all();
    $billIds = $supplierIds === [] ? [] : DB::table('bills')->whereIn('supplier_id', $supplierIds)->pluck('id')->all();
    $entryIds = $billIds === [] ? [] : DB::table('gl_journal_entries')
        ->where('source_type', 'bill')->whereIn('source_id', $billIds)->pluck('id')->all();

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-settings: remove this run\'s bill postings', null, function () use ($entryIds): void {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        });
    }

    if ($billIds !== []) {
        DB::table('bill_lines')->whereIn('bill_id', $billIds)->delete();
        DB::table('operational_events')->where('aggregate_type', 'bill')->whereIn('aggregate_id', $billIds)->delete();
        DB::table('bills')->whereIn('id', $billIds)->delete();
    }

    DB::table('suppliers')->where('code', 'like', 'SET-VERIFY%')->delete();

    $truckIds = DB::table('trucks')->where('registration_no', 'like', '%SV1')->pluck('id')->all();
    if ($truckIds !== []) {
        DB::table('odometer_readings')->whereIn('truck_id', $truckIds)->delete();
        DB::table('trucks')->whereIn('id', $truckIds)->delete();
    }

    // The register, row for row: what this run created is deleted, what it amended or closed is put
    // back exactly as it was found. A tax window is not a fixture to leave in a new shape.
    $now = DB::table('tax_rates')->pluck('id')->all();
    $created = array_values(array_diff($now, $registerBefore->keys()->all()));

    if ($created !== []) {
        DB::table('tax_rates')->whereIn('id', $created)->delete();
    }

    foreach ($registerBefore as $id => $row) {
        DB::table('tax_rates')->where('id', $id)->update([
            'rate_x10000' => $row->rate_x10000,
            'starts_on' => $row->starts_on,
            'ends_on' => $row->ends_on,
            'is_active' => $row->is_active,
            'is_default' => $row->is_default,
            'updated_at' => now(),
        ]);
    }

    // The theme knob and the probe seats. The blob row is put back byte for byte, and the read cache
    // is dropped through its own door so no later request in this database sees this run's theme.
    DB::table('companies')->where('id', 1)->update(['settings' => $themeBefore]);
    app(CachedSettings::class)->flush(1);

    $probeIds = DB::table('users')->where('email', 'like', 'verify-set-%@fleetguard.local')->pluck('id')->all();

    if ($probeIds !== []) {
        DB::table('number_sequences')->whereIn('last_used_by', $probeIds)->update(['last_used_by' => null]);
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds)->delete();
        // The rate-change trail names the probe as its actor, so the events go before the seats: a
        // foreign key is a rule, and `delete` would fail rather than cascade silently (§6.3's lesson).
        DB::table('security_events')->whereIn('user_id', $probeIds)->delete();
        DB::table('users')->whereIn('id', $probeIds)->delete();
    }

    $rewind = static function (string $scope, string $table, string $column): void {
        foreach (DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->get() as $row) {
            $highest = DB::table($table)->where('company_id', 1)->whereNotNull($column)
                ->pluck($column)->map(static function (string $number): int {
                    $dash = strrpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                })->max() ?? 0;

            DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        }
    };

    $rewind('bill', 'bills', 'number');
    $rewind('journal_entry', 'gl_journal_entries', 'entry_no');
}

// The register is restored from a snapshot on the way out, because this is the accountant's dev data
// and a harness that leaves the standard rate at 15% is a harness that changed the tax position. The
// restore runs on a shutdown handler rather than `finally`: a fatalled section must not be what leaves
// a new VAT window in the book.
register_shutdown_function(static function (): void {
    cleanUp();
});

echo "== A: the catalogue knows the difference between a blob value and a door ==\n";

check(in_array('appearance.theme', SettingsCatalogue::keys(), true), 'the default theme is an editable company setting (§20.37)');
check(in_array('tax.standard_rate_pct', SettingsCatalogue::keys(), true), '...and the standard VAT rate is a field an Owner can reach');
check(SettingsCatalogue::store('tax.standard_rate_pct') === 'door', 'the VAT field is declared as a door, not a blob key');
check(! in_array('tax.standard_rate_pct', SettingsCatalogue::blobKeys(), true), '...so the settings writer never receives it');
check(in_array('appearance.theme', SettingsCatalogue::blobKeys(), true), 'while the theme does live in the blob');
check(in_array('in:light,dark,system', SettingsCatalogue::rules()['settings.appearanceTheme'] ?? [], true), '...validated against its declared options rather than free text');

$unknown = '';

try {
    app(UpdateCompanySettings::class)(['tax.standard_rate_pct' => 15.0]);
} catch (Throwable $refused) {
    $unknown = $refused->getMessage();
}

check(str_contains($unknown, 'settings blob'), 'and a caller that tries to store the rate in the blob is refused by the writer itself', $unknown);
check(! array_key_exists('tax', (array) (DB::table('companies')->where('id', 1)->value('settings') ?? [])), '...leaving no tax branch in the tenant row');

/* ------------------------------------------------------------------ B: the theme */
echo "\n== B: the theme everyone opens in is the company's own choice ==\n";

$owner = User::query()->firstOrNew(['email' => 'verify-set-owner@fleetguard.local']);
$owner->name = 'Verify SET Owner';
$owner->company_id = 1;
$owner->password = Str::random(32);
$owner->email_verified_at = Carbon::now();
$owner->save();
app(ChangeUserRole::class)($owner->fresh(), RoleKey::Owner);
$owner = $owner->fresh();

$accountant = User::query()->firstOrNew(['email' => 'verify-set-accountant@fleetguard.local']);
$accountant->name = 'Verify SET Accountant';
$accountant->company_id = 1;
$accountant->password = Str::random(32);
$accountant->email_verified_at = Carbon::now();
$accountant->save();
app(ChangeUserRole::class)($accountant->fresh(), RoleKey::Accountant);
$accountant = $accountant->fresh();

Auth::login($owner);

check(Theme::defaultMode() === 'system', 'an unset company answers system — the deployment default, not a blank (§20.37)', Theme::defaultMode());
check(Theme::htmlClass() === null, '...which renders no class, because only the device knows its own preference');

app(UpdateCompanySettings::class)(['appearance.theme' => 'dark']);
CurrentCompany::forget();
CurrentCompany::force(1);

check(Theme::defaultMode() === 'dark', 'the Owner setting Dark makes that the company answer');
check(Theme::htmlClass() === 'dark', '...and the root element carries the class on first paint, before any script runs');

$illegal = '';

try {
    app(UpdateCompanySettings::class)(['appearance.theme' => 'bogus']);
} catch (Throwable $refused) {
    $illegal = $refused->getMessage();
}

check(str_contains($illegal, 'not among them'), 'a theme outside the declared options is refused by the writer, not stored and filtered on read', $illegal);
check(Theme::defaultMode() === 'dark', '...and the stored value is still the one that was legitimately set', Theme::defaultMode());

DB::table('companies')->where('id', 1)->update(['settings' => $themeBefore]);
app(CachedSettings::class)->flush(1);
CurrentCompany::forget();
CurrentCompany::force(1);

check(Theme::defaultMode() === 'system', 'restoring the row restores the answer, so this run leaves no theme behind');

/* ------------------------------------------------------------------ C: the VAT door */
echo "\n== C: changing the standard rate closes a window and opens another ==\n";

$standard = app(EffectiveVat::class)->standardPercent();
check($standard > 0, 'the register answers the field with a real percentage today', "percent={$standard}");

$setVat = app(SetStandardVatRate::class);

$openBefore = (int) DB::table('tax_rates')->where('kind', 'standard')->whereNull('ends_on')->count();
$idsBefore = DB::table('tax_rates')->pluck('id')->all();

Auth::login($accountant);

$moved = null;
$gateRefused = '';

try {
    $setVat->execute(13.0, $accountant->id);
} catch (Throwable $refused) {
    $gateRefused = $refused->getMessage();
}

check(! Gate::forUser($accountant)->allows('settings.manage'), 'the Accountant does not hold the ability this door asks for (§9.3)');
check(str_contains($gateRefused, 'settings.manage'), '...so the rate change is refused and the refusal names the ability', $gateRefused);
check((int) DB::table('tax_rates')->count() === count($idsBefore), '...and the refused attempt wrote no window at all');

Auth::login($owner);

$openBefore = (int) DB::table('tax_rates')->where('kind', 'standard')->whereNull('ends_on')->count();
$idsBefore = DB::table('tax_rates')->pluck('id')->all();

$same = $setVat->execute($standard, $owner->id);

check((int) DB::table('tax_rates')->where('id', $same->id)->count() === 1, 'saving the rate that already applies today returns the row that stands');
check(DB::table('tax_rates')->pluck('id')->all() === $idsBefore, '...and writes no new window: a save that changes nothing must not churn the register', 'rows moved');

$moved = $setVat->execute($standard >= 20 ? 21.0 : 20.0, $owner->id);

// PHP's `/` returns an int when two ints divide exactly, and the door answers a float — so the
// comparison is made between like types rather than by `==`, which would pass on `'20'` too.
$movedPercent = (float) ($moved->rate_x10000 / 10_000);

check($movedPercent !== $standard, 'a real change moves the rate to a new figure', "now={$movedPercent}");
check(app(EffectiveVat::class)->standardPercent() === $movedPercent, '...and the door the field reads answers the new figure immediately', sprintf('door=%s(%s) moved=%s(%s)', var_export(app(EffectiveVat::class)->standardPercent(), true), get_debug_type(app(EffectiveVat::class)->standardPercent()), var_export($movedPercent, true), get_debug_type($movedPercent)));
check((int) DB::table('tax_rates')->where('kind', 'standard')->whereNull('ends_on')->count() === $openBefore, '...with still exactly one open standard window', 'open='.$openBefore);

$sameDay = $setVat->execute($movedPercent + 1, $owner->id);
check($sameDay->id === $moved->id, 'a second change on the same day amends the window that opened today instead of colliding with the unique (company, code, starts_on) key');
check((int) DB::table('tax_rates')->where('id', $moved->id)->value('rate_x10000') === (int) (($movedPercent + 1) * 10_000), '...in place, because nothing was issued between the two clicks');
check((int) DB::table('tax_rates')->count() === count($idsBefore), '...which is why no extra row exists');

$past = Carbon::now()->subYears(2)->startOfDay();
$oldWindow = TaxRate::effectiveOn($past, TaxRateKind::Standard);

check($oldWindow !== null && $oldWindow->rate_x10000 !== $sameDay->rate_x10000, 'the window the change closed still answers a past date — history is read, not rewritten', $oldWindow === null ? 'unfindable' : 'found');

$zeroRefused = '';

try {
    $setVat->execute(0.0, $owner->id);
} catch (Throwable $refused) {
    $zeroRefused = $refused->getMessage();
}

check(str_contains($zeroRefused, 'zero-rated'), 'a 0% standard rate is refused and points at the kind that means it', $zeroRefused);

$fineRefused = '';

try {
    $setVat->execute(12.00005, $owner->id);
} catch (Throwable $refused) {
    $fineRefused = $refused->getMessage();
}

check(str_contains($fineRefused, 'decimal places'), 'and a rate finer than the register stores is refused rather than rounded away', $fineRefused);

$trail = (int) DB::table('security_events')->where('kind', 'settings_changed')->where('meta', 'like', '%tax.standard_rate_pct%')->count();
check($trail > 0, 'every rate change is in the security trail, because §9.3 marks settings ⚑', "rows={$trail}");

/* ------------------------------------------------------------------ D: the doors use it */
echo "\n== D: the documents actually charge the new rate ==\n";

$rateNow = app(EffectiveVat::class)->standardPercent();
$currency = CurrentCompany::model()->base_currency_code;

$supplier = (app(CreateSupplier::class))(new SupplierDto(name: 'SET-VERIFY Garage', code: 'SET-VERIFY-SUP'));
$truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 994 SV1', fleetNo: 'T-SV1', vin: 'VINSV100000000000000001', make: 'Probe', model: 'Settings', odometerKm: 10_000));

$net = 100_000; // P1,000.00

/** @var \App\Domain\Accounting\Models\Bill $bill */
$bill = (app(RecordBill::class))->execute(
    supplierId: $supplier->id,
    lines: [new BillLineInput(description: 'Clutch repair', category: BillCategory::Labour, netMinor: $net, truckId: $truck->id)],
    billOn: Carbon::now(),
    dueOn: Carbon::now()->addDays(14),
    note: 'SET-VERIFY rate probe',
    actorId: $owner->id,
);

$expectedTax = (int) round($net * $rateNow / 100);

check($bill->tax_amount === $expectedTax, 'the bill door charges the rate the settings field shows, per line', sprintf('tax=%d expected=%d at %s%%', $bill->tax_amount, $expectedTax, $rateNow));
check((int) $bill->lines()->value('tax_amount') === $expectedTax, '...and the percentage is frozen on the line, not re-read later (§5.5)');

$next = $rateNow >= 19 ? $rateNow - 3 : $rateNow + 3;
$setVat->execute((float) $next, $owner->id);

/** @var \App\Domain\Accounting\Models\Bill $second */
$second = (app(RecordBill::class))->execute(
    supplierId: $supplier->id,
    lines: [new BillLineInput(description: 'Second clutch repair', category: BillCategory::Labour, netMinor: $net, truckId: $truck->id)],
    billOn: Carbon::now(),
    dueOn: Carbon::now()->addDays(14),
    note: 'SET-VERIFY rate probe two',
    actorId: $owner->id,
);

check($second->tax_amount === (int) round($net * $next / 100), '...and the next document charges the changed rate', sprintf('tax=%d expected=%d at %s%%', $second->tax_amount, (int) round($net * $next / 100), $next));
check((int) $bill->fresh()->lines()->value('tax_amount') === $expectedTax, '...while the first document still carries the rate it was recorded at');

/* ------------------------------------------------------------------ E: the odometer column */
echo "\n== E: a reading says how far the truck actually went ==\n";

$record = app(\App\Domain\Fleet\Actions\RecordOdometerReading::class);

foreach ([41_000, 41_250, 41_900] as $index => $km) {
    $record(new \App\Domain\Fleet\Data\OdometerReadingDto(
        truckId: (int) $truck->id,
        odometerKm: $km,
        readOn: Carbon::now()->subDays(30 - $index)->toDateString(),
        source: \App\Domain\Fleet\OdometerSource::Manual,
    ));
}

$travelled = [];
$previous = null;

foreach (DB::table('odometer_readings')->where('truck_id', $truck->id)->orderBy('read_on')->orderBy('id')->get(['odometer_km']) as $row) {
    $travelled[] = $previous === null ? null : $row->odometer_km - $previous;
    $previous = $row->odometer_km;
}

check($travelled[1] === 250, 'the second reading is the distance the truck covered since the first', json_encode($travelled));
check($travelled[2] === 650, '...and the third is the leg after it, not the total from the start');

echo "\n== result ==\n";
printf("%d checks, %d failed\n", $checks, $failures);

Auth::logout();

exit($failures === 0 ? 0 : 1);
