<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fuel\Actions\CreateFuelStation;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\Actions\RecordFuelIssue;
use App\Domain\Fuel\Actions\RecordKioskFuelIssue;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\Data\KioskFuelDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\AdvanceTrip;
use App\Domain\Operations\Actions\ApprovePod;
use App\Domain\Operations\Actions\CaptureProofOfDelivery;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\IssueTripAccessToken;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Actions\RedeemKioskToken;
use App\Domain\Operations\Actions\RejectPod;
use App\Domain\Operations\Actions\RevokeTripAccessToken;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Exceptions\KioskScopeUnavailable;
use App\Domain\Operations\Kiosk\KioskAuthority;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripAccessScope;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 2 slice 2d verification — the kiosk and the proof of delivery.
 *
 * Not the test suite (`tests/` is local-only). This is the runnable proof of the sentences the
 * slice turns on:
 *
 * - *"A token cannot read another trip, and the database stores no secret"* — the row keeps SHA-256
 *   of the secret and of `pin|secret`, the model hides both, and the only copy of the plain values is the
 *   Action's return value;
 * - *"A driver may never transition to delivered"* — the capture reaches `pod_pending` and the
 *   office's approval, not the driver's, reaches `delivered`;
 * - The offline retry — the same `client_uuid` twice is one delivery note, not two.
 *
 * Phase 3's slice 3g adds the driver's fuel screen to the same file: a `fuel` link records the slip
 * the driver paid for, issues its litres onto the trip, posts the 5100 cost against petty cash and
 * moves the odometer — one transaction, through the Actions the office uses — with the token named on
 * the issue row and on its trail. The refusals that matter are the narrow scope, the trip binding and
 * the wrong tank; the retry that matters is the same `client_uuid`.
 *
 * It writes to the development database on the live MySQL 9.1 instance (docs/DEV-DATABASE.md) inside the
 * `KIO` fixture namespace, removes everything it created including the uploaded bytes, and asserts that
 * it did.
 *
 * Run: php scripts/verify-kiosk.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/**
 * Delete rows by an id list, refusing to run on an empty list.
 *
 * `whereIn(char(26)_column, [])` is harmless, but the first drafts of these scripts passed `[0]`, and
 * MySQL compared a ULID column against an integer and aborted the whole statement (the mine).
 */
function wipeIds(string $table, string $column, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    return DB::table($table)->whereIn($column, $ids)->delete();
}

/**
 * The barrier refuses deletes on the ledger; a fixture must lift it for its own entries only.
 *
 * @param  list<string>  $costIds
 * @return list<string>
 */
function kioskCostJournalEntries(array $costIds): array
{
    if ($costIds === []) {
        return [];
    }

    return DB::table('gl_journal_entries')
        ->where('source_type', 'trip_cost')
        ->whereIn('source_id', array_map('strval', $costIds))
        ->pluck('id')
        ->all();
}

/** @param list<string> $entryIds */
function purgeKioskJournalEntries(array $entryIds): void
{
    if ($entryIds === []) {
        return;
    }

    LedgerBarrier::withLiftedGuard('verify-kiosk: clear a run\'s fuel cost entries', null, function () use ($entryIds): void {
        DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
    });
}

/*
 * Pre-clean: a run that died halfway leaves a trip at `pod_pending` with a live token, and the next run
 * would report a fixture token as a product finding.
 */
$kioJobIds = DB::table('freight_jobs')->where('notes', 'like', 'KIO%')->pluck('id')->all();
$kioTripIds = $kioJobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $kioJobIds)->pluck('id')->all();
$kioPodIds = $kioTripIds === [] ? [] : DB::table('proof_of_deliveries')->whereIn('trip_id', $kioTripIds)->pluck('id')->all();
$kioDocIds = $kioPodIds === [] ? [] : DB::table('documents')->where('morph_type', 'proof_of_delivery')->whereIn('morph_id', $kioPodIds)->pluck('id')->all();
// A run that died between deleting a POD and its documents leaves files nothing can open. They are
// swept here so a crashed run cannot make the next one assert against its ghost.
$kioDocIds = array_values(array_unique(array_merge($kioDocIds, DB::table('documents')
    ->where('morph_type', 'proof_of_delivery')
    ->whereRaw('`morph_id` not in (select `id` from `proof_of_deliveries`)')
    ->pluck('id')
    ->all())));
$kioTruckIds = DB::table('trucks')->where('registration_no', 'like', '%KIO')->pluck('id')->all();
$kioEmployeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-KIO%')->pluck('id')->all();
$kioDriverIds = $kioEmployeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $kioEmployeeIds)->pluck('id')->all();
$kioCostIds = $kioTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $kioTripIds)->pluck('id')->all();
$kioIssueIds = $kioTripIds === [] ? [] : DB::table('fuel_issues')->whereIn('trip_id', $kioTripIds)->pluck('id')->all();
$kioPurchaseIds = array_values(array_unique(array_merge(
    DB::table('fuel_purchases')->where('invoice_no', 'like', 'KIO-%')->pluck('id')->all(),
    $kioIssueIds === [] ? [] : DB::table('fuel_issues')->whereIn('id', $kioIssueIds)->whereNotNull('purchase_id')->pluck('purchase_id')->all(),
)));

purgeKioskJournalEntries(kioskCostJournalEntries($kioCostIds));

foreach ($kioDocIds as $docId) {
    $paths = DB::table('document_versions')->where('document_id', $docId)->pluck('path')->all();

    if ($paths !== []) {
        Storage::disk(DocumentStore::Disk)->delete($paths);
    }
}

DB::table('vehicle_assignments')
    ->where(static fn ($q) => $q->whereIn('truck_id', $kioTruckIds ?: [0])->orWhereIn('driver_id', $kioDriverIds ?: [0]))
    ->delete();
DB::table('odometer_readings')->whereIn('truck_id', $kioTruckIds ?: [0])->delete();
wipeIds('odometer_readings', 'trip_id', $kioTripIds);

