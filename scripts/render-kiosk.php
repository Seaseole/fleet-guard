<?php

declare(strict_types=1);

/**
 * Phase 2 slice 2d — the kiosk and the office's half of the proof, through the real HTTP/Livewire stack.
 *
 * `verify-kiosk.php` proves the gate: hashes, refusals, the state machine, tenancy. What it cannot prove is
 * the thing §9.6 is actually about — that the string a dispatcher copies off the trip screen **opens the
 * driver's page**, with the fields the driver will be asked for on it, and that what comes back is what the
 * office sees when it decides. A capture Action that is correct in a script and a link that 404s on a
 * phone is a delivery nobody can evidence.
 *
 * So this sweep uses two doors in order: it issues a link on the trip detail page through Livewire, reads
 * the URL the panel printed, and GETs that URL with no session at all — the shared phone in the yard.
 * Slice 3g adds the fuel screen to the same sweep: a `fuel` link opens a form with the pump's fields and
 * no POD form, and each narrow link landing on the other door is told so instead of being handed a form
 * it cannot save.
 *
 * Not a test suite: `tests/` stays out of the repository (§14.6). Fixtures live in the `RKO` namespace on
 * the development database; everything created is removed and then asserted removed, including the two
 * operational counters, which `documents:number-integrity` compares against the surviving rows.
 *
 * Run: php scripts/render-kiosk.php   (set RENDER_KIO_TRACE=1 for stack traces)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\AdvanceTrip;
use App\Domain\Operations\Actions\CaptureProofOfDelivery;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\IssueTripAccessToken;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Actions\RevokeTripAccessToken;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripAccessToken;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * @extends TestCase
 */
final class KioskRenderSweep extends TestCase
{
    public int $failures = 0;

    private ?int $ownerCompany = null;

    private ?User $owner = null;

