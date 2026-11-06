<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CaptureBorderCost;
use App\Domain\Operations\Actions\CompleteTrip;
use App\Domain\Operations\Actions\CreateBorderPost;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\FinishBorderCosts;
use App\Domain\Operations\Actions\MarkCrossingEntered;
use App\Domain\Operations\Actions\MarkCrossingExited;
use App\Domain\Operations\Actions\PlanBorderCrossing;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\BorderCostKind;
use App\Domain\Operations\BorderCrossingDirection;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Data\BorderCostDto;
use App\Domain\Operations\Data\BorderPostDto;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Models\BorderCost;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\BorderPost;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 2 slice 2f verification — border gates, crossings and the charges captured at them
 *.
 *
 * It proves the sentences the slice turns on:
 *
 * - a gate is master data, registered once per country, with a corridor country and real coordinates;
 * - a crossing is a trip fact with the machine: `planned → entered → exited → costs_captured`,
 *   each edge guarded, each move on the trail, and the two Phase 6 states out of this build's reach;
 * - every charge at a gate posts its own trip cost (type `border`, account 5400) and copies the
 *   eight columns from that posting, so the gate's screen and the ledger cannot disagree;
 * - the rollup's border bucket equals the charges that were posted;
 * - the register is company-scoped and the dispatcher's `crossborder.view` is not `crossborder.manage`.
 *
 * It writes to the development database on the live MySQL 9.1 instance (docs/DEV-DATABASE.md) inside the
 * `XBD` fixture namespace, lifts the barrier only to delete its own journal entries, and asserts that
 * everything it created is gone and the barrier is down again.
 *
 * Run: php scripts/verify-crossing.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/** Delete rows by an id list, refusing to run on an empty list (the integer/ULID comparison). */
function wipeIds(string $table, string $column, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    return DB::table($table)->whereIn($column, $ids)->delete();
}

/** @return list<string> */
function costJournalEntries(array $costIds): array
{
    if ($costIds === []) {
        return [];
    }

    return DB::table('gl_journal_entries')
        ->where('source_type', 'trip_cost')
        ->whereIn('source_id', $costIds)
        ->pluck('id')
        ->all();
}

/** @param list<string> $entryIds */
function purgeJournalEntries(array $entryIds, string $why): void
{
    if ($entryIds === []) {
        return;
    }

    LedgerBarrier::withLiftedGuard($why, null, function () use ($entryIds): void {
        DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
    });
}

/*
 * Pre-clean: a run that died halfway leaves crossings, charges, trips and — because the design refuses the
 * delete — journal entries the next run would count as fixtures. Everything this file writes is inside
 * the `XBD` namespace, so the sweep is exact. Crossings before trips, charges before crossings.
 */
$xbdJobIds = DB::table('freight_jobs')->where('notes', 'like', 'XBD%')->pluck('id')->all();
$xbdTripIds = $xbdJobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $xbdJobIds)->pluck('id')->all();
$xbdCrossingIds = $xbdTripIds === [] ? [] : DB::table('border_crossings')->whereIn('trip_id', $xbdTripIds)->pluck('id')->all();
$xbdCostIds = $xbdTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $xbdTripIds)->pluck('id')->all();
$xbdTruckIds = DB::table('trucks')->where('registration_no', 'like', '%XBD')->pluck('id')->all();
$xbdEmployeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-XBD%')->pluck('id')->all();
$xbdDriverIds = $xbdEmployeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $xbdEmployeeIds)->pluck('id')->all();

purgeJournalEntries(costJournalEntries($xbdCostIds), 'verify-crossing: clear an interrupted run\'s entries');

