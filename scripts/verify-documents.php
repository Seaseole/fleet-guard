<?php

declare(strict_types=1);

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\AuditTrailIsAppendOnly;
use App\Domain\Kernel\Audit\Models\OperationalEvent;
use App\Domain\Kernel\Audit\Models\SecurityEvent;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\Actions\DeleteDocumentVersion;
use App\Domain\Kernel\Documents\Actions\DownloadDocument;
use App\Domain\Kernel\Documents\Actions\RegisterDocument;
use App\Domain\Kernel\Documents\Actions\TransitionDocument;
use App\Domain\Kernel\Documents\Actions\UploadDocumentVersion;
use App\Domain\Kernel\Documents\ContentSniffer;
use App\Domain\Kernel\Documents\DocumentInput;
use App\Domain\Kernel\Documents\DocumentLink;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Exceptions\DocumentNotDownloadable;
use App\Domain\Kernel\Documents\Exceptions\DocumentVersionIsImmutable;
use App\Domain\Kernel\Documents\Exceptions\InvalidDocumentFile;
use App\Domain\Kernel\Documents\Exceptions\LifecycleTransitionRefused;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use App\Domain\Kernel\Documents\ScanVerdict;
use App\Domain\Kernel\Documents\Support\DocumentIntegrityVerifier;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Phase 0 documents verification (DESIGN.md §20.7).
 *
 * Not the test suite — `tests/` is local-only by owner decision (§14.6). This is the runnable proof
 * of §14.4 and §10.8: that a file is judged by its content, that one version per document is
 * current in the database rather than in the code, that a download is signed, refused, audited and
 * uncacheable, and that a version's bytes cannot be edited after the fact.
 *
 * It writes to the development database and to the private disk, and removes everything it created —
 * including the second company and the temporary staff account the isolation and visibility checks
 * need. `finally` rather than a tidy ending, because a verifier that leaves a stray company behind
 * when it fails is a verifier nobody re-runs.
 *
 * Run: php scripts/verify-documents.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

CurrentCompany::force(1);

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

/** Assert the callable throws the named class, and report what it said. */
function refuses(string $what, callable $fn, string $expectClass): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        if ($e instanceof $expectClass) {
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 100)));
        } else {
            failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());
        }
    }
}

/**
 * Every CHECK is measured in both directions: the row it names must be refused, and the same row
 * corrected must be accepted.
 *
 * Only the first half is obvious, and it is the half that hides the real defect. A constraint whose
 * expression reaches UNKNOWN — a nullable column compared without an `is not null` — passes the
 * violating row and looks identical to a working one until a query proves otherwise, because nothing
 * on the refusing side ever says "it should have refused that". The second half guards the opposite
 * mistake: a CHECK that refuses everything also passes every "bad row refused" assertion.
 *
 * The accepted inserts are rolled back rather than deleted: this run proves constraints, it does not
 * populate tables.
 *
 * @param  array<string, mixed>  $row
 */
function checkRefuses(string $table, array $row, string $what): void
{
    refuses($what, function () use ($table, $row): void {
        DB::table($table)->insert($row);
    }, QueryException::class);
}

/** @param  array<string, mixed>  $row */
function checkAccepts(string $table, array $row, string $what): void
{
    try {
        DB::beginTransaction();
        DB::table($table)->insert($row);
        DB::rollBack();
        ok($what);
    } catch (Throwable $e) {
        DB::rollBack();
        failed($what, $e->getMessage());
    }
}

/**
 * Does this request survive the route's `signed` middleware?
 *
 * The exception is the answer, so it is caught here rather than allowed to end the run: a link that
 * passes when it should not is the finding, and it is the same class of finding whether the reason is
 * a forged signature, an expired one or a swapped document id.
 */
function passesSignature(ValidateSignature $middleware, Request $request): bool
{
    $passed = false;

    try {
        $middleware->handle($request, function () use (&$passed): void {
            $passed = true;
        });
    } catch (InvalidSignatureException) {
        // the answer wanted
    }

    return $passed;
}

/**
 * A real PDF and a real PNG, not a signature followed by nonsense: fileinfo reads the container, and
 * a fixture it cannot parse arrives as `application/octet-stream` — which would make the allowlist
 * check pass for the wrong reason.
 *
 * @return array<string, string> label => absolute path
 */