    private string $pngPath = '';

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function sweep(): void
    {
        /** @var User $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail();
        $this->owner = $owner;
        $this->ownerCompany = $owner->company_id;
        CurrentCompany::force($owner->company_id);
        Auth::login($owner);

        // The kiosk sweep measures the driver's door and the office's panel; the MFA redirect belongs to
        // render-identity and would be reported here as a broken trip screen. CSRF is skipped for the one
        // /livewire/update POST below because it is posted without the browser's cookie jar, and the
        // browser pass already proved the token path; what this sweep proves is the middleware gap.
        $this->withoutMiddleware([
            EnsureMultiFactorAuthentication::class,
            PreventRequestForgery::class,
        ]);

        $workshop = $this->staffAs($owner, RoleKey::WorkshopManager, 'render-kio-check-workshop@fleetguard.local');
        CurrentCompany::force($owner->company_id);

        /*
         * Two arrived trips: one whose proof the office accepts, one it sends back. They are built through
         * the Actions the board uses, because the screen is being proved, not the spine.
         */
        [$tripA, $crewA] = $this->arrivedTrip('1', 230_000);
        [$tripB, $crewB] = $this->arrivedTrip('2', 240_000);

        // The driver's fuel screen (slice 3g) needs a tank to show its form, so this fixture truck
        // declares one; the trip is out and not yet decided, which is where a driver buys diesel.
        [$tripC, $crewC] = $this->arrivedTrip('3', 250_000, 300);
        $fuelTank = (app(CreateFuelTank::class))((int) $crewC[0]->id, FuelTankKind::Main);

        $driverA = $crewA[2];
        $driverB = $crewB[2];

        echo "== §9.6 the link is the door, and it opens without a login ==\n";

        $issue = app(IssueTripAccessToken::class);
        $direct = $issue($tripA, (int) $driverA->id, ['pod'], (int) $owner->id);
        $directSecret = $direct['secret'];

        $fuelLink = $issue($tripC, (int) $crewC[2]->id, ['fuel'], (int) $owner->id);

        $this->attempt('a driver phone with no session opens the page for its one trip', function () use ($directSecret): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$directSecret)->assertOk()->getContent();

            foreach ([
                'Proof of delivery',
                'Who received it?',
                'Tonnes actually received',
                'What went wrong? (one per line)',
                'Photo of the delivered load',
                'Signature — draw with a finger',
                '6-digit PIN from the dispatcher',
                'Send the proof',
            ] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the driver page is missing '{$needle}'");
                }
            }
        });

        $this->attempt('the driver page is the driver page: no delivery decision on it', function () use ($directSecret): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$directSecret)->assertOk()->getContent();

            foreach (['Accept the delivery', 'Send back', 'Revoke'] as $officeOnly) {
                if (str_contains($html, $officeOnly)) {
                    throw new Exception("the kiosk HTML offers the office control '{$officeOnly}'");
                }
            }
        });

        $this->attempt('a guessed secret is a 404, not a page that explains itself', function (): void {
            $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.str_repeat('ab', 24))->assertNotFound();
        });

        $this->attempt('the same secret under another company is a 404 — the path is the tenant, not a hint', function () use ($directSecret): void {
            $this->kioskGet('/kiosk/'.($this->ownerCompany + 1).'/'.$directSecret)->assertNotFound();
        });

        app(RevokeTripAccessToken::class)($direct['token']);
        CurrentCompany::force($this->ownerCompany);

        $this->attempt('a revoked link is a 404 too', function () use ($directSecret): void {
            $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$directSecret)->assertNotFound();
        });

        echo "\n== §9.6 the panel issues the link, and prints it once ==\n";

        /** @var array{url: string, pin: string, expires_at: string|null} $reveal */
        $reveal = [];

        $this->attempt('the trip screen shows the door and says no proof has arrived yet', function () use ($owner, $tripA): void {
            $html = (string) Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $tripA])->html();

            foreach (['Driver access and delivery evidence', 'Issue a driver link', 'The driver has not sent a proof yet'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the trip panel is missing '{$needle}'");
                }
            }

            if (str_contains($html, 'Copy this now')) {
                throw new Exception('the panel showed a secret before anything was issued');
            }
        });

        $this->attempt('issuing prints the URL and PIN once, in the panel rather than in the row', function () use ($owner, $tripA, &$reveal): void {
            $component = Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $tripA])
                ->set('kioskScopes', ['pod'])
                ->call('issueDriverLink')
                ->assertHasNoErrors('kiosk');

            $html = (string) $component->html();

            foreach (['Copy this now', 'Only hashes are stored, so neither can be shown again.', 'PIN ', 'Live', 'Revoke'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the reveal/table markup is missing '{$needle}'");
                }
            }

            $stored = session('kiosk.reveal.'.$tripA->id);

            if (! is_array($stored) || ! isset($stored['url'], $stored['pin'])) {
                throw new Exception('the one-time reveal did not reach the session');
            }

            if (! str_contains($html, (string) $stored['url'])) {
                throw new Exception('the panel is not printing the same URL the session holds');
            }

            $reveal = $stored;
        });

        $this->attempt('the URL the panel printed is the page the driver opens', function () use (&$reveal, $tripA): void {
            $secret = $this->secretFrom($reveal['url'] ?? '');
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$secret)->assertOk()->getContent();

            if (! str_contains($html, $tripA->trip_no)) {
                throw new Exception('the printed link does not name the trip it was issued for');
            }

            if (! str_contains($html, 'Send the proof')) {
                throw new Exception('the printed link does not open the capture form');
            }
        });

        $this->attempt('dismissing the reveal keeps the row, because the row is the evidence', function () use ($owner, $tripA): void {
            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $tripA])
                ->call('dismissDriverReveal')
                ->html();

            if (str_contains($html, 'Copy this now')) {
                throw new Exception('the reveal survived the dismissal');
            }

            if (! str_contains($html, 'Revoke') || ! str_contains($html, 'Live')) {
                throw new Exception('the token row disappeared with the reveal');
            }
        });

        echo "\n== §15.2 3g the fuel link opens the fuel screen, and only that screen ==\n";

        $this->attempt('a fuel link opens the driver\'s fuel screen with the pump\'s fields', function () use ($fuelLink, $tripC): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$fuelLink['secret'].'/fuel')->assertOk()->getContent();

            foreach ([
                'Fuel on the road',
                $tripC->trip_no,
                'Record what the pump gave you',
                'Litres',
                'Price per litre',
                'Odometer reading',
                '6-digit PIN from the dispatcher',
                'Record the fuel',
            ] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the fuel page is missing '{$needle}'");
                }
            }

            if (str_contains($html, 'Send the proof')) {
                throw new Exception('the fuel page offers the POD form its link does not carry');
            }

            foreach (['Accept the delivery', 'Send back', 'Revoke'] as $officeOnly) {
                if (str_contains($html, $officeOnly)) {
                    throw new Exception("the fuel page offers the office control '{$officeOnly}'");
                }
            }
        });

        $this->attempt('a fuel link on the POD URL is told which door it opened, not given a form', function () use ($fuelLink): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$fuelLink['secret'])->assertOk()->getContent();

            if (! str_contains($html, 'does not open proof-of-delivery')) {
                throw new Exception('the POD page does not say the link lacks the pod scope');
            }

            if (! str_contains($html, 'Record fuel instead')) {
                throw new Exception('the POD page does not point at the screen the link does open');
            }

            if (str_contains($html, 'Send the proof')) {
                throw new Exception('the POD page renders its form for a fuel-only link');
            }
        });

        $this->attempt('a pod link on the fuel URL is told the fuel scope is missing', function () use (&$reveal): void {
            $secret = $this->secretFrom($reveal['url'] ?? '');
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$secret.'/fuel')->assertOk()->getContent();

            if (! str_contains($html, 'does not open fuel capture')) {
                throw new Exception('the fuel page does not name the missing scope');
            }

            if (! str_contains($html, 'Go to proof of delivery')) {
                throw new Exception('the fuel page does not point back at the door the link opens');
            }

            if (str_contains($html, 'Record the fuel')) {
                throw new Exception('the fuel page renders its form for a pod-only link');
            }
        });

        /*
         * The page controls submit to `/livewire/update`, a route the `kiosk` middleware does not guard —
         * so the company context and the token attribute the GET established are gone by the first save.
         * Before the component held its own link and re-redeemed it, every submit died with
         * `MissingCompanyContext` (a 500 the browser found; `Livewire::test` would not). This sends the
         * snapshot the page printed back to the real endpoint with no session, exactly as the phone does.
         */
        $this->attempt('a submit with no session re-opens the page\'s own link instead of dying in the update', function () use ($fuelLink, $fuelTank, $tripC): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$fuelLink['secret'].'/fuel')->assertOk()->getContent();

            if (preg_match('/wire:snapshot="([^"]+)"/', $html, $match) !== 1) {
                throw new Exception('the fuel page carries no Livewire snapshot to submit');
            }

            $before = FuelIssue::query()->where('trip_id', $tripC->id)->count();

            $response = $this->kioskPostJson(
                '/'.ltrim(app(HandleRequests::class)->getUpdateUri(), '/'),
                ['components' => [[
                    'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
                    'updates' => [
                        'tankId' => (string) $fuelTank->id,
                        'stationId' => '',
                        'litres' => '50',
                        'price' => '19.75',
                        'odometer' => '300600',
                        'invoiceNo' => '',
                        'note' => '',
                        'pin' => '000000',
                    ],
                    'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
                ]]],
            )->assertOk();

            if (! str_contains((string) $response->getContent(), 'That PIN does not match this trip.')) {
                throw new Exception('the update did not reach the Action\'s PIN refusal: '.substr((string) $response->getContent(), 0, 400));
            }

            if (FuelIssue::query()->where('trip_id', $tripC->id)->count() !== $before) {
                throw new Exception('a refused submit wrote a fuel issue');
            }
        });

        $this->attempt('a seat without kiosk.token.issue cannot see the door at all', function () use ($workshop, $tripA): void {
            $html = (string) Livewire::actingAs($workshop)->test('pages::operations.detail', ['trip' => $tripA])->html();

            foreach (['Driver access and delivery evidence', 'Issue a driver link', 'Accept the delivery'] as $sealed) {
                if (str_contains($html, $sealed)) {
                    throw new Exception("the workshop seat sees '{$sealed}'");
                }
            }
        });

        echo "\n== §10.2 the office decides on what came back ==\n";

        $tokenA = TripAccessToken::query()
            ->where('trip_id', $tripA->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        $signature = fn (): DocumentInput => DocumentInput::fromPath($this->pngPath, 'image/png', 'signature.png');
        $photo = fn (): DocumentInput => DocumentInput::fromPath($this->pngPath, 'image/png', 'load.png');

        $facts = [
            'receiver_name' => 'RKO Receiver A',
            'quantity_tonnes' => '27.50',
            'exceptions' => [],
            'none_declared' => true,
        ];

        $podA = (app(CaptureProofOfDelivery::class))(
            $tokenA,
            $this->secretFrom($reveal['url']),
            $reveal['pin'],
            $facts,
            ['signature' => $signature(), 'photo' => $photo()],
            (string) Str::uuid(),
        );

        $this->attempt('the submitted proof appears on the trip with both decisions offered', function () use ($owner, $tripA, $podA): void {
            if ($podA->status !== PodStatus::Submitted || $tripA->fresh()->status !== TripStatus::PodPending) {
                throw new Exception('the capture did not reach submitted / pod_pending');
            }

            $html = (string) Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $tripA])->html();

            foreach (['RKO Receiver A', '27.5', 'Signature on file', 'Accept the delivery', 'Send back'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the proof card is missing '{$needle}'");
                }
            }
        });

        $this->attempt('accepting the delivery is the office move, and the screen reports it', function () use ($owner, $tripA, $podA): void {
            $component = Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $tripA])
                ->call('approvePod', $podA->id)
                ->assertHasNoErrors('pod');

            $html = (string) $component->html();

            if (str_contains($html, 'Accept the delivery') || str_contains($html, 'Send back')) {
                throw new Exception('the decision controls survived the decision');
            }

            if (! str_contains($html, 'approved')) {
                throw new Exception('the proof card does not show the approved state');
            }

            if ($tripA->fresh()->status !== TripStatus::Delivered) {
                throw new Exception('the trip did not reach delivered');
            }
        });

        $this->attempt('the driver who reloads after approval sees the outcome, not a second form', function () use (&$reveal): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$this->secretFrom($reveal['url']))->assertOk()->getContent();

            if (! str_contains($html, 'Check again')) {
                throw new Exception('the kiosk page does not show the captured state');
            }

            if (str_contains($html, 'Send the proof')) {
                throw new Exception('the kiosk page invites a second capture after approval');
            }
        });

        $tokenB = $issue($tripB, (int) $driverB->id, ['pod'], (int) $owner->id);

        $podB = (app(CaptureProofOfDelivery::class))(
            $tokenB['token'],
            $tokenB['secret'],
            $tokenB['pin'],
            ['receiver_name' => 'RKO Receiver B', 'quantity_tonnes' => '26.00', 'exceptions' => ['one pallet wet'], 'none_declared' => true],
            ['signature' => $signature(), 'photo' => $photo()],
            (string) Str::uuid(),
        );

        $this->attempt('a sent-back proof keeps its reason and leaves the trip where it was', function () use ($owner, $tripB, $podB): void {
            $component = Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $tripB]);

            $component->call('rejectPod', $podB->id)->assertHasErrors('podRejectReason');

            $component
                ->set('podRejectReason', 'The photo shows the wrong pallet')
                ->call('rejectPod', $podB->id)
                ->assertHasNoErrors('pod');

            $html = (string) $component->html();

            if ($podB->fresh()->status !== PodStatus::Rejected) {
                throw new Exception('the proof was not marked rejected');
            }

            if ($tripB->fresh()->status !== TripStatus::PodPending) {
                throw new Exception('rejection moved the trip; §10.2 says the delivery stays unproven, not unmade');
            }

            if (! str_contains($html, 'rejected') || ! str_contains($html, 'one pallet wet')) {
                throw new Exception('the card does not show the rejected proof and what was declared wrong');
            }

            if (str_contains($html, 'Accept the delivery')) {
                throw new Exception('a rejected proof still offers acceptance');
            }
        });

        $this->attempt('after a rejection the driver link opens the form again', function () use ($tokenB): void {
            $html = (string) $this->kioskGet('/kiosk/'.$this->ownerCompany.'/'.$tokenB['secret'])->assertOk()->getContent();

            if (! str_contains($html, 'Send the proof')) {
                throw new Exception('the kiosk page does not offer a resubmission');
            }

            if (str_contains($html, 'Check again')) {
                throw new Exception('the kiosk page behaves as if a proof was accepted');
            }
        });

        echo "\n== the page is honest to the ledger's rules about evidence ==\n";

        $this->attempt('both captures left their signature and photo as POD documents, and the trip links them', function () use ($podA, $podB): void {
            foreach ([$podA, $podB] as $pod) {
                $documents = DB::table('documents')
                    ->where('morph_type', 'proof_of_delivery')
                    ->where('morph_id', $pod->id)
                    ->pluck('id')
                    ->all();

                if (count($documents) !== 2) {
                    throw new Exception("proof {$pod->id} has ".count($documents).' documents, expected signature + photo');
                }

                if ($pod->fresh()->signature_document_id === null) {
                    throw new Exception("proof {$pod->id} has no signature on the row");
                }
            }
        });
    }

    /**
     * One trip, walked to `arrived` through the doors the board uses.
     *
     * @return array{0: Trip, 1: array{0: mixed, 1: mixed, 2: mixed}}
     */
    private function arrivedTrip(string $suffix, int $odometer, ?int $mainTankLitres = null): array
    {
        $customer = DB::table('customers')->where('code', 'RKO-CUST')->first();

        if ($customer === null) {
            (app(CreateCustomer::class))(new CustomerDto(name: 'RKO Render Customer', code: 'RKO-CUST', paymentTerms: 'net_30'));
        }

        $customerId = (string) DB::table('customers')->where('code', 'RKO-CUST')->value('id');

        $routeCreated = DB::table('routes')->where('code', 'RKO-GBE-WDH')->exists();

        if (! $routeCreated) {
            (app(CreateRoute::class))(new RouteDto(
                code: 'RKO-GBE-WDH',
                origin: 'Gaborone',
                destination: 'Windhoek',
                originCountryCode: 'BW',
                destinationCountryCode: 'NA',
                distanceKm: '750',
            ));
        }

        $routeId = (string) DB::table('routes')->where('code', 'RKO-GBE-WDH')->value('id');

        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 7'.$suffix.' RKO',
            fleetNo: 'T-RKO'.$suffix,
            vin: 'VINRKO00000000000000'.$suffix,
            make: 'Probe',
            model: 'Kiosk render',
            odometerKm: $odometer,
            mainTankLitres: $mainTankLitres,
        ));

        $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 7'.$suffix.' RKO', type: 'flatbed', capacityTonnes: 30.0));

        $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-RKO00'.$suffix, name: 'RKO Render Driver '.$suffix, hiredOn: '2024-01-10'));

        $driver = (app(CreateDriver::class))(new DriverDto(
            employeeId: (int) $employee->id,
            licenceNo: 'DL-RKO00'.$suffix,
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
            allowanceRateBase: 35_000,
            kioskEnabled: true,
        ));

        $job = (app(CreateJob::class))(new JobDto(
            customerId: (int) $customerId,
            routeId: (int) $routeId,
            commodity: 'packaged goods',
            tonnes: 28.0,
            requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
            requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
            notes: 'RKO render fixture '.$suffix,
        ));

        $job = (app(QuoteJob::class))($job, new JobQuoteDto(
            rateType: RouteRateType::PerTrip,
            amountMajor: '120000',
            currencyCode: CurrentCompany::model()->base_currency_code,
        ), 1);

        $job = (app(AcceptJob::class))($job, 1);

        $trip = (app(CreateTrip::class))($job, 1);

        $trip = (app(PlanTrip::class))($trip, new TripDto(
            jobId: $job->id,
            truckId: (int) $truck->id,
            driverId: (int) $driver->id,
            trailerId: (int) $trailer->id,
            plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
        ), 1);

        $trip = (app(DispatchTrip::class))($trip, 1);
        $trip = (app(DepartTrip::class))($trip, $odometer + 1, null, 1);
        $trip = (app(AdvanceTrip::class))($trip, TripStatus::InTransit, 1);
        $trip = (app(AdvanceTrip::class))($trip, TripStatus::Arrived, 1);

        if ($trip->status !== TripStatus::Arrived) {
            throw new Exception('the fixture trip did not arrive — check the edge map before blaming the screen');
        }

        return [$trip, [$truck, $trailer, $driver]];
    }

    private function secretFrom(string $url): string
    {
        if (preg_match('#/kiosk/\d+/([0-9a-f]{48})#', $url, $match) !== 1) {
            throw new Exception("the reveal URL does not carry a 48-character secret: {$url}");
        }

        return $match[1];
    }

    /**
     * A GET as the driver's phone sees it: no account, no session.
     *
     * `Auth::login()` in this process would otherwise make every request look authenticated, which is
     * precisely the condition a kiosk page must not need — so the owner is logged out for the call and put
     * back afterwards for the office's Actions and Livewire tests. The company context is re-forced because
     * `ResolveCurrentCompany` forgets it at the end of every request.
     */
    private function kioskGet(string $path): TestResponse
    {
        Auth::logout();

        try {
            $response = $this->get($path);
        } finally {
            if ($this->owner !== null) {
                Auth::login($this->owner);
            }

            CurrentCompany::force($this->ownerCompany ?? 1);
        }

        return $response;
    }

    /** The same no-session door, for the Livewire update the page's own controls post to. */
    private function kioskPostJson(string $path, array $payload): TestResponse
    {
        Auth::logout();

        try {
            $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson($path, $payload);
        } finally {
            if ($this->owner !== null) {
                Auth::login($this->owner);
            }

            CurrentCompany::force($this->ownerCompany ?? 1);
        }

        return $response;
    }

    private function staffAs(User $owner, ?RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render KIO Check '.($role?->label() ?? 'No Role');
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);

        if ($role !== null) {
            app(ChangeUserRole::class)($user, $role);
        }

        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
    }

    public function drive(): void
    {
        $this->setUp();

        $this->rememberOwner();

        $this->pngPath = storage_path('app/rko-render-fixture.png');
        file_put_contents($this->pngPath, $this->pngBytes());

        $this->purge();
        $this->assertNothingSurvives();
        $this->alignCounters('before the run, after an earlier run\'s debris');

        try {
            $this->sweep();
        } finally {
            $this->purge();
            $this->assertNothingSurvives();
            $this->alignCounters('after cleanup, so the nightly self-check sees only surviving rows');

            @unlink($this->pngPath);

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function pngBytes(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

        if ($png === false) {
            throw new Exception('the PNG fixture could not be decoded');
        }

        return $png;
    }

    private function rememberOwner(): void
    {
        $company = DB::table('users')->where('email', config('fleet.identity.owner_email'))->value('company_id');

        if ($company === null) {
            throw new Exception('the seeded owner account is missing — seed the demo company before running this sweep');
        }

        $this->ownerCompany = (int) $company;
        CurrentCompany::force($this->ownerCompany);
    }

    /**
     * Remove the `RKO` namespace by marker rather than by id list: a run that died between issue and
     * capture leaves rows this file never collected, and the next run would report them as product finds.
     */
    private function purge(): void
    {
        $companyId = $this->ownerCompany ?? 1;

        $jobIds = DB::table('freight_jobs')->where('company_id', $companyId)->where('notes', 'like', 'RKO%')->pluck('id')->all();
        $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();
        $podIds = $tripIds === [] ? [] : DB::table('proof_of_deliveries')->whereIn('trip_id', $tripIds)->pluck('id')->all();
        $docIds = $podIds === [] ? [] : DB::table('documents')
            ->where('morph_type', 'proof_of_delivery')
            ->whereIn('morph_id', $podIds)
            ->pluck('id')
            ->all();
        // A run that died between deleting a POD and its documents leaves files nothing can open; they
        // are swept here so a crashed run cannot make the next one assert against its ghost.
        $docIds = array_values(array_unique(array_merge($docIds, DB::table('documents')
            ->where('morph_type', 'proof_of_delivery')
            ->whereRaw('`morph_id` not in (select `id` from `proof_of_deliveries`)')
            ->pluck('id')
            ->all())));

        $truckIds = DB::table('trucks')->where('registration_no', 'like', '%RKO')->pluck('id')->all();
        $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-RKO%')->pluck('id')->all();
        $driverIds = $employeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $employeeIds)->pluck('id')->all();

        foreach ($docIds as $docId) {
            $paths = DB::table('document_versions')->where('document_id', $docId)->pluck('path')->all();

            if ($paths !== []) {
                Storage::disk(DocumentStore::Disk)->delete($paths);
            }
        }

        $this->deleteIn('vehicle_assignments', 'truck_id', $truckIds);
        $this->deleteIn('vehicle_assignments', 'driver_id', $driverIds);
        $this->deleteIn('odometer_readings', 'truck_id', $truckIds);
        $this->deleteIn('odometer_readings', 'trip_id', $tripIds);
        // The fuel screen's fixtures: a tank fitted to a truck and nothing else — this sweep reads the
        // form and never submits, so no issue, slip or journal entry is written.
        $this->deleteIn('fuel_tanks', 'truck_id', $truckIds);

        $this->deleteIn('document_versions', 'document_id', $docIds);
        $this->deleteIn('proof_of_deliveries', 'trip_id', $tripIds);
        $this->deleteIn('trip_access_tokens', 'trip_id', $tripIds);
        $this->deleteIn('loads', 'trip_id', $tripIds);
        $this->deleteIn('operational_events', 'aggregate_id', $tripIds);
        $this->deleteIn('operational_events', 'aggregate_id', $jobIds);
        // Approving a POD builds the trip's earnings, and those rows hold the trip as a parent.
        $this->deleteIn('chargeables', 'trip_id', $tripIds);
        $this->deleteIn('trips', 'id', $tripIds);
        $this->deleteIn('documents', 'id', $docIds);
        $this->deleteIn('freight_jobs', 'id', $jobIds);

        $this->deleteIn('drivers', 'id', $driverIds);
        $this->deleteIn('employees', 'id', $employeeIds);
        $this->deleteIn('trucks', 'id', $truckIds);
        DB::table('trailers')->where('registration_no', 'like', '%RKO')->delete();
        DB::table('routes')->where('code', 'like', 'RKO%')->delete();
        DB::table('customers')->where('code', 'like', 'RKO%')->delete();
    }

    /**
     * `whereIn(char(26)_column, [])` is harmless; the first drafts of these scripts passed `[0]`, and MySQL
     * compared a ULID column against an integer and aborted the statement.
     *
     * @param  list<int|string>  $ids
     */
    private function deleteIn(string $table, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::table($table)->whereIn($column, $ids)->delete();
    }

    private function assertNothingSurvives(): void
    {
        $counts = [
            'fixture jobs' => DB::table('freight_jobs')->where('notes', 'like', 'RKO%')->count(),
            'fixture trucks' => DB::table('trucks')->where('registration_no', 'like', '%RKO')->count(),
            'fixture drivers' => DB::table('employees')->where('employee_no', 'like', 'EMP-RKO%')->count(),
            'fixture routes' => DB::table('routes')->where('code', 'like', 'RKO%')->count(),
            'fixture customers' => DB::table('customers')->where('code', 'like', 'RKO%')->count(),
        ];

        foreach ($counts as $label => $count) {
            if ($count !== 0) {
                throw new Exception("{$count} {$label} survived cleanup");
            }
        }

        echo "  cleanup: no RKO fixtures remain\n";
    }

    /**
     * The `job`/`trip` counters are compared against the rows by `documents:number-integrity`; leaving this
     * run's numbers spent with its fixtures deleted would report drift forever.
     */
    private function alignCounters(string $when): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
            $highest = DB::table($table)
                ->where('company_id', $this->ownerCompany ?? 1)
                ->pluck($column)
                ->map(static function (string $number): int {
                    $dash = strpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                })
                ->max() ?? 0;

            DB::table('number_sequences')
                ->where('company_id', $this->ownerCompany ?? 1)
                ->where('scope', $scope)
                ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        }

        CurrentCompany::forget();

        echo "  counters aligned to the surviving rows ({$when})\n";
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;

            // PHPUnit's default renderer exports the whole page and then trips its own assertion outside a
            // test run; the message is the part that names the defect.
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";

            if (getenv('RENDER_KIO_TRACE') !== false) {
                echo $exception->getTraceAsString()."\n";
            }
        }
    }

    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 240);
    }
}

$sweep = new KioskRenderSweep('drive');

try {
    $sweep->drive();
} catch (Throwable $fatal) {
    echo "\nFATAL — ".$fatal->getMessage()."\n";

    if (getenv('RENDER_KIO_TRACE') !== false) {
        echo $fatal->getTraceAsString()."\n";
    }

    exit(2);
}

echo "\n".($sweep->failures === 0 ? 'PASS' : 'FAIL')." — {$sweep->failures} failed\n";

exit($sweep->failures === 0 ? 0 : 1);