wipeIds('document_versions', 'document_id', $kioDocIds);
wipeIds('proof_of_deliveries', 'trip_id', $kioTripIds);
wipeIds('fuel_issues', 'id', $kioIssueIds);
wipeIds('fuel_purchases', 'id', $kioPurchaseIds);
wipeIds('trip_costs', 'trip_id', $kioTripIds);
DB::table('fuel_tanks')->whereIn('truck_id', $kioTruckIds ?: [0])->delete();
DB::table('fuel_stations')->where('name', 'like', 'KIO %')->delete();
wipeIds('trip_access_tokens', 'trip_id', $kioTripIds);
wipeIds('loads', 'trip_id', $kioTripIds);
wipeIds('operational_events', 'aggregate_id', $kioTripIds);
wipeIds('operational_events', 'aggregate_id', $kioJobIds);
wipeIds('chargeables', 'trip_id', $kioTripIds);
wipeIds('trips', 'id', $kioTripIds);
wipeIds('documents', 'id', $kioDocIds);
wipeIds('freight_jobs', 'id', $kioJobIds);

DB::table('drivers')->whereIn('id', $kioDriverIds ?: [0])->delete();
DB::table('employees')->whereIn('id', $kioEmployeeIds ?: [0])->delete();
DB::table('trucks')->whereIn('id', $kioTruckIds ?: [0])->delete();
DB::table('trailers')->where('registration_no', 'like', '%KIO')->delete();
DB::table('routes')->where('code', 'like', 'KIO%')->delete();
DB::table('customers')->where('code', 'like', 'KIO%')->delete();

$alignCounters = static function (string $when): void {
    foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
        $highest = DB::table($table)
            ->where('company_id', 1)
            ->pluck($column)
            ->map(static function (string $number): int {
                $dash = strpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })
            ->max() ?? 0;

        DB::table('number_sequences')
            ->where('company_id', 1)
            ->where('scope', $scope)
            ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
    }

    // This run posts fuel costs and purges their entries, so the journal counter must follow the
    // surviving rows: left ahead, the next post collides on a number nobody ever kept.
    $journal = DB::table('number_sequences')
        ->where('company_id', 1)
        ->where('scope', 'journal_entry')
        ->first();

    if ($journal !== null) {
        $prefix = 'JV-'.$journal->period_key.'-';

        $highestEntry = DB::table('gl_journal_entries')
            ->where('company_id', 1)
            ->where('entry_no', 'like', $prefix.'%')
            ->pluck('entry_no')
            ->map(static fn (string $number): int => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        DB::table('number_sequences')
            ->where('id', $journal->id)
            ->update(['next_value' => $highestEntry + 1, 'updated_at' => now()]);
    }

    echo "  counters aligned to the surviving rows ({$when})\n";
};

$alignCounters('before the run');

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
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

function refuses(string $what, callable $fn, string $expectClass, ?string $messageContains = null): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        if (! $e instanceof $expectClass) {
            failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());

            return;
        }

        if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
            failed($what, "the refusal did not mention '{$messageContains}': ".$e->getMessage());

            return;
        }

        ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 140)));
    }
}

/** The refusal paths force the company context to probe tenant isolation; every one of them puts it back. */
function asCompanyOne(): void
{
    CurrentCompany::force(1);
}

$runStartedAt = Carbon::now();
// The Owner seat is the account the deployment names, not row 1: on a development database row 1
// may have been re-seated, and a harness that signs in the wrong seat fails at the first gated
// write before it tests anything. `FLEET_OWNER_EMAIL` is the documented override.
$owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail();
Auth::login($owner);
CurrentCompany::force((int) $owner->company_id);

$created = [
    'truck_ids' => [], 'trailer_ids' => [], 'employee_ids' => [], 'driver_ids' => [],
    'job_ids' => [], 'trip_ids' => [], 'token_ids' => [], 'pod_ids' => [], 'document_ids' => [],
    'tank_ids' => [], 'station_ids' => [], 'issue_ids' => [], 'cost_ids' => [], 'purchase_ids' => [],
];

/* ------------------------------------------------------------------------- */
echo "== the schema keeps the promises the docs make (live MySQL 9.1) ==\n";

$tokenDdl = (string) ((array) DB::select('show create table trip_access_tokens')[0])['Create Table'];

foreach ([
    'trip_access_tokens_token_hash_unique',
    'trip_access_tokens_id_company_unique',
    'trip_access_tokens_trip_company_fk',
    'trip_access_tokens_secrets_stored',
    'trip_access_tokens_scopes_not_blank',
    'trip_access_tokens_expires_after_issue',
] as $name) {
    check(str_contains($tokenDdl, $name), "trip_access_tokens carries {$name}");
}

$podDdl = (string) ((array) DB::select('show create table proof_of_deliveries')[0])['Create Table'];

foreach ([
    'proof_of_deliveries_client_uuid_unique',
    'proof_of_deliveries_id_company_unique',
    'pod_trip_company_fk',
    'pod_signature_document_fk',
    'pod_capture_token_fk',
    'pod_status_allowed',
    'pod_dated_once_submitted',
    'pod_approved_is_signed',
    'pod_quantity_positive',
    'pod_receiver_not_blank',
] as $name) {
    check(str_contains($podDdl, $name), "proof_of_deliveries carries {$name}");
}

/* ------------------------------------------------------------------------- */
echo "\n== the route is public, throttled, and names the company in the path ==\n";

$kioskRoute = Route::getRoutes()->getByName('kiosk.capture');

check($kioskRoute !== null, 'the kiosk capture route is registered');

if ($kioskRoute !== null) {
    check(str_contains($kioskRoute->uri(), '{company}'), 'the company travels in the path — a driver has no session to resolve one from (the design is fail-closed)');
    check(str_contains($kioskRoute->uri(), '{secret}'), '...and so does the secret, which is the whole credential');
    check(in_array('throttle:kiosk', $kioskRoute->middleware(), true), 'the attempt is rate-limited before the token is even looked up');
    check(in_array('kiosk', $kioskRoute->middleware(), true), 'the kiosk middleware establishes the tenant and stamps last_used_at');
    check(! in_array('auth', $kioskRoute->middleware(), true), 'and no auth middleware: a driver has no account (ADR-0006)');
}