wipeIds('border_costs', 'border_crossing_id', $xbdCrossingIds);
wipeIds('border_crossings', 'trip_id', $xbdTripIds);
wipeIds('trip_costs', 'trip_id', $xbdTripIds);
wipeIds('loads', 'trip_id', $xbdTripIds);
wipeIds('odometer_readings', 'trip_id', $xbdTripIds);
wipeIds('odometer_readings', 'truck_id', $xbdTruckIds);
wipeIds('vehicle_assignments', 'trip_id', $xbdTripIds);
wipeIds('operational_events', 'aggregate_id', array_values(array_unique(array_merge($xbdTripIds, $xbdCrossingIds))));
wipeIds('operational_events', 'aggregate_id', $xbdJobIds);
wipeIds('trips', 'id', $xbdTripIds);
wipeIds('freight_jobs', 'id', $xbdJobIds);
wipeIds('drivers', 'id', $xbdDriverIds);
wipeIds('employees', 'id', $xbdEmployeeIds);
wipeIds('trucks', 'id', $xbdTruckIds);
DB::table('trailers')->where('registration_no', 'like', '%XBD')->delete();
DB::table('border_posts')->where('name', 'like', 'XBD %')->delete();
DB::table('routes')->where('code', 'like', 'XBD%')->delete();
DB::table('customers')->where('code', 'like', 'XBD%')->delete();
DB::table('companies')->where('name', 'Verify XBD Second Company')->delete();

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
    'truck_ids' => [], 'employee_ids' => [], 'driver_ids' => [],
    'job_ids' => [], 'trip_ids' => [], 'crossing_ids' => [],
    'post_ids' => [], 'cost_ids' => [], 'border_cost_ids' => [],
];

/* ------------------------------------------------------------------------- */
echo "== / the schema keeps the promises the docs make (live MySQL 9.1) ==\n";

$postDdl = (string) ((array) DB::select('show create table border_posts')[0])['Create Table'];
check(str_contains($postDdl, 'border_posts_name_unique'), 'a gate is unique per company, name and country');
check(str_contains($postDdl, 'border_posts_country_upper'), '...and its country code is stored upper-case');

$crossingDdl = (string) ((array) DB::select('show create table border_crossings')[0])['Create Table'];

foreach ([
    'border_crossings_trip_post_direction_unique',
    'border_crossings_trip_company_fk',
    'border_crossings_direction_allowed',
    'border_crossings_status_allowed',
    'border_crossings_country_upper',
    'border_crossings_exit_follows_entry',
    'border_crossings_state_has_times',
] as $name) {
    check(str_contains($crossingDdl, $name), "border_crossings carries {$name}");
}

$borderCostDdl = (string) ((array) DB::select('show create table border_costs')[0])['Create Table'];

foreach ([
    'border_costs_trip_cost_unique',
    'border_costs_trip_cost_fk',
    'border_costs_crossing_company_fk',
    'border_costs_kind_allowed',
    'border_costs_amount_positive',
    'border_costs_rate_positive',
    'border_costs_currency_upper',
] as $name) {
    check(str_contains($borderCostDdl, $name), "border_costs carries {$name}");
}

check(Schema::hasColumns('border_costs', ['currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref', 'base_currency_code', 'base_amount']), 'border_costs carries the eight columns');

/* ------------------------------------------------------------------------- */
echo "\n== registering a gate: master data, refused before it can split ==\n";

$createPost = app(CreateBorderPost::class);

refuses(
    'a gate in a country the fleet does not drive to is refused',
    fn () => $createPost(new BorderPostDto(name: 'XBD Nowhere', countryCode: 'FR')),
    InvalidArgumentException::class,
    'not a corridor country'
);

refuses(
    'a gate north of the pole is refused',
    fn () => $createPost(new BorderPostDto(name: 'XBD Pole', countryCode: 'BW', lat: '91')),
    InvalidArgumentException::class,
    'between -90 and 90'
);

refuses(
    'a gate with a blank name is refused',
    fn () => $createPost(new BorderPostDto(name: '   ', countryCode: 'BW')),
    InvalidArgumentException::class,
    'needs a name'
);

$kazungula = $createPost(new BorderPostDto(name: 'XBD Kazungula', countryCode: 'BW', lat: '-17.7890000', lng: '25.2680000', openFrom: '06:00', openTo: '22:00', notes: 'River crossing'));
$created['post_ids'][] = $kazungula->id;

check($kazungula->country_code === 'BW' && $kazungula->label() === 'XBD Kazungula (BW)', 'the gate stores its country and prints it with the name');
check($kazungula->openingHours() === '06:00–22:00', '...and its hours read back as one phrase');

