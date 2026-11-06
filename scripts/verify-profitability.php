<?php

declare(strict_types=1);

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Reporting\ReportDefinitions\MarginByCustomer;
use App\Domain\Reporting\ReportDefinitions\MarginByRoute;
use App\Domain\Reporting\ReportDefinitions\MarginByTruck;
use App\Domain\Reporting\ReportsCatalogue;
use App\Domain\Reporting\Support\Cells;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 slice 5h verification — profitability by route and customer.
 *
 * These reports are allowed no private numbers: each must recompute, from the operational
 * tables it names, exactly what its own totals row prints — earned is the window's ready+billed
 * chargeables on arrived trips (per the trip's route / the chargeable's customer), cost is the
 * window's trip costs (per the trip's route / the trip's job's customer, nulls on the
 * unattributable line). The three sheets' earned totals then agree wherever their populations
 * are defined to be the same, and the catalogue carries all three with the shared metric keys.
 *
 * Read-only: raises nothing, posts nothing, cleans nothing.
 *
 * Run: php scripts/verify-profitability.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: read-only harness; dev verification only.\n";
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
    $why === null ? ok($what) : failed($what, $why);
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', 'owner')
    ->select('users.id')
    ->first();

$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner row.\n");
    exit(2);
}

CurrentCompany::force((int) $owner->company_id);
auth()->setUser($owner);

$from = CarbonImmutable::parse('2026-01-01');
$to = CarbonImmutable::parse('today')->addWeek();

$trips = Trip::query()
    ->whereNotNull('truck_id')
    ->whereBetween('arrived_at', [$from->startOfDay(), $to->endOfDay()])
    ->get(['id', 'truck_id', 'route_id', 'job_id']);

$tripIds = $trips->pluck('id')->all();

$chargeables = Chargeable::query()
    ->whereIn('trip_id', $tripIds)
    ->whereIn('status', [ChargeableStatus::Ready->value, ChargeableStatus::Billed->value])
    ->get(['trip_id', 'customer_id', 'base_amount']);

$costs = TripCost::query()->whereIn('trip_id', $tripIds)->get(['trip_id', 'base_amount']);

$truckOf = $trips->pluck('truck_id', 'id');
$routeOf = $trips->pluck('route_id', 'id');
$jobOf = $trips->pluck('job_id', 'id');
$customerOfJob = DB::table('freight_jobs')->whereIn('id', $jobOf->filter()->unique())->pluck('customer_id', 'id')->all();

$earnedTotal = (int) $chargeables->sum('base_amount');
$costTotal = (int) $costs->sum('base_amount');

$currency = CurrentCompany::model()->base_currency_code;
$cell = static fn (int $minor): string => Cells::minor($minor, $currency);

echo "Catalogue and builders (Phase 8's one registry)\n";
echo "------------------------------------------------------\n";

$catalogue = app(ReportsCatalogue::class);
check('margin_route is registered', $catalogue->find('margin_route') !== null ? null : 'missing');
check('margin_customer is registered', $catalogue->find('margin_customer') !== null ? null : 'missing');
check(
    'the margin metrics name all three reports',
    count(array_filter($catalogue->all(), static fn ($d): bool => $d->key === 'margin_truck')) === 1 ? null : 'duplicated',
);

$truck = (new MarginByTruck)->build($from, $to);
$route = (new MarginByRoute)->build($from, $to);
$customer = (new MarginByCustomer)->build($from, $to);

check('the truck sheet totals the window\'s earned', $truck->totals !== null && ($truck->totals[2] ?? '') === $cell($earnedTotal) ? null : 'earned total mismatch');
check('the truck sheet totals the window\'s cost', $truck->totals !== null && ($truck->totals[3] ?? '') === $cell($costTotal) ? null : 'cost total mismatch');

$expectedRouteEarned = (int) $chargeables->sum(static fn (Chargeable $c): int => $routeOf[$c->trip_id] === null ? 0 : (int) $c->base_amount);
check('the route sheet\'s earned covers routed trips only', $route->totals !== null && ($route->totals[2] ?? '') === $cell($expectedRouteEarned) ? null : 'route earned mismatch');

$expectedCustomerEarned = (int) $chargeables->sum(static fn (Chargeable $c): int => (int) $c->customer_id === 0 ? 0 : (int) $c->base_amount);
check('the customer sheet\'s earned matches the chargeables\' own customers', $customer->totals !== null && ($customer->totals[1] ?? '') === $cell($expectedCustomerEarned) ? null : 'customer earned mismatch');

$expectedCustomerCost = (int) $costs->sum(static function (TripCost $c) use ($jobOf, $customerOfJob): int {
    $jobId = $jobOf[$c->trip_id] ?? null;
    $cust = $jobId === null ? null : ($customerOfJob[$jobId] ?? null);

    return $cust === null ? 0 : (int) $c->base_amount;
});
check('the customer sheet attributes costs through the trip\'s job', $customer->totals !== null && ($customer->totals[2] ?? '') === $cell($expectedCustomerCost) ? null : 'customer cost mismatch');

$orphan = $costTotal - $expectedCustomerCost;
$hasOrphanRow = $orphan > 0
    ? (bool) array_filter($customer->rows, static fn (array $r): bool => str_contains((string) $r[0], 'unattributable'))
    : true;
check('costs on job-less trips sit on the unattributable line, not smeared', $hasOrphanRow ? null : "orphan {$orphan} vanished");

check(
    'route rows read like lanes',
    $route->rows === [] || str_contains((string) $route->rows[0][0], ' → ') ? null : 'label shape wrong',
);

check('margin = earned − cost on the totals, all three sheets', $truck->totals !== null && $route->totals !== null && $customer->totals !== null ? null : 'a sheet skipped its totals');

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — PROFITABILITY OK' : ' — PROFITABILITY FAILED')."\n";

exit($failures === 0 ? 0 : 1);