function fixtures(): array
{
    $dir = sys_get_temp_dir().'/fleetguard-documents-'.Str::random(6);
    mkdir($dir, 0700, true);

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    $pngPath = $dir.'/photo.png';
    $image = imagecreatetruecolor(120, 80);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 24, 96, 160));
    imagepng($image, $pngPath);
    $png = (string) file_get_contents($pngPath);
    imagedestroy($image);

    $jpgImage = imagecreatetruecolor(60, 40);
    imagefill($jpgImage, 0, 0, (int) imagecolorallocate($jpgImage, 200, 180, 40));
    ob_start();
    imagejpeg($jpgImage, null, 85);
    $jpg = (string) ob_get_clean();
    imagedestroy($jpgImage);

    return [
        'pdf' => put($dir.'/scan.pdf', $pdf),
        'jpg' => put($dir.'/photo.jpg', $jpg),
        'png' => put($dir.'/photo.png', $png),
        'php' => put($dir.'/invoice.pdf', "<?php echo 'this is not a document';\n"),
        'big' => put($dir.'/big.pdf', str_pad("%PDF-1.4\n", 9 * 1048576, '0')),
    ];
}

function put(string $path, string $contents): string
{
    file_put_contents($path, $contents);

    return $path;
}

/** @var array<string, string> $files */
$files = fixtures();

$store = app(DocumentStore::class);
$register = app(RegisterDocument::class);
$upload = app(UploadDocumentVersion::class);
$transition = app(TransitionDocument::class);
$delete = app(DeleteDocumentVersion::class);
$link = app(DocumentLink::class);
$audit = app(AuditRecorder::class);

$owner = User::query()->findOrFail(1);
$createdIds = [];
$companyIds = [];
$userIds = [];

echo "\n== fixtures ==\n";
foreach ($files as $label => $path) {
    printf("  %-4s %9d bytes  sniffed=%s\n", $label, filesize($path), (new ContentSniffer)->sniff($path));
}