check((int) config('fleet.kiosk.pin_attempts_per_minute') > 0, 'the PIN attempt budget is a configured number (fleet.kiosk.pin_attempts_per_minute)');
check((int) config('fleet.kiosk.ttl_hours_after_trip_end') > 0, 'and so is the grace after the trip ends (fleet.kiosk.ttl_hours_after_trip_end)');

$fuelRoute = Route::getRoutes()->getByName('kiosk.fuel');

check($fuelRoute!== null, 'the driver fuel screen has its own route');

if ($fuelRoute !== null) {
    check(str_contains($fuelRoute->uri(), '{company}') && str_contains($fuelRoute->uri(), '{secret}'), '...with the company and the secret in the path, same as the POD door');
    check(in_array('throttle:kiosk', $fuelRoute->middleware(), true) && in_array('kiosk', $fuelRoute->middleware(), true), '...behind the same attempt throttle and tenant middleware');
    check(! in_array('auth', $fuelRoute->middleware(), true), '...and no auth middleware: a driver has no account (ADR-0006)');
}

/* ------------------------------------------------------------------------- */
echo "\n== the fuel issue row names the phone that wrote it ==\n";

$issueDdl = (string) ((array) DB::select('show create table fuel_issues')[0])['Create Table'];

check(str_contains($issueDdl, 'fuel_issues_trip_access_token_fk'), 'a kiosk issue points at the token row that wrote it');
check(str_contains($issueDdl, 'fuel_issues_kiosk_names_its_token'), '...and the CHECK pairs `source = kiosk` with a named token in both directions');

/* ------------------------------------------------------------------------- */
echo "\n== fixtures: job → trip → assigned → in_transit → arrived ==\n";

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'KIO Verify Customer', code: 'KIO-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'KIO-GBE-WDH',
    origin: 'Gaborone',
    destination: 'Windhoek',
    originCountryCode: 'BW',
    destinationCountryCode: 'NA',
    distanceKm: '750',
));

$baseCurrency = CurrentCompany::model()->base_currency_code;

/**
 * One fixture trip, walked to `arrived` through the real doors.
 *
 * @return array{0: Trip, 1: array{0: mixed, 1: mixed, 2: mixed}}
 */
$arrivedTrip = static function (string $suffix, int $odometer, ?int $mainTankLitres = null) use (&$created, $customer, $route, $baseCurrency): array {
    $truck = (app(CreateTruck::class))(new TruckDto(
        registrationNo: 'B 8'.$suffix.' KIO',
        fleetNo: 'T-KIO'.$suffix,
        vin: 'VINKIO00000000000000'.$suffix,
        make: 'Probe',
        model: 'Kiosk',
        odometerKm: $odometer,
        mainTankLitres: $mainTankLitres,
    ));
    $created['truck_ids'][] = $truck->id;

    $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 8'.$suffix.' KIO', type: 'flatbed', capacityTonnes: 30.0));
    $created['trailer_ids'][] = $trailer->id;

    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-KIO00'.$suffix, name: 'KIO Verify Driver '.$suffix, hiredOn: '2024-01-10'));
    $created['employee_ids'][] = $employee->id;

    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id,
        licenceNo: 'DL-KIO00'.$suffix,
        licenceCode: 'EC',
        licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        allowanceRateBase: 35_000,
        kioskEnabled: true,
    ));
    $created['driver_ids'][] = $driver->id;

    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id,
        routeId: $route->id,
        commodity: 'packaged goods',
        tonnes: 28.0,
        requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
        notes: 'KIO fixture job '.$suffix,
    ));
    $created['job_ids'][] = $job->id;

    $job = (app(QuoteJob::class))($job, new JobQuoteDto(
        rateType: RouteRateType::PerTrip,
        amountMajor: '120000',
        currencyCode: $baseCurrency,
    ), 1);

    $job = (app(AcceptJob::class))($job, 1);

    $trip = (app(CreateTrip::class))($job, 1);
    $created['trip_ids'][] = $trip->id;

    $trip = (app(PlanTrip::class))($trip, new TripDto(
        jobId: $job->id,
        truckId: $truck->id,
        driverId: $driver->id,
        trailerId: $trailer->id,
        plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
    ), 1);

    $trip = (app(DispatchTrip::class))($trip, 1);
    $trip = (app(DepartTrip::class))($trip, $odometer + 1, null, 1);
    $trip = (app(AdvanceTrip::class))($trip, TripStatus::InTransit, 1);

    return [$trip, [$truck, $trailer, $driver]];
};

/** @var Trip $trip */
[$trip, $crew] = $arrivedTrip('1', 200_000);
$driver = $crew[2];

check($trip->status === TripStatus::InTransit, 'the fixture trip is on the road ('.$trip->trip_no.')');

/* ------------------------------------------------------------------------- */
echo "\n== issuing a token: hashes out, plain values once ==\n";

$issue = app(IssueTripAccessToken::class);

refuses('a token with no scopes is refused', fn () => $issue($trip, (int) $driver->id, [], 1), KioskScopeUnavailable::class, 'opens nothing');
refuses('a scope this build cannot honour is refused at issue time', fn () => $issue($trip, (int) $driver->id, ['odometer'], 1), KioskScopeUnavailable::class, 'not captured through the kiosk yet');

$otherDriverId = (int) DB::table('drivers')->where('company_id', 1)->where('id', '<>', $driver->id)->value('id');
refuses('a token for somebody other than the trip\'s driver is refused', fn () => $issue($trip, $otherDriverId, ['pod'], 1), InvalidArgumentException::class, 'crewed by driver');

$driver->forceFill(['kiosk_enabled' => false])->save();
refuses('a driver not enabled for field capture is refused', fn () => $issue($trip, (int) $driver->id, ['pod'], 1), InvalidArgumentException::class, 'not enabled for field capture');
$driver->forceFill(['kiosk_enabled' => true])->save();