refuses(
    'the same gate in the same country is refused by name',
    fn () => $createPost(new BorderPostDto(name: 'XBD Kazungula', countryCode: 'BW')),
    InvalidArgumentException::class,
    'already exists'
);

$katima = $createPost(new BorderPostDto(name: 'XBD Katima Mulilo', countryCode: 'NA'));
$created['post_ids'][] = $katima->id;

check($katima->openingHours() === '24 hours', 'a gate with no hours recorded reads as 24 hours, not as closed');

/* ------------------------------------------------------------------------- */
echo "\n== fixtures: a trip that can carry gates ==\n";

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'XBD Verify Customer', code: 'XBD-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'XBD-GBE-WDH',
    origin: 'Gaborone',
    destination: 'Windhoek',
    originCountryCode: 'BW',
    destinationCountryCode: 'NA',
    distanceKm: '750',
));

$baseCurrency = CurrentCompany::model()->base_currency_code;

$truck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 82 XBD',
    fleetNo: 'T-XBD1',
    vin: 'VINXBD000000000000001',
    make: 'Probe',
    model: 'Crossing',
    odometerKm: 120_000,
));
$created['truck_ids'][] = $truck->id;

$employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-XBD001', name: 'XBD Verify Driver', hiredOn: '2024-01-10'));
$created['employee_ids'][] = $employee->id;

$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-XBD001',
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
    commodity: 'copper',
    tonnes: 28.0,
    requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
    notes: 'XBD main job',
));
$created['job_ids'][] = $job->id;

$job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '1200.00', currencyCode: $baseCurrency), 1);
$job = (app(AcceptJob::class))($job, 1);

$trip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $trip->id;

/* ------------------------------------------------------------------------- */
echo "\n== planning a crossing: born planned, country copied, retried once ==\n";

$plan = app(PlanBorderCrossing::class);

$entry = $plan($trip, $kazungula, BorderCrossingDirection::Entry, 1);
$created['crossing_ids'][] = $entry->id;

check($entry->status === BorderCrossingStatus::Planned, 'a new crossing is planned ('.$entry->id.')');
check($entry->country_code === 'BW', '...and the gate\'s country is copied onto the row, not joined at read time');
check($entry->entered_at === null && $entry->exited_at === null, '...with no entry or exit on it yet');
check($entry->state_changed_at !== null, '...and the state clock is written with the state');

$retry = $plan($trip, $kazungula, BorderCrossingDirection::Entry, 1);
check($retry->id === $entry->id, 'a retried plan returns the crossing it already made, not a second one');
check((int) DB::table('border_crossings')->where('trip_id', $trip->id)->count() === 1, '...and the table holds exactly one row for it');

$exitCrossing = $plan($trip, $kazungula, BorderCrossingDirection::Exit, 1);
$created['crossing_ids'][] = $exitCrossing->id;

check($exitCrossing->id !== $entry->id && $exitCrossing->direction === BorderCrossingDirection::Exit, 'the same gate the other way is a second crossing — a round trip crosses twice');

check((int) DB::table('operational_events')->where('aggregate_type', 'border_crossing')->where('aggregate_id', $entry->id)->count() === 0, 'birth is not a transition, so it writes no trail event');

$closedTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $closedTrip->id;
$closedTrip->forceFill([
    'status' => TripStatus::Closed->value,
    'costs_frozen' => true,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();

refuses(
    'a closed trip cannot acquire a crossing',
    fn () => $plan($closedTrip->fresh(), $kazungula, BorderCrossingDirection::Entry, 1),
    InvalidArgumentException::class,
    'cannot acquire a border crossing'
);

$cancelledTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $cancelledTrip->id;
$cancelledTrip->forceFill([
    'status' => TripStatus::Cancelled->value,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();

refuses(
    'a cancelled trip cannot acquire a crossing',
    fn () => $plan($cancelledTrip->fresh(), $kazungula, BorderCrossingDirection::Entry, 1),
    InvalidArgumentException::class,
    'cannot acquire a border crossing'
);

/* ------------------------------------------------------------------------- */
echo "\n== entering and exiting: guarded edges, stamped facts, one trail per move ==\n";

$enter = app(MarkCrossingEntered::class);
$exit = app(MarkCrossingExited::class);

refuses(
    'a crossing cannot be exited before it was entered',
    fn () => $exit($entry, null, 1),
    InvalidArgumentException::class,
    'only after it was entered'
);

$entered = $enter($entry->fresh(), 'CD-2026-77120', 1);
check($entered->status === BorderCrossingStatus::Entered, 'the crossing is entered');
check($entered->entered_at !== null, '...with the moment it happened stamped on the row');
check($entered->transit_doc_no === 'CD-2026-77120', '...and the transit document the gate asked for');

$entryTrail = DB::table('operational_events')
    ->where('aggregate_type', 'border_crossing')
    ->where('aggregate_id', $entry->id)
    ->orderBy('id')
    ->get(['from_state', 'to_state', 'actor_id', 'actor_kind'])
    ->all();

check(count($entryTrail) === 1 && $entryTrail[0]->from_state === 'planned' && $entryTrail[0]->to_state === 'entered', 'the move is on the trail as planned → entered');
check((int) $entryTrail[0]->actor_id === 1 && $entryTrail[0]->actor_kind === 'user', '...with the person who made it');

refuses(
    'the same crossing cannot be entered twice',
    fn () => $enter($entry->fresh(), null, 1),
    InvalidArgumentException::class,
    'already entered'
);

refuses(
    'an exit stamped before the entry is refused with both times named',
    fn () => $exit($entry->fresh(), null, 1, Carbon::now()->subHour()),
    InvalidArgumentException::class,
    'had not entered until'
);

$exited = $exit($entry->fresh(), 'cleared — 40 minutes', 1);
check($exited->status === BorderCrossingStatus::Exited, 'the crossing is exited');
check($exited->exited_at !== null && $exited->exited_at->gte($exited->entered_at), '...and the exit is not before the entry');
check($exited->clearance_state === 'cleared — 40 minutes', '...with the gate\'s own clearance word kept as text');

check((int) DB::table('operational_events')->where('aggregate_type', 'border_crossing')->where('aggregate_id', $entry->id)->count() === 2, 'entry and exit are two events on the trail, in order');

refuses(
    'the schema refuses a past-entered state without its times',
    fn () => DB::table('border_crossings')->where('id', $exitCrossing->id)->update(['status' => 'exited']),
    QueryException::class
);

/* ------------------------------------------------------------------------- */
echo "\n== // capturing a charge: one trip cost per charge, eight columns copied ==\n";

$capture = app(CaptureBorderCost::class);

$chargeDto = static fn (
    BorderCostKind $kind,
    Money $amount,
    string $rate = '1',
    ?TripCostPayment $paid = null,
    ?string $description = null,
): BorderCostDto => new BorderCostDto(
    kind: $kind,
    amount: $amount,
    rate: $rate,
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(CurrentCompany::timezone())->startOfDay(),
    occurredOn: Carbon::now(CurrentCompany::timezone())->startOfDay(),
    paidWith: $paid ?? TripCostPayment::PettyCash,
    description: $description,
);

$duty = $capture($exited, $chargeDto(BorderCostKind::Duty, Money::ofMajor('450.00', $baseCurrency), '1', TripCostPayment::Bank, 'Customs duty'), 1);
$created['border_cost_ids'][] = $duty->id;

check($duty->kind === BorderCostKind::Duty, 'the charge records what the gate charged for');
check($duty->base_amount === 45_000 && $duty->base_currency_code === $baseCurrency, '...at the base figure the statement was');
check($duty->trip_cost_id !== null, '...and links the trip cost it posted');

$dutyTripCost = TripCost::query()->findOrFail($duty->trip_cost_id);
$created['cost_ids'][] = $dutyTripCost->id;

check($dutyTripCost->cost_type === TripCostType::Border, 'the posted trip cost is of type border, so files it in the border bucket');
check($dutyTripCost->source_type === 'border_cost' && $dutyTripCost->source_id === $duty->id, '...and its source pair names the border cost, so a retry cannot post twice');

$dutyEntry = DB::table('gl_journal_entries')->where('source_type', 'trip_cost')->where('source_id', $dutyTripCost->id)->first();
check($dutyEntry !== null, 'the charge has a journal entry of its own');

$lines = $dutyEntry === null ? [] : DB::table('gl_journal_lines as l')
    ->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
    ->where('l.entry_id', $dutyEntry->id)
    ->get(['l.*', 'a.code as account_code'])
    ->keyBy('direction')
    ->all();

check(isset($lines['debit']) && $lines['debit']->account_code === '5400', '...debitng 5400 Border and customs');
check(isset($lines['credit']) && $lines['credit']->account_code === '1120', '...and crediting 1120, the bank the money left');
check(isset($lines['debit']) && $lines['debit']->trip_id === $trip->id, '...with the trip dimension the design requires');

$usd = $capture($exited, $chargeDto(BorderCostKind::Scanning, Money::ofMajor('100.00', 'USD'), '13.50', TripCostPayment::PettyCash, 'Scanner fee'), 1);
$created['border_cost_ids'][] = $usd->id;
$usdTripCost = TripCost::query()->findOrFail($usd->trip_cost_id);
$created['cost_ids'][] = $usdTripCost->id;

check($usd->amount === 10_000 && $usd->currency_code === 'USD', 'a foreign charge keeps its stated figure (100.00 USD)');
check($usd->base_amount === 135_000, '...and the base figure is 10 000 × 13.50 = 135 000 minor');

$copiedColumns = ['currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'base_currency_code', 'base_amount'];
$copied = true;

foreach ($copiedColumns as $column) {
    $raw = DB::table('trip_costs')->where('id', $usdTripCost->id)->value($column);
    $copy = DB::table('border_costs')->where('id', $usd->id)->value($column);

    if ((string) $raw !== (string) $copy) {
        $copied = false;
        failed("border_costs.{$column} equals the posted trip cost's", 'trip cost '.(string) $raw.' vs border cost '.(string) $copy);
    }
}

check($copied, 'every copied column on the border cost equals the posted trip cost it names');

check($usd->fx_rate_source === FxRateSource::Manual && (float) $usd->fx_rate === 13.5, '...including the rate and its source, frozen rather than re-derived');

$fresh = Trip::query()->findOrFail($trip->id);
check($fresh->border_cost_base === 180_000, 'the border bucket equals both charges (45 000 + 135 000)');
check($fresh->direct_cost_base === 180_000, '...and so do the trip\'s direct costs, because a border cost is a direct cost');
check((int) DB::table('border_costs')->where('border_crossing_id', $entry->id)->count() === 2, 'one crossing may carry several charges — the gate charges more than once');

refuses(
    'a charge on a crossing that is still planned is refused',
    fn () => $capture($exitCrossing, $chargeDto(BorderCostKind::RoadUserFee, Money::ofMajor('10.00', $baseCurrency))),
    InvalidArgumentException::class,
    'while the truck is at the gate'
);

/* ------------------------------------------------------------------------- */
echo "\n== finishing the costs: a claim, not a derivation, and not on a guess ==\n";

$finish = app(FinishBorderCosts::class);

$freeExit = $plan($trip, $katima, BorderCrossingDirection::Exit, 1);
$created['crossing_ids'][] = $freeExit->id;
$freeExit = $enter($freeExit, null, 1);
$freeExit = $exit($freeExit->fresh(), null, 1);

refuses(
    'a crossing with no captured charge cannot be finished',
    fn () => $finish($freeExit->fresh(), 1),
    InvalidArgumentException::class,
    'No charge has been captured'
);

$finished = $finish($exited->fresh(), 1);
check($finished->status === BorderCrossingStatus::CostsCaptured, 'a crossing with charges on it is finished');
check((int) DB::table('operational_events')->where('aggregate_type', 'border_crossing')->where('aggregate_id', $entry->id)->where('to_state', 'costs_captured')->count() === 1, '...and the finish is on the trail');

refuses(
    'a finished crossing is sealed against late charges',
    fn () => $capture($finished, $chargeDto(BorderCostKind::Other, Money::ofMajor('10.00', $baseCurrency))),
    InvalidArgumentException::class,
    'while the truck is at the gate'
);

refuses(
    'this build cannot reach agent_reconciled — the design keeps Phase 6 shut',
    fn () => $finished->moveTo(BorderCrossingStatus::AgentReconciled, null, [], ActorKind::User, 1),
    IllegalTransition::class,
    'agent_reconciled'
);

/* ------------------------------------------------------------------------- */
echo "\n== completion: a gate the crossing record has not cleared stops the trip ==\n";

$complete = app(CompleteTrip::class);

// Fabricated at `delivered`, the way the other refusals fabricate their state: the checklist under
// test runs before any odometer arithmetic, so nothing else has to be true for it to answer.
$deliveredTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $deliveredTrip->id;
$deliveredTrip->forceFill([
    'status' => TripStatus::Delivered->value,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();

$clearing = $plan($deliveredTrip->fresh(), $katima, BorderCrossingDirection::Entry, 1);
$created['crossing_ids'][] = $clearing->id;

refuses(
    'a delivered trip with a planned crossing is refused completion, naming the gate',
    fn () => $complete($deliveredTrip->fresh(), 400_000, null, 1),
    InvalidArgumentException::class,
    'requires every crossing to be exited'
);

$clearing = $enter($clearing->fresh(), null, 1);

refuses(
    '...and a crossing entered but not exited blocks it just the same',
    fn () => $complete($deliveredTrip->fresh(), 400_000, null, 1),
    InvalidArgumentException::class,
    'is entered'
);

$clearing = $exit($clearing->fresh(), null, 1);

// Past the gate the Action reaches its next refusal: this trip never departed, so it has no opening
// reading. Reaching that line is the proof the crossing check let it through.
refuses(
    'once the crossing is exited the checklist moves past it',
    fn () => $complete($deliveredTrip->fresh(), 400_000, null, 1),
    InvalidArgumentException::class,
    'no opening reading'
);

/* ------------------------------------------------------------------------- */
echo "\n== the dispatcher reads the register and does not move it ==\n";

/** @var User $dispatcher */
$dispatcher = User::query()->firstOrNew(['email' => 'verify-xbd-dispatcher@fleetguard.local']);
$dispatcher->name = 'Verify XBD Dispatcher';
$dispatcher->company_id = $owner->company_id;
$dispatcher->password = Str::random(32);
$dispatcher->email_verified_at = Carbon::now();
$dispatcher->save();
app(ChangeUserRole::class)($dispatcher->fresh(), RoleKey::Dispatcher);
$dispatcher = $dispatcher->fresh();

check(Gate::forUser($dispatcher)->allows('crossborder.view'), 'a dispatcher may see crossings');
check(! Gate::forUser($dispatcher)->allows('crossborder.manage'), '...but not capture or move them');

Auth::login($dispatcher);
refuses(
    'a dispatcher cannot plan a crossing',
    fn () => $plan($trip, $katima, BorderCrossingDirection::Entry),
    InvalidArgumentException::class,
    'cross-border management'
);
Auth::login($owner);
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== tenancy: the border registers are scoped, not filtered by habit ==\n";

$stamp = Carbon::now()->toDateTimeString();

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify XBD Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

CurrentCompany::force(2);
check((int) BorderPost::query()->count() === 0, 'company 2 reads no gates');
check((int) BorderCrossing::query()->count() === 0, '...no crossings');
check((int) BorderCost::query()->count() === 0, '...and no border charges');

CurrentCompany::force(1);
check((int) BorderPost::query()->count() >= 2, 'company 1 still reads its own gates');
DB::table('companies')->where('id', 2)->delete();
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== the ledger still verifies with these postings on it ==\n";

$ledgerExit = Artisan::call('ledger:verify', ['--quiet-ok' => true]);
$ledgerOutput = Artisan::output();
check($ledgerExit === 0, 'ledger:verify passes with the border charges in place', mb_substr(str_replace("\n", ' ', $ledgerOutput), 0, 240));

/* ------------------------------------------------------------------------- */
echo "\n== cleanup, and the proof it worked ==\n";

$runTripIds = array_values(array_unique(array_merge(
    $created['trip_ids'],
    DB::table('trips')->whereIn('job_id', $created['job_ids'] ?: ['0'])->pluck('id')->all(),
)));
$runCrossingIds = array_values(array_unique(array_merge(
    $created['crossing_ids'],
    $runTripIds === [] ? [] : DB::table('border_crossings')->whereIn('trip_id', $runTripIds)->pluck('id')->all(),
)));
$runBorderCostIds = $runCrossingIds === [] ? [] : DB::table('border_costs')->whereIn('border_crossing_id', $runCrossingIds)->pluck('id')->all();
$runCostIds = array_values(array_unique(array_merge(
    $created['cost_ids'],
    $runTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $runTripIds)->pluck('id')->all(),
)));

$runEntryIds = costJournalEntries($runCostIds);
purgeJournalEntries($runEntryIds, 'verify-crossing: remove this run\'s trip-cost entries');

check(! LedgerBarrier::isLifted(), 'the barrier is down again after the cleanup');

wipeIds('border_costs', 'border_crossing_id', $runCrossingIds);
wipeIds('border_crossings', 'trip_id', $runTripIds);
wipeIds('trip_costs', 'trip_id', $runTripIds);
wipeIds('loads', 'trip_id', $runTripIds);
wipeIds('odometer_readings', 'trip_id', $runTripIds);
wipeIds('odometer_readings', 'truck_id', $created['truck_ids']);
wipeIds('vehicle_assignments', 'trip_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', array_values(array_unique(array_merge($runTripIds, $runCrossingIds))));
wipeIds('operational_events', 'aggregate_id', $created['job_ids']);
wipeIds('trips', 'id', $runTripIds);
wipeIds('freight_jobs', 'id', $created['job_ids']);

DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $created['truck_ids'] ?: [0])->delete();
DB::table('trailers')->where('registration_no', 'like', '%XBD')->delete();
DB::table('border_posts')->where('name', 'like', 'XBD %')->delete();
DB::table('routes')->where('code', 'like', 'XBD%')->delete();
DB::table('customers')->where('code', 'like', 'XBD%')->delete();

foreach ([Trip::class, BorderCrossing::class, BorderCost::class, BorderPost::class, TripCost::class, Job::class] as $type) {
    DB::table('audits')->where('auditable_type', $type)->where('created_at', '>=', $runStartedAt)->delete();
}

// The probe seat goes after its grant rows: `model_has_roles` carries a team id and refuses to let the
// account leave first (the same children-before-parents rule verify-master records).
$probeIds = DB::table('users')->where('email', 'like', 'verify-xbd-%@fleetguard.local')->pluck('id')->all();
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

$alignCounters('after cleanup');

$afterExit = Artisan::call('documents:number-integrity');
$afterOutput = Artisan::output();
check($afterExit === 0, 'the counters and the surviving rows agree once the fixtures are gone', mb_substr(str_replace("\n", ' ', $afterOutput), 0, 200));

$leftovers = [
    'trips' => (int) DB::table('trips')->whereIn('id', $runTripIds ?: ['0'])->count(),
    'freight_jobs' => (int) DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: ['0'])->count(),
    'border_crossings' => (int) DB::table('border_crossings')->whereIn('id', $runCrossingIds ?: ['0'])->count(),
    'border_costs' => (int) DB::table('border_costs')->whereIn('id', $runBorderCostIds ?: ['0'])->count(),
    'border_posts' => (int) DB::table('border_posts')->where('name', 'like', 'XBD %')->count(),
    'trip_costs' => (int) DB::table('trip_costs')->whereIn('id', $runCostIds ?: ['0'])->count(),
    'journal_entries' => (int) DB::table('gl_journal_entries')->whereIn('id', $runEntryIds ?: ['0'])->count(),
    'journal_lines' => (int) DB::table('gl_journal_lines')->whereIn('entry_id', $runEntryIds ?: ['0'])->count(),
    'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%XBD')->count(),
    'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-XBD%')->count(),
    'drivers' => (int) DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->count(),
    'customers' => (int) DB::table('customers')->where('code', 'like', 'XBD%')->count(),
    'routes' => (int) DB::table('routes')->where('code', 'like', 'XBD%')->count(),
    'probe company' => (int) DB::table('companies')->where('name', 'Verify XBD Second Company')->count(),
    'probe seats' => (int) DB::table('users')->where('email', 'like', 'verify-xbd-%@fleetguard.local')->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