try {
    // ---------------------------------------------------------------- content, not extension
    echo "\n== §14.4 the file is judged by what it is ==\n";

    $licence = $register->handle(
        DocumentType::Licence,
        'verify: driver licence code A',
        expiresOn: now()->addDays(200),
    );
    $createdIds[] = $licence->id;

    check($licence->lifecycle === Lifecycle::Draft, 'a register entry starts as draft, not current');
    check($licence->currentVersion === null, 'a draft has no bytes', 'found '.optional($licence->currentVersion)->id);
    check($licence->validity() === Validity::Valid, 'validity derives 200 days out', (string) $licence->validity()->value);

    $v1 = $upload->handle($licence, DocumentInput::fromPath($files['pdf']), $owner->id);
    $createdIds[] = $v1->id;

    check($v1->version_seq === 1, 'the first version is sequence 1');
    check($v1->mime === 'application/pdf', 'the stored MIME is what was sniffed', $v1->mime);
    check($v1->sha256 === hash_file('sha256', $files['pdf']), 'the digest is of the bytes that were stored');
    check($v1->size_bytes === (int) filesize($files['pdf']), 'the size is of the bytes that were stored');
    check(
        (bool) preg_match('#^company/1/licence/unfiled/[0-9A-HJKMNP-TV-Z]{26}/1$#', $v1->path),
        'the path is company/{id}/{type}/{entity}/{ulid}/{version} (§14.4)',
        $v1->path,
    );
    check($store->exists($v1), 'the object is on the private disk');
    check($licence->refresh()->lifecycle === Lifecycle::Current, 'publishing the first version makes the document current');

    // The name says PDF; the bytes say PHP. §14.4's whole sentence is about this file.
    refuses('a file named .pdf whose content is source code is refused', function () use ($upload, $licence, $files): void {
        $upload->handle($licence, DocumentInput::fromPath($files['php'], 'application/pdf', 'invoice.pdf'));
    }, InvalidDocumentFile::class);

    check(DocumentVersion::query()->where('document_id', $licence->id)->count() === 1, 'the refused file created no version row');
    check(! Storage::disk(DocumentStore::Disk)->exists(str_replace('/1', '/2', $v1->path)), 'and wrote no bytes');

    refuses('a file over the type cap is refused', function () use ($upload, $licence, $files): void {
        $upload->handle($licence, DocumentInput::fromPath($files['big'], 'application/pdf'));
    }, InvalidDocumentFile::class);

    check((int) DB::table('documents')->where('id', $licence->id)->value('created_at') > 0, 'the document survived its refusals');

    // ---------------------------------------------------------------- §10.8 one current version
    echo "\n== §10.8 one current version, and the history underneath it ==\n";

    $v2 = $upload->handle($licence, DocumentInput::fromPath($files['png']), $owner->id, 're-scanned: first copy was unreadable');
    $createdIds[] = $v2->id;

    check($v2->version_seq === 2, 'the next version is sequence 2');
    check($v2->is_current, 'the new version is the current one');
    check(! $v1->refresh()->is_current, 'the old version is no longer current');
    check($v1->refresh()->replaced_reason === 're-scanned: first copy was unreadable', 'the supersede recorded why');
    check((int) DocumentVersion::query()->where('document_id', $licence->id)->where('is_current', true)->count() === 1, 'exactly one current copy in the index');

    // The application is not the layer being proved here: this insert goes around every model, cast
    // and Action, and the unique index across the STORED generated column is what refuses it.
    $forgedId = (string) Str::ulid();
    refuses('the database refuses a second current version even for raw SQL', function () use ($forgedId, $licence): void {
        DB::table('document_versions')->insert([
            'id' => $forgedId,
            'company_id' => 1,
            'document_id' => $licence->id,
            'version_seq' => 99,
            'disk' => DocumentStore::Disk,
            'path' => 'company/1/licence/'.$forgedId.'/99',
            'mime' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'is_current' => true,
            'virus_scan_status' => 'pending',
            'uploaded_at' => now(),
        ]);
    }, QueryException::class);

    refuses('and refuses a second version with the same sequence', function () use ($licence, $v2): void {
        DB::table('document_versions')->insert([
            'id' => (string) Str::ulid(),
            'company_id' => 1,
            'document_id' => $licence->id,
            'version_seq' => $v2->version_seq,
            'disk' => DocumentStore::Disk,
            'path' => 'company/1/licence/probe/x',
            'mime' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'is_current' => false,
            'virus_scan_status' => 'pending',
            'uploaded_at' => now(),
        ]);
    }, QueryException::class);

    check(DocumentVersion::query()->where('id', $forgedId)->doesntExist(), 'and left nothing behind');

    // A scan result with no scan time is a claim, not a record.
    refuses('a clean verdict without a scan time is refused by the CHECK', function () use ($licence): void {
        DB::table('document_versions')->insert([
            'id' => (string) Str::ulid(), 'company_id' => 1, 'document_id' => $licence->id, 'version_seq' => 77,
            'disk' => DocumentStore::Disk, 'path' => 'company/1/licence/probe/77', 'mime' => 'application/pdf',
            'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'is_current' => false,
            'virus_scan_status' => 'clean', 'uploaded_at' => now(),
        ]);
    }, QueryException::class);

    // ---------------------------------------------------------------- immutability
    echo "\n== every CHECK, measured in both directions ==\n";

    $docRow = [
        'id' => (string) Str::ulid(), 'company_id' => 1, 'type' => 'licence', 'title' => 'probe',
        'lifecycle' => 'draft', 'retention_class' => 'operational',
    ];

    checkRefuses('documents', ['type' => 'warrant_of_fitness'] + $docRow, 'documents_type_allowed refuses a type outside the register');
    checkRefuses('documents', ['lifecycle' => 'retired'] + $docRow, 'documents_lifecycle_allowed refuses a state §10.8 does not have');
    checkRefuses('documents', ['retention_class' => 'forever'] + $docRow, 'documents_retention_allowed refuses an unclassified hold');
    checkRefuses('documents', ['morph_id' => (string) Str::ulid()] + $docRow, 'documents_subject_is_a_pair refuses half a subject');
    checkRefuses('documents', ['lifecycle' => 'archived'] + $docRow, 'documents_retirement_dated refuses an archive with no date');
    checkRefuses('documents', ['lifecycle' => 'voided', 'voided_at' => now()] + $docRow, 'documents_void_explained refuses a void with no reason');
    checkAccepts('documents', ['morph_type' => 'trip', 'morph_id' => (string) Str::ulid()] + $docRow, 'a complete subject pair is a legal row');
    checkAccepts('documents', ['lifecycle' => 'archived', 'archived_at' => now()] + $docRow, 'a dated archive is a legal row');
    checkAccepts('documents', ['lifecycle' => 'voided', 'voided_at' => now(), 'void_reason' => 'superseded by the regulator copy'] + $docRow, 'a void that says why is a legal row');

    $versionRow = [
        'id' => (string) Str::ulid(), 'company_id' => 1, 'document_id' => $licence->id, 'version_seq' => 900,
        'disk' => DocumentStore::Disk, 'path' => 'company/1/licence/probe/900', 'mime' => 'application/pdf',
        'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'is_current' => false,
        'virus_scan_status' => 'pending', 'uploaded_at' => now(),
    ];

    checkRefuses('document_versions', ['virus_scan_status' => 'suspect'] + $versionRow, 'document_versions_scan_status_allowed refuses a verdict with no behaviour behind it');
    checkRefuses('document_versions', ['version_seq' => 0] + $versionRow, 'document_versions_seq_positive refuses a version numbered zero');
    checkRefuses('document_versions', ['size_bytes' => 0] + $versionRow, 'document_versions_size_recorded refuses a version with no bytes');
    checkRefuses('document_versions', ['size_bytes' => -1] + $versionRow, '…and a negative one (the unsigned column, not the CHECK — which is why the CHECK is written as > 0)');
    checkRefuses('document_versions', ['path' => '   '] + $versionRow, 'document_versions_address_present refuses a version with no address');
    checkRefuses('document_versions', ['sha256' => str_repeat('a', 63)] + $versionRow, '…and a digest that is not a SHA-256');
    checkRefuses('document_versions', ['mime' => ''] + $versionRow, '…and a version that does not say what it is');
    checkRefuses('document_versions', ['virus_scan_status' => 'clean'] + $versionRow, 'document_versions_scan_recorded refuses a clean claim with no scan behind it');
    checkAccepts('document_versions', ['virus_scan_status' => 'clean', 'scanned_at' => now()] + $versionRow, 'a clean verdict with its time is a legal row');
    checkAccepts('document_versions', $versionRow, 'a pending verdict with no time is a legal row — that is the state §17 Q8 leaves open');

    checkRefuses('security_events', [
        'company_id' => 1, 'kind' => 'document_emailed_outside', 'happened_at' => now(),
    ], 'security_events_kind_known refuses a kind nobody can query for');
    checkAccepts('security_events', [
        'company_id' => 1, 'kind' => 'document_downloaded', 'happened_at' => now(),
    ], 'and accepts the kind §14.4 writes');

    $eventRow = [
        'company_id' => 1, 'aggregate_type' => 'documents', 'aggregate_id' => (string) Str::ulid(),
        'from_state' => 'current', 'occurred_at' => now(), 'created_at' => now(), 'actor_kind' => 'user',
    ];

    checkRefuses('operational_events', ['to_state' => 'current', 'actor_kind' => 'admin'] + $eventRow, 'operational_events_actor_kind_allowed refuses a principal §9.2 does not define');
    checkRefuses('operational_events', ['to_state' => 'voided', 'reason' => null] + $eventRow, 'operational_events_destructive_explained refuses a void with a NULL reason');
    checkRefuses('operational_events', ['to_state' => 'voided', 'reason' => ''] + $eventRow, '…and with an empty one');
    checkRefuses('operational_events', ['to_state' => 'voided', 'reason' => '     '] + $eventRow, '…and with whitespace, which is the shape of a form that "answered"');
    checkRefuses('operational_events', ['to_state' => 'reversed', 'reason' => null] + $eventRow, '…and on every destructive state in the list');
    checkAccepts('operational_events', ['to_state' => 'voided', 'reason' => 'licence withdrawn by the authority'] + $eventRow, 'a destructive transition that says why is a legal row');
    checkAccepts('operational_events', ['to_state' => 'current', 'reason' => null] + $eventRow, 'and a non-destructive one is not made to invent a reason');

    echo "\n== §10.8 the bytes a version describes are immutable ==\n";

    refuses('editing the stored path is refused in code', function () use ($v1): void {
        $v1->forceFill(['path' => 'company/1/licence/somewhere/else/1'])->save();
    }, DocumentVersionIsImmutable::class);

    refuses('editing the digest is refused in code', function () use ($v1): void {
        $v1->forceFill(['sha256' => str_repeat('f', 64)])->save();
    }, DocumentVersionIsImmutable::class);

    $v1->forceFill(['scan_detail' => 'written off in error — corrected by the same person who wrote it'])->save();
    check($v1->refresh()->scan_detail !== null, 'the mutable columns still move: immutability is of the bytes, not the row');

    refuses('a new version cannot be filed against a voided document', function () use ($licence, $transition, $upload, $files): void {
        $transition->handle($licence, Lifecycle::Voided, 'verify: the licence was withdrawn');
        $upload->handle($licence, DocumentInput::fromPath($files['pdf']));
    }, LifecycleTransitionRefused::class);

    check($licence->refresh()->lifecycle === Lifecycle::Voided, 'the void itself happened before the upload refused');
    check($licence->voided_at !== null && $licence->void_reason === 'verify: the licence was withdrawn', 'and dated the record (§4.4 retirement_dated)');

    // ---------------------------------------------------------------- §4.4 a destructive move needs a reason
    echo "\n== §4.4 and §10.1 the trail ==\n";

    $unvoided = $register->handle(DocumentType::Permit, 'verify: permit that will not be voidable without a reason');
    $createdIds[] = $unvoided->id;
    refuses('voiding without a reason is refused by the action', function () use ($unvoided, $transition): void {
        $transition->handle($unvoided, Lifecycle::Voided, null);
    }, LifecycleTransitionRefused::class);
    refuses('and draft to archived is not a transition at all', function () use ($unvoided, $transition): void {
        $transition->handle($unvoided, Lifecycle::Archived);
    }, LifecycleTransitionRefused::class);

    $reasonless = (string) Str::ulid();
    refuses('the operational_events CHECK refuses a destructive transition with no reason', function () use ($reasonless): void {
        DB::table('operational_events')->insert([
            'company_id' => 1, 'aggregate_type' => 'documents', 'aggregate_id' => $reasonless,
            'from_state' => 'current', 'to_state' => 'voided', 'actor_kind' => 'user',
            'reason' => null, 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }, QueryException::class);

    $ops = OperationalEvent::query()->where('aggregate_id', $licence->id)->orderBy('id')->get();
    check($ops->count() >= 2, 'the lifecycle is reconstructable from rows, not asserted', 'found '.$ops->count());
    check($ops->contains(fn (OperationalEvent $e): bool => $e->to_state === 'current' && $e->from_state === 'draft'), 'the publish is on the trail');
    check($ops->contains(fn (OperationalEvent $e): bool => $e->to_state === 'voided' && $e->reason === 'verify: the licence was withdrawn'), 'the void carries its reason');

    check(SecurityEvent::query()->where('kind', 'document_uploaded')->where('meta->document_id', $licence->id)->count() === 2, 'both accepted uploads were audited', 'count='.(string) SecurityEvent::query()->where('kind', 'document_uploaded')->count());
    check(SecurityEvent::query()->where('kind', 'document_voided')->where('meta->document_id', $licence->id)->exists(), 'the void is on the security trail too (§4.4)');

    refuses('the audit trail refuses an update', function (): void {
        $event = SecurityEvent::query()->where('kind', 'document_uploaded')->firstOrFail();
        $event->forceFill(['kind' => 'mfa_enabled'])->save();
    }, AuditTrailIsAppendOnly::class);

    refuses('and refuses a delete', function (): void {
        SecurityEvent::query()->where('kind', 'document_uploaded')->firstOrFail()->delete();
    }, AuditTrailIsAppendOnly::class);

    check(SecurityEventKind::DocumentDownloaded->value === 'document_downloaded', 'the download kind the route writes is the kind the CHECK allows');

    // ---------------------------------------------------------------- signed download
    echo "\n== §14.4 signed, refused, audited, uncacheable ==\n";

    // A live document for the download checks: the voided one must refuse.
    $insurance = $register->handle(DocumentType::Insurance, 'verify: insurance certificate', expiresOn: now()->addDays(20));
    $createdIds[] = $insurance->id;
    $good = $upload->handle($insurance, DocumentInput::fromPath($files['pdf']), $owner->id);
    $createdIds[] = $good->id;

    $url = $link->temporaryFor($good);
    check(str_contains($url, 'signature=') && str_contains($url, 'expires='), 'the download URL is signed and time-limited');
    check($link->ttlSeconds() === 60, 'the default TTL is §14.4 sixty seconds', 'ttl='.(string) $link->ttlSeconds());

    $route = Route::getRoutes()->getByName('documents.download');
    check($route !== null, 'the download route exists');
    /** @var list<string> $routeMiddleware */
    $routeMiddleware = array_map(
        static fn ($m): string => is_string($m) ? $m : $m::class,
        $route->gatherMiddleware(),
    );
    check(in_array('signed', $routeMiddleware, true), 'signature checking is on the route, not in the controller', implode(',', $routeMiddleware));
    check(in_array('auth', $routeMiddleware, true), 'and so is the session: a signature is not an authorisation', implode(',', $routeMiddleware));

    // The middleware itself, on requests built from the absolute URL it would actually see: the
    // signature covers the whole URL including its scheme and host, so a request made from a path
    // alone can only ever fail.
    $signatureMiddleware = new ValidateSignature($app['router']->getRoutes());

    $passed = false;
    $signatureMiddleware->handle(Request::create($url), function () use (&$passed): void {
        $passed = true;
    });
    check($passed, 'the real signature passes the real middleware');

    /** @var array<string, string> $query */
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $forged = str_replace((string) $query['signature'], str_repeat('a', 64), $url);
    check(! passesSignature($signatureMiddleware, Request::create($forged)), 'a forged signature does not pass');

    // A link minted already-expired is the only way to test the TTL without sleeping for it: the
    // signature itself is valid, so this fails on `expires` and nothing else.
    $expiredUrl = URL::temporarySignedRoute(
        'documents.download',
        now()->subSeconds(120),
        ['documentVersion' => $good->id],
    );
    check(! passesSignature($signatureMiddleware, Request::create($expiredUrl)), 'an expired link does not pass either');

    $foreign = str_replace('/documents/versions/'.$good->id.'/', '/documents/versions/'.Str::ulid()->toBase32().'/', $url);
    check(! passesSignature($signatureMiddleware, Request::create($foreign)), 'and a valid signature does not carry a different document version');

    $downloaded = app(DownloadDocument::class)->handle($good, $owner->id, 'verify');
    check($downloaded->getStatusCode() === 200, 'the action serves the file');
    /** @var string $body */
    $body = '';
    ob_start();
    $callback = $downloaded->getCallback();
    $callback();
    $body = (string) ob_get_clean();
    check($body === (string) file_get_contents($files['pdf']), 'and the bytes are the ones that were stored');
    check(str_contains((string) $downloaded->headers->get('Cache-Control'), 'no-store'), 'Cache-Control says no-store (§14.4)', (string) $downloaded->headers->get('Cache-Control'));
    check(str_contains((string) $downloaded->headers->get('Cache-Control'), 'private'), 'and private', (string) $downloaded->headers->get('Cache-Control'));
    check($downloaded->headers->get('X-Content-Type-Options') === 'nosniff', 'and the browser is told not to interpret it');
    check(str_contains((string) $downloaded->headers->get('Content-Disposition'), 'attachment'), 'served as an attachment, never inline');
    check($downloaded->headers->get('Content-Type') === 'application/pdf', 'with the sniffed type', (string) $downloaded->headers->get('Content-Type'));

    $trail = SecurityEvent::query()->where('kind', 'document_downloaded')->orderByDesc('id')->first();
    check($trail !== null && (string) $trail->meta['document_id'] === $insurance->id, 'the download is on the audit trail with the document id');
    check($trail !== null && $trail->user_id === $owner->id, 'and with the actor');
    check($trail !== null && is_string($trail->ip_address) && $trail->ip_address !== '', 'and the address it came from', 'ip='.var_export($trail->ip_address ?? null, true));
    check($trail !== null && (string) $trail->meta['sha256'] === $good->sha256, 'and which copy was served');

    refuses('a voided document is refused', function () use ($v2): void {
        app(DownloadDocument::class)->handle($v2);
    }, DocumentNotDownloadable::class);

    // The scan gate, measured rather than assumed: no scanner here, so a pending verdict serves and
    // an infected one never does (§17 Q8's honest interim rule).
    $good->forceFill(['virus_scan_status' => ScanVerdict::Infected, 'scanned_at' => now(), 'scan_detail' => 'verify: simulated find'])->save();
    refuses('infected bytes are refused', function () use ($good): void {
        app(DownloadDocument::class)->handle($good);
    }, DocumentNotDownloadable::class);
    refuses('and no link is minted for them', function () use ($link, $good): void {
        $link->temporaryFor($good);
    }, DocumentNotDownloadable::class);
    $good->forceFill(['virus_scan_status' => ScanVerdict::Pending, 'scanned_at' => null, 'scan_detail' => null])->save();
    check($good->refresh()->isServable(), 'with no scanner configured, pending bytes serve (§17 Q8 documented in the config)');

    // ---------------------------------------------------------------- integrity sweep
    echo "\n== §4.4 sha256 means something: documents:verify-integrity ==\n";

    $integrity = app(DocumentIntegrityVerifier::class);

    check($store->digest($v2) === $v2->sha256, 'the sweep re-reads the stored bytes and agrees with the row');
    check($integrity->findings() === [], 'and reports nothing about an honest archive', json_encode($integrity->findings()));

    // The failure the digest exists for: the object changes underneath a row that still looks healthy.
    Storage::disk(DocumentStore::Disk)->put($v2->path, 'a different file entirely, with fewer bytes');
    $drift = $integrity->findings();
    check(collect($drift)->contains(fn (array $f): bool => $f['kind'] === 'digest' && $f['version_id'] === $v2->id), 'a replaced object is reported as a digest finding', json_encode($drift));
    // The command, not just the class: exit 1 is what makes the scheduler alarm rather than log (§14.6).
    check(Artisan::call('documents:verify-integrity') === 1, 'the scheduled command exits 1 on drift, so the scheduler alarms rather than logs');

    Storage::disk(DocumentStore::Disk)->put($v2->path, (string) file_get_contents($files['png']));
    check($integrity->findings() === [], 'restoring the bytes clears the finding');

    Storage::disk(DocumentStore::Disk)->put('company/1/licence/orphan/probe/1', 'nobody owns these bytes');
    check(collect($integrity->findings())->contains(fn (array $f): bool => $f['kind'] === 'orphan bytes'), 'bytes with no index row behind them are reported');
    Storage::disk(DocumentStore::Disk)->delete('company/1/licence/orphan/probe/1');

    // A row pointing at an object that is not there. Built with raw SQL because the model guard is the
    // thing that makes this state unreachable from inside the application — which is exactly why the
    // sweep has to be able to see it anyway: the risk is the disk, not the app.
    $ghostId = (string) Str::ulid();
    DB::table('document_versions')->insert([
        'id' => $ghostId, 'company_id' => 1, 'document_id' => $insurance->id, 'version_seq' => 500,
        'disk' => DocumentStore::Disk, 'path' => 'company/1/insurance/never/written/500', 'mime' => 'application/pdf',
        'size_bytes' => 12, 'sha256' => str_repeat('b', 64), 'is_current' => false,
        'virus_scan_status' => 'pending', 'uploaded_at' => now(),
    ]);
    check(collect($integrity->findings())->contains(fn (array $f): bool => $f['kind'] === 'missing' && $f['version_id'] === $ghostId), 'a row whose object is gone is reported as missing', json_encode($integrity->findings()));
    // Attached to the live insurance document, not the voided licence: the refusal must be the one that
    // names the missing object, and a voided document is refused for its lifecycle before it ever looks
    // at the disk.
    refuses('and the download gate refuses it rather than serving nothing', function () use ($ghostId): void {
        app(DownloadDocument::class)->handle(DocumentVersion::query()->findOrFail($ghostId));
    }, DocumentNotDownloadable::class);
    DB::table('document_versions')->where('id', $ghostId)->delete();
    check($integrity->findings() === [], 'and the archive is clean again');

    // ---------------------------------------------------------------- delete
    echo "\n== §10.8 deleting a version removes the bytes with it ==\n";

    $path = $good->path;
    $delete->handle($good, 'verify: superseded by the broker copy', $owner->id);
    check(! Storage::disk(DocumentStore::Disk)->exists($path), 'the object is gone from the disk');
    check(DocumentVersion::query()->where('id', $good->id)->doesntExist(), 'and the row that pointed at it');
    check((int) DocumentVersion::query()->where('document_id', $insurance->id)->where('is_current', true)->count() === 0, 'the document now has no current copy');
    check(SecurityEvent::query()->where('kind', 'document_version_deleted')->where('meta->document_id', $insurance->id)->exists(), 'the delete is audited with the digest it removed');
    refuses('a delete needs a reason even though the CHECK cannot see it', function () use ($v1, $delete): void {
        $delete->handle($v1, '   ');
    }, InvalidArgumentException::class);

    // ---------------------------------------------------------------- tenancy + visibility
    echo "\n== §8 T3 and §14.4 visibility ==\n";

    $other = Company::create([
        'name' => 'Verify Second Fleet',
        'registration_number' => 'VERIFY-2',
        'base_currency_code' => 'BWP',
        'timezone' => 'Africa/Gaborone',
    ]);
    $companyIds[] = (string) $other->id;

    CurrentCompany::force((int) $other->id);
    $foreign = $register->handle(DocumentType::Contract, 'verify: another company\'s contract');
    $createdIds[] = $foreign->id;

    CurrentCompany::force(1);
    check(Document::query()->where('id', $foreign->id)->doesntExist(), 'a scoped read in company 1 cannot see company 2\'s document');
    check(DocumentVersion::query()->where('document_id', $foreign->id)->doesntExist(), 'nor its versions');
    check(str_starts_with($store->pathFor($foreign, 'x', 1), 'company/'.$other->id.'/'), 'and its bytes would have gone under its own company prefix (§14.4)', $store->pathFor($foreign, 'x', 1));
    check(str_starts_with($good->path, 'company/1/'), 'while company 1\'s stayed under company/1', $good->path);

    $restricted = $register->handle(
        DocumentType::Contract,
        'verify: restricted to the auditor',
        visibilityRoles: ['auditor'],
        confidential: true,
    );
    $createdIds[] = $restricted->id;

    $auditor = User::create([
        'name' => 'Verify Auditor',
        'email' => 'verify-auditor-'.Str::random(6).'@example.invalid',
        'password' => 'not-a-real-password',
        'company_id' => 1,
    ]);
    $userIds[] = $auditor->id;
    $auditor->assignRole('auditor');

    $dispatcher = User::create([
        'name' => 'Verify Dispatcher',
        'email' => 'verify-dispatch-'.Str::random(6).'@example.invalid',
        'password' => 'not-a-real-password',
        'company_id' => 1,
    ]);
    $userIds[] = $dispatcher->id;
    $dispatcher->assignRole('dispatcher');

    check(Gate::forUser($auditor)->allows('view', $restricted), 'the named role sees the restricted paper');
    check(Gate::forUser($dispatcher)->denies('view', $restricted), 'a role it does not name does not');
    check(Gate::forUser($owner)->allows('view', $restricted), 'the Owner is never locked out of their own register (§9.3)');
    check(Gate::forUser($dispatcher)->allows('view', $licence), 'an unrestricted document stays visible to everyone holding documents.view');
    check(Gate::forUser($dispatcher)->denies('download', $restricted), 'and the download gate asks the same question as the list');
} finally {
    echo "\n== cleanup ==\n";

    foreach ($userIds as $id) {
        // spatie v8 keys the pivot on the polymorphic pair (`model_type`, `model_id`), not `user_id`:
        // deleting by the wrong column here would leave the grant pointing at a row that no longer
        // exists, and an orphan pivot is invisible until someone reuses an id.
        DB::table('model_has_roles')->where('model_id', $id)->delete();
        DB::table('model_has_permissions')->where('model_id', $id)->delete();
        DB::table('security_events')->where('user_id', $id)->delete();
        DB::table('users')->where('id', $id)->delete();
    }

    foreach ($companyIds as $id) {
        DB::table('operational_events')->where('company_id', $id)->delete();
        DB::table('security_events')->where('company_id', $id)->delete();
    }

    // The disk first, then the rows: a row deleted without its object leaves an orphan nobody can
    // name, and this run created both together.
    foreach (Document::withoutGlobalScopes()->whereIn('id', $createdIds)->get() as $document) {
        foreach (DocumentVersion::withoutGlobalScopes()->where('document_id', $document->id)->get() as $version) {
            Storage::disk($version->disk)->delete($version->path);
        }
    }

    $documentIds = Document::withoutGlobalScopes()->whereIn('id', $createdIds)->pluck('id')->all();

    if ($documentIds !== []) {
        DB::table('document_versions')->whereIn('document_id', $documentIds)->delete();
        DB::table('documents')->whereIn('id', $documentIds)->delete();
    }

    DB::table('operational_events')->where('aggregate_type', 'documents')->whereIn('aggregate_id', $createdIds)->delete();

    // By id, not by a marker in the title: the first version of this line filtered on
    // `meta->document_id like '%verify%'`, which matches nothing because that column holds a ULID. Every
    // run then left its audit rows behind — invisible, since the checks above all passed. A cleanup that
    // silently cleans nothing is worse than no cleanup, so the count is printed and asserted.
    $removedEvents = DB::table('security_events')
        ->whereIn('meta->document_id', $createdIds)
        ->orWhereIn('meta->version_id', $createdIds)
        ->delete();

    echo '  audit rows removed: '.$removedEvents.' where meta names one of '.count($createdIds)." fixture ids\n";

    check($removedEvents > 0, 'the run removed its own audit rows');
    check(DB::table('security_events')->whereIn('meta->document_id', $createdIds)->count() === 0, 'and none are left');

    foreach ($companyIds as $id) {
        DB::table('companies')->where('id', $id)->delete();
    }

    $orphanDirs = glob(sys_get_temp_dir().'/fleetguard-documents-*');
    foreach ((array) $orphanDirs as $dir) {
        if (is_dir($dir)) {
            foreach ((array) glob($dir.'/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    // Deleting the objects is not the end of the residue: Laravel's `put` created the whole path
    // skeleton — `company/1/licence/unfiled/<version>/` — and nothing removes it. A crashed run used to
    // leave forty of those behind, which reads as archive drift to anyone who lists the disk. Pruned
    // deepest-first, so a directory only goes once it holds nothing: a directory with someone else's
    // real bytes in it is never touched, and that is why `rmdir` (not a recursive delete) is the tool.
    $root = Storage::disk(DocumentStore::Disk)->path('');
    $prunedDirectories = 0;

    if (is_dir($root)) {
        $walking = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($walking as $entry) {
            if ($entry->isDir() && @rmdir($entry->getPathname()) !== false) {
                $prunedDirectories++;
            }
        }
    }

    // The inverse of the sweep's question: the sweep asks whether every row has bytes behind it; this
    // asks whether the disk still holds bytes with no row — which is what a crashed run leaves.
    $bytesWithNoRow = 0;

    $remaining = Storage::disk(DocumentStore::Disk)->allFiles();
    $indexed = DocumentVersion::withoutGlobalScopes()->pluck('path')->all();

    foreach ($remaining as $path) {
        if (! in_array($path, $indexed, true)) {
            $bytesWithNoRow++;
        }
    }

    echo '  empty directories pruned: '.$prunedDirectories."\n";

    check($bytesWithNoRow === 0, 'the private disk holds no bytes without an index row behind them');
    check($remaining === [], 'and after this run it holds nothing at all');

    echo '  fixture directories removed; verify rows removed'."\n";
}

echo "\n== result ==\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