$first = $issue($trip, (int) $driver->id, ['pod'], 1);
$token = $first['token'];
$secret = $first['secret'];
$pin = $first['pin'];

$created['token_ids'][] = $token->id;

check(ctype_xdigit($secret) && strlen($secret) === 48, 'the secret is 24 random bytes, hex-encoded ('.strlen($secret).' chars)');
check(preg_match('/\A\d{6}\z/', $pin) === 1, 'the PIN is six digits ('.$pin.')');
check(str_contains($first['url'], '/kiosk/1/'.$secret), 'the URL names the company and carries the secret ('.$first['url'].')');

$row = DB::table('trip_access_tokens')->where('id', $token->id)->first();
$raw = json_encode((array) $row);

check($raw !== false && ! str_contains($raw, $secret), 'the database row contains no copy of the secret');
check($raw !== false && ! str_contains($raw, $pin.'|'), '...and no unbound copy of the PIN');
check($row->token_hash === hash('sha256', $secret), 'token_hash is SHA-256 of the secret');
check($row->pin_hash === hash('sha256', $pin.'|'.$secret), 'pin_hash is SHA-256 of PIN bounded to the secret — a lifted PIN is useless against another trip');
check($row->scopes === 'pod', 'the scope is stored as the vocabulary\'s value');

$serialised = json_encode($token->toArray());
check($serialised !== false && ! str_contains($serialised, (string) $row->token_hash), 'the model hides token_hash from serialisation');
check($serialised !== false && ! str_contains($serialised, (string) $row->pin_hash), '...and pin_hash too');

$issuedEvent = DB::table('security_events')
    ->where('kind', 'kiosk_token_issued')
    ->where('user_id', 1)
    ->where('happened_at', '>=', $runStartedAt)
    ->get();

check($issuedEvent->count() >= 1, 'the design records the issuance on the security trail ('.$issuedEvent->count().' row)');
check(
    $issuedEvent->contains(static fn ($event): bool => str_contains((string) $event->meta, $token->id)),
    '...and its meta names the token row so "who could write that POD" has an answer'
);

check($token->isLive(), 'a freshly issued token is live');
check($token->closedBecause() === null, '...and reports no closing reason');

/* ------------------------------------------------------------------------- */
echo "\n== redemption: the secret is the only way in, and it says nothing when it fails ==\n";

$redeem = app(RedeemKioskToken::class);

$live = $redeem->fromSecret(1, $secret, '10.11.12.13');
check($live->id === $token->id, 'the secret resolves the token inside the company in its path');
check($live->last_ip === '10.11.12.13', '...and stamps the address it was used from');
check($live->last_used_at !== null, '...and the moment it was used');

refuses('a wrong secret resolves nothing', fn () => $redeem->fromSecret(1, str_repeat('ab', 24), '10.11.12.13'), InvalidArgumentException::class);
asCompanyOne();

refuses('the same secret in another company resolves nothing — tenancy is the path, not a hint', fn () => $redeem->fromSecret(2, $secret, '10.11.12.13'), InvalidArgumentException::class);
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== capture refuses everything that is not evidence ==\n";

$capture = app(CaptureProofOfDelivery::class);

$pngPath = storage_path('app/kio-signature-fixture.png');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

if ($png === false) {
    throw new RuntimeException('the PNG fixture could not be decoded');
}

file_put_contents($pngPath, $png);

$signature = static fn (): DocumentInput => DocumentInput::fromPath($pngPath, 'image/png', 'signature.png');
$photo = static fn (): DocumentInput => DocumentInput::fromPath($pngPath, 'image/png', 'load.png');

$facts = [
    'receiver_name' => 'KIO Receiver',
    'quantity_tonnes' => '27.55',
    'exceptions' => [],
    'none_declared' => true,
];

refuses(
    'a proof cannot be captured before the truck has arrived',
    fn () => $capture($token, $secret, $pin, $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'nothing to prove delivery of'
);

check(DB::table('proof_of_deliveries')->where('trip_id', $trip->id)->count() === 0, '...and nothing was written');

$trip = (app(AdvanceTrip::class))($trip, TripStatus::Arrived, 1);
check($trip->status === TripStatus::Arrived, 'the truck arrives ('.$trip->trip_no.')');

refuses(
    'the wrong PIN is refused',
    fn () => $capture($token, $secret, $pin === '000000' ? '111111' : '000000', $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'PIN'
);

refuses(
    'a wrong secret is refused even with the right PIN',
    fn () => $capture($token, str_repeat('cd', 24), $pin, $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class
);

refuses(
    'a proof without a photo is refused — the design names signature AND photo',
    fn () => $capture($token, $secret, $pin, $facts, ['signature' => $signature()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'no photo'
);

refuses(
    'a proof without a signature is refused',
    fn () => $capture($token, $secret, $pin, $facts, ['photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'no signature'
);

$unanswered = $facts;
unset($unanswered['exceptions']);
refuses(
    'an unanswered exceptions question is not the same as "nothing went wrong"',
    fn () => $capture($token, $secret, $pin, $unanswered, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'unanswered'
);

$silent = $facts;
$silent['none_declared'] = false;
refuses(
    '...and an empty list without the acknowledgement is refused too',
    fn () => $capture($token, $secret, $pin, $silent, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'empty answer is not the same'
);

check(DB::table('proof_of_deliveries')->where('trip_id', $trip->id)->count() === 0, 'every refusal above wrote no POD row');
check(DB::table('documents')->where('title', 'like', $trip->trip_no.' %')->count() === 0, '...and uploaded no document — the gate is asked before the first byte is stored');

/* ------------------------------------------------------------------------- */
echo "\n== the offline retry: one client_uuid, one delivery note ==\n";

$clientUuid = (string) Str::uuid();

$pod = $capture($token, $secret, $pin, $facts, ['signature' => $signature(), 'photo' => $photo()], $clientUuid);
$created['pod_ids'][] = $pod->id;

check($pod->status === PodStatus::Submitted, 'a complete proof lands as submitted (waiting for the office)');
check($pod->receiver_name === 'KIO Receiver', '...with the receiver named');
check((float) $pod->quantity_received_tonnes_10_2 === 27.55, '...and the quantity received, in the 10^2 column');
check($pod->signature_document_id!== null, '...and the signature filed as a document (the design keeps its retention)');
check($pod->exceptions === [], '...and "nothing went wrong" stored as an empty list, not null');

$documents = DB::table('documents')->where('morph_type', 'proof_of_delivery')->where('morph_id', $pod->id)->get();
check($documents->count() === 2, 'two documents were filed against the POD: the signature and the photo ('.$documents->count().')');
check($documents->every(static fn ($document): bool => $document->type === DocumentType::Pod->value), '...both of the pod type');

$versions = DB::table('document_versions')->whereIn('document_id', $documents->pluck('id')->all())->get();
check($versions->count() === 2 && $versions->every(static fn ($version): bool => $version->mime === 'image/png'), '...each with one current version whose MIME was sniffed, not trusted');

$trip->refresh();
check($trip->status === TripStatus::PodPending, 'the capture walked arrived → pod_pending');

$kioskEvent = DB::table('operational_events')
    ->where('aggregate_type', 'trip')
    ->where('aggregate_id', $trip->id)
    ->where('to_state', 'pod_pending')
    ->first();

check($kioskEvent!== null && $kioskEvent->actor_kind === ActorKind::Kiosk->value, '...and the trail attributes it to the kiosk, not to a person');

$podIdsBefore = DB::table('proof_of_deliveries')->where('trip_id', $trip->id)->pluck('id')->all();
$docIdsBefore = DB::table('documents')->where('morph_type', 'proof_of_delivery')->pluck('id')->all();

$retry = $capture($token, $secret, $pin, $facts, ['signature' => $signature(), 'photo' => $photo()], $clientUuid);

check($retry->id === $pod->id, 'the same client_uuid returns the same row instead of a second delivery note');
check(DB::table('proof_of_deliveries')->where('trip_id', $trip->id)->pluck('id')->all() === $podIdsBefore, '...and the POD table is unchanged by the retry');
check(DB::table('documents')->where('morph_type', 'proof_of_delivery')->pluck('id')->all() === $docIdsBefore, '...and no duplicate signature or photo was filed');

/* ------------------------------------------------------------------------- */
echo "\n== the driver cannot deliver: that is the office's decision ==\n";

refuses(
    'a kiosk actor cannot move pod_pending → delivered',
    fn () => (app(AdvanceTrip::class))($trip->fresh(), TripStatus::Delivered, null, ActorKind::Kiosk),
    InvalidArgumentException::class,
    'office decision'
);

check($trip->fresh()->status === TripStatus::PodPending, '...and the trip stayed where the driver left it');

check(Gate::allows('pod.approve'), 'the Owner holds pod.approve');

$approved = (app(ApprovePod::class))($pod->fresh(), 1);

check($approved->status === PodStatus::Approved, 'approving the proof marks it approved');
check($trip->fresh()->status === TripStatus::Delivered, '...and moves the trip to delivered in the same transaction');

refuses(
    'a proof cannot be approved twice',
    fn () => (app(ApprovePod::class))($pod->fresh(), 1),
    InvalidArgumentException::class,
    'already approved'
);

refuses(
    'and a delivered trip is past the road: capture is refused',
    fn () => $capture($token, $secret, $pin, $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid()),
    InvalidArgumentException::class,
    'nothing to prove delivery of'
);

/* ------------------------------------------------------------------------- */
echo "\n== rejection sends the paper back, not the truck ==\n";

[$tripB, $crewB] = $arrivedTrip('2', 210_000, 250);
$driverB = $crewB[2];

$tripB = (app(AdvanceTrip::class))($tripB, TripStatus::Arrived, 1);

check($tripB->status === TripStatus::Arrived, 'the second fixture arrived ('.$tripB->trip_no.')');

$second = $issue($tripB, (int) $driverB->id, ['pod'], 1);
$created['token_ids'][] = $second['token']->id;

$podB = $capture($second['token'], $second['secret'], $second['pin'], $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid());
$created['pod_ids'][] = $podB->id;

check($tripB->fresh()->status === TripStatus::PodPending, 'the second trip is waiting on the office');

$rejected = (app(RejectPod::class))($podB->fresh(), 'the photo shows the wrong pallet');

check($rejected->status === PodStatus::Rejected, 'rejecting marks the proof rejected');
check($tripB->fresh()->status === TripStatus::PodPending, '...and leaves the trip at pod_pending — the delivery is unproven, not unmade');
check(($rejected->exceptions['rejected_for'] ?? null) === 'the photo shows the wrong pallet', '...with the reason kept on the row for the driver to act on');

refuses(
    'a rejection needs a reason',
    fn () => (app(RejectPod::class))($podB->fresh(), '   '),
    InvalidArgumentException::class,
    'Say what is wrong'
);

$resubmitted = $capture($second['token'], $second['secret'], $second['pin'], $facts, ['signature' => $signature(), 'photo' => $photo()], (string) Str::uuid());
$created['pod_ids'][] = $resubmitted->id;

check($resubmitted->id !== $podB->id, 'the driver can submit again from pod_pending, as a new row');
check($resubmitted->status === PodStatus::Submitted, '...and it waits for the office');
check(DB::table('proof_of_deliveries')->where('trip_id', $tripB->id)->count() === 2, '...leaving both attempts on file — the rejected one is evidence too');

$approvedB = (app(ApprovePod::class))($resubmitted->fresh(), 1);
check($approvedB->status === PodStatus::Approved && $tripB->fresh()->status === TripStatus::Delivered, 'the replacement is approved and the trip delivered');

/* ------------------------------------------------------------------------- */
echo "\n== 3g the driver's fuel screen: one slip, one issue, one cost ==\n";

[$tripC, $crewC] = $arrivedTrip('3', 300_000, 300);
$truckC = $crewC[0];
$driverC = $crewC[2];

$mainTank = (app(CreateFuelTank::class))($truckC->id, FuelTankKind::Main);
$created['tank_ids'][] = $mainTank->id;

$pump = (app(CreateFuelStation::class))('KIO Pump Gaborone', 'Gaborone');
$created['station_ids'][] = $pump->id;

// A tank on another truck, so the "fuel goes into this trip's own truck" refusal has something to
// name — the other truck's capacity was declared when the second fixture was built.
$otherTank = (app(CreateFuelTank::class))($crewB[0]->id, FuelTankKind::Main);
$created['tank_ids'][] = $otherTank->id;

$fuelToken = $issue($tripC, (int) $driverC->id, ['fuel'], 1);
$created['token_ids'][] = $fuelToken['token']->id;

check($fuelToken['token']->hasScope(TripAccessScope::Fuel), 'a link issued for the fuel screen carries the fuel scope');
check(! $fuelToken['token']->hasScope(TripAccessScope::Pod), '...and only that scope: the dispatcher chose the narrow door');

$recordFuel = app(RecordKioskFuelIssue::class);

// 250.00 L at BWP 19.75 = BWP 4 937.50 → 493 750 minor units.
$dto = static fn (?int $tankId = null): KioskFuelDto => new KioskFuelDto(
    tankId: $tankId ?? $mainTank->id,
    litresX100: 25_000,
    pricePerLitreMinor: 1_975,
    odometerKm: 300_500,
    occurredOn: Carbon::now(),
    stationId: $pump->id,
    invoiceNo: 'KIO-CASH-1',
    note: 'KIO cash slip',
);

refuses(
    'the wrong PIN is refused at the fuel door',
    fn () => $recordFuel($fuelToken['token'], $fuelToken['secret'], $fuelToken['pin'] === '000000' ? '111111' : '000000', $dto(), (string) Str::uuid()),
    InvalidArgumentException::class,
    'PIN'
);

$podOnly = $issue($tripC, (int) $driverC->id, ['pod'], 1);
$created['token_ids'][] = $podOnly['token']->id;

refuses(
    'a pod-only link cannot reach the fuel screen — scopes are narrow',
    fn () => $recordFuel($podOnly['token'], $podOnly['secret'], $podOnly['pin'], $dto(), (string) Str::uuid()),
    InvalidArgumentException::class,
    'does not carry the fuel'
);

$wrongTankUuid = (string) Str::uuid();

refuses(
    'another truck\'s tank is refused',
    fn () => $recordFuel($fuelToken['token'], $fuelToken['secret'], $fuelToken['pin'], $dto($otherTank->id), $wrongTankUuid),
    InvalidArgumentException::class,
    'not an active tank'
);

check((int) DB::table('fuel_purchases')->where('source_uuid', $wrongTankUuid)->count() === 0, '...and the slip that attempt wrote is rolled back with the issue');

$clientUuid = (string) Str::uuid();
$kioskIssue = $recordFuel($fuelToken['token'], $fuelToken['secret'], $fuelToken['pin'], $dto(), $clientUuid);
$created['issue_ids'][] = $kioskIssue->id;
$created['cost_ids'][] = $kioskIssue->trip_cost_id;
$created['purchase_ids'][] = $kioskIssue->purchase_id;

check($kioskIssue->source === FuelIssueSource::Kiosk, 'the issue says it was written at the kiosk');
check($kioskIssue->status === FuelIssueStatus::Posted, '...and posted the cost rather than waiting for the office');
check($kioskIssue->trip_access_token_id === $fuelToken['token']->id, '...naming the token row — attribution survives the shared phone');
check($kioskIssue->recorded_by === null, '...with no user recorded, because a driver has no account (ADR-0006)');

$purchase = DB::table('fuel_purchases')->where('id', $kioskIssue->purchase_id)->first();

check($purchase !== null && (int) $purchase->litres === 25_000 && (int) $purchase->price_per_litre_minor === 1_975, 'the slip is recorded at the litres and price on the driver\'s receipt');
check($purchase !== null && (int) $purchase->amount === 493_750, '...its amount is litres × price: BWP 4 937.50');
check($purchase!== null && (bool) $purchase->posted_to_ledger === false, '...and the slip is not posted as a payable — the issue carries the money');

$cost = TripCost::query()->findOrFail($kioskIssue->trip_cost_id);

check($cost->cost_type === TripCostType::Fuel, 'the issue raised a trip cost of type fuel');
check($cost->paid_with === TripCostPayment::PettyCash, '...settled as petty cash, because the driver paid at the pump');
check($cost->base_amount === 493_750, '...for the slip\'s own figure, to the minor unit');

$entry = DB::table('gl_journal_entries')->where('source_type', 'trip_cost')->where('source_id', $cost->id)->first();

check($entry !== null, 'the cost has a journal entry that names it');

if ($entry !== null) {
    $lines = DB::table('gl_journal_lines')->where('entry_id', $entry->id)->get()->all();
    $chart = app(ChartOfAccounts::class)->byCode();
    $debit = collect($lines)->firstWhere('direction', 'debit');
    $credit = collect($lines)->firstWhere('direction', 'credit');

    check($debit !== null && (int) $debit->account_id === $chart['5100']->id && (int) $debit->amount_base === 493_750, '...debiting 5100 fuel by the issued cost');
    check($credit !== null && (int) $credit->account_id === $chart['1110']->id && (int) $credit->amount_base === 493_750, '...and crediting 1110 petty cash, the money the driver spent');
    check($debit!== null && $debit->truck_id === $truckC->id && $debit->trip_id === $tripC->id, '...with the truck and trip dimensions account 5100 requires');
}

$reading = DB::table('odometer_readings')->where('trip_id', $tripC->id)->where('odometer_km', 300_500)->first();

check($reading !== null && $reading->source === 'fuel_issue', 'the dashboard figure becomes an odometer reading marked as coming from the pump');
check((int) DB::table('trucks')->where('id', $truckC->id)->value('odometer_km') === 300_500, '...and moves the truck\'s projection to it');

$rollup = (int) DB::table('trips')->where('id', $tripC->id)->value('fuel_cost_base');

check($rollup === 493_750, 'the rollup\'s fuel bucket equals the posted cost ('.$rollup.')');

$issueEvent = DB::table('operational_events')
    ->where('aggregate_type', 'fuel_issue')
    ->where('aggregate_id', $kioskIssue->id)
    ->where('to_state', 'posted')
    ->first();

$context = $issueEvent === null ? [] : (json_decode((string) $issueEvent->context, true) ?: []);

check($issueEvent!== null && $issueEvent->actor_kind === ActorKind::Kiosk->value, 'the recorded → posted move is on the trail attributed to the kiosk');
check(($context['trip_access_token_id'] ?? null) === $fuelToken['token']->id, '...and its context names the token, so the trail and the row agree');

$fuelAuthority = KioskAuthority::verified($fuelToken['token'], $fuelToken['secret'], $fuelToken['pin'], TripAccessScope::Fuel);

refuses(
    'a fuel link cannot pour its litres into a different trip',
    fn () => (app(RecordFuelIssue::class))($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 1_000,
        odometerKm: 300_600,
        occurredOn: Carbon::now(),
        purchaseId: $kioskIssue->purchase_id,
        source: FuelIssueSource::Kiosk,
    ), null, $fuelAuthority),
    InvalidArgumentException::class,
    'another trip'
);

$fuelRetry = $recordFuel($fuelToken['token'], $fuelToken['secret'], $fuelToken['pin'], $dto(), $clientUuid);

check($fuelRetry->id === $kioskIssue->id, 'the same client_uuid returns the litre already recorded instead of buying it twice');
check((int) DB::table('fuel_issues')->where('trip_id', $tripC->id)->count() === 1, '...and the trip holds exactly one issue');
check((int) DB::table('fuel_purchases')->where('invoice_no', 'KIO-CASH-1')->count() === 1, '...and exactly one slip');

/* ------------------------------------------------------------------------- */
echo "\n== revocation and expiry: two reasons a link dies, kept in two columns ==\n";

$revoke = app(RevokeTripAccessToken::class);
$revoked = $revoke($token->fresh());

check($revoked->revoked === true, 'revoking flips the flag');
check($revoked->closedBecause() === 'revoked', '...and the row says why the link is closed');
check($revoked->expires_at->isFuture(), '...without touching the expiry — the two reasons stay separate');

refuses('a revoked secret no longer resolves', fn () => $redeem->fromSecret(1, $secret, '10.11.12.13'), InvalidArgumentException::class, 'closed');
asCompanyOne();

$revokedEvent = DB::table('security_events')
    ->where('kind', 'kiosk_token_revoked')
    ->where('happened_at', '>=', $runStartedAt)
    ->get();

check($revokedEvent->contains(static fn ($event): bool => str_contains((string) $event->meta, $token->id)), 'revocation is on the security trail with the token it closed');

$expiringIssue = $issue($tripB, (int) $driverB->id, ['pod'], 1);
$expiring = $expiringIssue['token'];
$created['token_ids'][] = $expiring->id;

$expiring->forceFill([
    'created_at' => Carbon::now()->subDays(2),
    'expires_at' => Carbon::now()->subHour(),
])->save();

check($expiring->fresh()->closedBecause() === 'expired', 'a token past its horizon reports "expired"');
refuses('...and its secret no longer resolves', fn () => $redeem->fromSecret(1, $expiringIssue['secret'], '10.11.12.13'), InvalidArgumentException::class);
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== a link re-issued for a trip that ended long ago is born live, not expired ==\n";

// The grace is measured from the trip's own end, so §10.2's rejection loop reaching the office days after
// the truck arrived would ask for an expiry already in the past — and
// `trip_access_tokens_expires_after_issue` refuses that insert. The issue still has to work.
DB::table('trips')->where('id', $tripB->id)->update([
    'departed_at' => Carbon::now()->subDays(5),
    'arrived_at' => Carbon::now()->subDays(4),
]);

$late = $issue($tripB->fresh(), (int) $driverB->id, ['pod'], 1);
$created['token_ids'][] = $late['token']->id;

check((int) DB::table('trip_access_tokens')->where('id', $late['token']->id)->count() === 1, 'the database takes the insert — the expiry-after-issue CHECK is not tripped');
check($late['token']->expires_at->isFuture(), '...because the horizon runs from the issue, not the stale arrival ('.$late['token']->expires_at->toIso8601String().')');
check($late['token']->isLive(), '...so the re-issued link opens the capture form');
refuses('the two-day-old link stays shut — the new door did not revive it', fn () => $redeem->fromSecret(1, $expiringIssue['secret'], '10.11.12.13'), InvalidArgumentException::class);
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== cleanup, and the proof it worked ==\n";

$runTripIds = array_values(array_unique(array_merge(
    $created['trip_ids'],
    DB::table('trips')->whereIn('job_id', $created['job_ids'] ?: ['0'])->pluck('id')->all(),
)));
$runPodIds = array_values(array_unique(array_merge(
    $created['pod_ids'],
    $runTripIds === [] ? [] : DB::table('proof_of_deliveries')->whereIn('trip_id', $runTripIds)->pluck('id')->all(),
)));
$runDocIds = $runPodIds === [] ? [] : DB::table('documents')->where('morph_type', 'proof_of_delivery')->whereIn('morph_id', $runPodIds)->pluck('id')->all();
$runTokenIds = array_values(array_unique(array_merge(
    $created['token_ids'],
    $runTripIds === [] ? [] : DB::table('trip_access_tokens')->whereIn('trip_id', $runTripIds)->pluck('id')->all(),
)));
$runTruckIds = array_values(array_unique(array_merge($created['truck_ids'], DB::table('trucks')->where('registration_no', 'like', '%KIO')->pluck('id')->all())));
$runDriverIds = array_values(array_unique(array_merge(
    $created['driver_ids'],
    DB::table('drivers')->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-KIO%')->pluck('id')->all() ?: [0])->pluck('id')->all(),
)));
$runCostIds = $runTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $runTripIds)->pluck('id')->all();
$runIssueIds = $runTripIds === [] ? [] : DB::table('fuel_issues')->whereIn('trip_id', $runTripIds)->pluck('id')->all();
$runPurchaseIds = array_values(array_unique(array_merge(
    $created['purchase_ids'],
    DB::table('fuel_purchases')->where('invoice_no', 'like', 'KIO-%')->pluck('id')->all(),
    $runIssueIds === [] ? [] : DB::table('fuel_issues')->whereIn('id', $runIssueIds)->whereNotNull('purchase_id')->pluck('purchase_id')->all(),
)));
$runTankIds = array_values(array_unique(array_merge(
    $created['tank_ids'],
    DB::table('fuel_tanks')->whereIn('truck_id', $runTruckIds ?: [0])->pluck('id')->all(),
)));

purgeKioskJournalEntries(kioskCostJournalEntries($runCostIds));

foreach ($runDocIds as $docId) {
    $paths = DB::table('document_versions')->where('document_id', $docId)->pluck('path')->all();

    if ($paths !== []) {
        Storage::disk(DocumentStore::Disk)->delete($paths);
    }
}

// Children before parents: the POD points at a document with ON DELETE RESTRICT, and at a token that
// cascades — deleting the POD first is what makes both directions safe. The readings and reservations
// point at the trip and the truck both, so they go before either.
DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $runTruckIds ?: [0])->orWhereIn('driver_id', $runDriverIds ?: [0]))->delete();
DB::table('odometer_readings')->whereIn('truck_id', $runTruckIds ?: [0])->delete();
wipeIds('odometer_readings', 'trip_id', $runTripIds);
wipeIds('document_versions', 'document_id', $runDocIds);
wipeIds('proof_of_deliveries', 'trip_id', $runTripIds);
wipeIds('fuel_issues', 'id', $runIssueIds);
wipeIds('fuel_purchases', 'id', $runPurchaseIds);
wipeIds('trip_costs', 'trip_id', $runTripIds);
wipeIds('fuel_tanks', 'id', $runTankIds);
DB::table('fuel_stations')->where('name', 'like', 'KIO %')->delete();
wipeIds('trip_access_tokens', 'trip_id', $runTripIds);
wipeIds('loads', 'trip_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $created['job_ids']);
wipeIds('chargeables', 'trip_id', $runTripIds);
wipeIds('trips', 'id', $runTripIds);
wipeIds('documents', 'id', $runDocIds);
wipeIds('freight_jobs', 'id', $created['job_ids']);

DB::table('drivers')->whereIn('id', $runDriverIds ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $runTruckIds ?: [0])->delete();
DB::table('trailers')->whereIn('id', $created['trailer_ids'] ?: [0])->delete();
DB::table('routes')->where('code', 'like', 'KIO%')->delete();
DB::table('customers')->where('code', 'like', 'KIO%')->delete();

foreach ([Trip::class, Job::class, TripAccessToken::class, ProofOfDelivery::class, Document::class, TripCost::class, FuelIssue::class] as $type) {
    DB::table('audits')->where('auditable_type', $type)->where('created_at', '>=', $runStartedAt)->delete();
}

// The trail rows this run wrote, by kind and window: a fixture's audit of its own token is not evidence
// about the company, and leaving it would make the security screen report doors nobody opened.
DB::table('security_events')
    ->whereIn('kind', ['kiosk_token_issued', 'kiosk_token_revoked', 'document_uploaded'])
    ->where('happened_at', '>=', $runStartedAt)
    ->delete();

@unlink($pngPath);

$alignCounters('after cleanup');

$afterExit = Artisan::call('documents:number-integrity');
$afterOutput = Artisan::output();
check($afterExit === 0, 'the counters and the surviving rows agree once the fixtures are gone', mb_substr(str_replace("\n", ' ', $afterOutput), 0, 200));

$leftovers = [
    'trips' => DB::table('trips')->whereIn('id', $runTripIds ?: [0])->count(),
    'tokens' => DB::table('trip_access_tokens')->whereIn('id', $runTokenIds ?: [0])->count(),
    'proofs' => DB::table('proof_of_deliveries')->whereIn('id', $runPodIds ?: [0])->count(),
    'documents' => DB::table('documents')->whereIn('id', $runDocIds ?: [0])->count(),
    'trucks' => DB::table('trucks')->where('registration_no', 'like', '%KIO')->count(),
    'trailers' => DB::table('trailers')->where('registration_no', 'like', '%KIO')->count(),
    'employees' => DB::table('employees')->where('employee_no', 'like', 'EMP-KIO%')->count(),
    'drivers' => DB::table('drivers')->whereIn('id', $runDriverIds ?: [0])->count(),
    'customers' => DB::table('customers')->where('code', 'like', 'KIO%')->count(),
    'routes' => DB::table('routes')->where('code', 'like', 'KIO%')->count(),
    'fuel stations' => DB::table('fuel_stations')->where('name', 'like', 'KIO %')->count(),
    'assignments' => DB::table('vehicle_assignments')->whereIn('trip_id', $runTripIds ?: [0])->count(),
    'fuel issues' => DB::table('fuel_issues')->whereIn('id', $runIssueIds ?: [0])->count(),
    'fuel purchases' => DB::table('fuel_purchases')->whereIn('id', $runPurchaseIds ?: [0])->count(),
    'trip costs' => DB::table('trip_costs')->whereIn('id', $runCostIds ?: [0])->count(),
    'fuel tanks' => DB::table('fuel_tanks')->whereIn('id', $runTankIds ?: [0])->count(),
    'journal entries' => $runCostIds === [] ? 0 : DB::table('gl_journal_entries')->where('source_type', 'trip_cost')->whereIn('source_id', array_map('strval', $runCostIds))->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
