<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Identity\ConfirmsRecentPassword;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\LimitConfirmationAttempts;
use App\Domain\Kernel\Identity\PermissionCatalogue;
use App\Domain\Kernel\Identity\SensitiveActionMap;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\ResolveCurrentCompany;
use App\Domain\Security\Sessions\TrackUserSessions;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Security review as a command, not a checklist somebody remembers.
 *
 * Five questions, each answered from the code rather than from a document:
 *
 * 1. **Is anything reachable without `auth`, and is anything authenticated reachable without a
 *    permission?** Every route is tested against a public allowlist whose entries carry their reason
 *    (login, the invitation link, the kiosk's token door). An app route that forgets its `can:` shows
 *    up here instead of in a pen-test report.
 * 2. **Does every ⚑ ability take a password again?** `PermissionCatalogue::REAUTH` is the claim;
 *    {@see SensitiveActionMap} is the evidence. A new `Gate::allows('invoices.void')` in an unlisted
 *    file fails the audit, and a listed action with no {@see ConfirmsRecentPassword} call (or route
 *    `reauth`) fails too.
 * 3. **Are the global doors still on?** Company scope, the session register, the confirmation
 *    throttle and mandatory MFA are appended to the `web` group in `bootstrap/app.php`; a later edit
 *    that drops one would otherwise be invisible because every route still says `auth`.
 * 4. **Is the private disk private?** `documents_private` must not serve, the download route must be
 *    signed, and the controller must ask the policy — a signature proves provenance, not permission.
 * 5. **Does every encrypted column keep trio?** Schema introspection, not a model list: an
 *    `*_encrypted` column with no `key_version`, a version the key ring cannot decrypt, or a
 *    ciphertext/mask pair written apart are findings.
 *
 * `--static` skips the two database answers so CI can run the same command without an application
 * schema. In that mode it also skips alerting, because "database unreachable" is the one failure the
 * alert engine cannot record in a database.
 */
final class SecurityAudit extends Command
{
    protected $signature = 'security:audit
        {--static : Skip database-backed checks — the mode CI runs without an application schema}
        {--json : Print one machine-readable object instead of the report}
        {--no-alert : Report findings without touching the alert register}';

    protected $description = 'Audit route gates, ⚑ re-auth coverage, signed downloads and encrypted columns';

    /**
     * Routes that may be reached without a session, each with the reason it is safe.
     *
     * @var array<string, string>
     */
    private const PUBLIC_ROUTES = [
        'home' => 'redirects to login or dashboard; no data of its own',
        'login' => 'Fortify sign-in screen',
        'login.store' => 'Fortify sign-in endpoint (guest, throttled)',
        'password.request' => 'Fortify reset request (guest, throttled)',
        'password.email' => 'Fortify reset mail (guest, throttled)',
        'password.reset' => 'reset form carrying a signed token (guest)',
        'password.update' => 'reset submission (guest, signed token)',
        'two-factor.login' => 'second factor challenge while the session is half-authenticated',
        'two-factor.login.store' => 'second factor submission (guest, throttled)',
        'passkey.login-options' => 'passkey assertion options (guest, throttled)',
        'passkey.login' => 'passkey assertion (guest, throttled)',
        'security.invitations.accept' => 'the invitation token in the path is the proof',
        'sanctum.csrf-cookie' => 'cookie bootstrap, no data',
        'up' => 'health endpoint',
        'well-known.passkeys' => 'static discovery document',
    ];

    /**
     * Authenticated routes whose business is the caller's own account, so a `can:` gate would be
     * redundant: the row is the user. Fortify's endpoints enforce `auth` inside their own stack.
     *
     * @var array<string, string>
     */
    private const OWN_ACCOUNT_ROUTES = [
        'logout' => 'ends the caller\'s session',
        'password.confirm' => 're-auth prompt itself',
        'password.confirm.store' => 're-auth submission',
        'password.confirmation' => 're-auth status poll',
        'verification.notice' => 'asks the caller to verify their own address',
        'verification.verify' => 'verifies the caller\'s own address',
        'verification.send' => 'resends the caller\'s own verification mail',
        'two-factor.enable' => 'caller\'s own second factor',
        'two-factor.confirm' => 'caller\'s own second factor',
        'two-factor.disable' => 'caller\'s own second factor',
        'two-factor.qr-code' => 'caller\'s own second factor',
        'two-factor.secret-key' => 'caller\'s own second factor secret',
        'two-factor.recovery-codes' => 'caller\'s own recovery codes',
        'two-factor.regenerate-recovery-codes' => 'caller\'s own recovery codes',
        'passkey.confirm' => 'caller\'s own passkey',
        'passkey.confirm-options' => 'caller\'s own passkey',
        'passkey.destroy' => 'caller\'s own passkey',
        'passkey.registration-options' => 'caller\'s own passkey',
        'passkey.store' => 'caller\'s own passkey',
        'user-profile-information.update' => 'caller\'s own profile',
        'user-password.update' => 'caller\'s own password',
    ];

    /**
     * Application routes that deliberately ask no `can:` middleware, with the check that stands in
     * its place. Keyed by route name, or by URI when the route is an unnamed redirect.
     *
     * @var array<string, string>
     */
    private const AUTH_ONLY_ROUTES = [
        'dashboard' => 'every card asks its own permission and renders a locked placeholder otherwise',
        'profile.edit' => 'own account',
        'appearance.edit' => 'own account',
        'sessions.edit' => 'own sessions, own-scope by design',
        'security.edit' => 'own MFA and passkeys',
        'settings' => 'redirect to the caller\'s own profile',
        'documents.download' => 'signed URL plus the policy inside DownloadDocumentController',
        'reports.exports.download' => 'row policy, per-report export ability and the ⚑ prompt inside DownloadReportExport',
    ];

    /**
     * Encrypted columns that legitimately have no `_masked` sibling. An API credential has no human
     * display surface; the pairing CHECK in the migration is what keeps its key version honest.
     *
     * @var array<string, string>
     */
    private const NO_MASK_COLUMNS = [
        'gps_provider_accounts.api_key_encrypted' => 'write-only provider credential; there is nothing to display',
    ];

    /** @var list<array{check: string, subject: string, finding: string}> */
    private array $findings = [];

    /** @var list<array{check: string, subject: string, note: string}> */
    private array $notes = [];

    /** @var array<string, string|int> */
    private array $summary = [];

    public function handle(SelfCheckAlerter $alerter): int
    {
        $this->checkGlobalStack();
        $this->checkRoutes();
        $this->checkSensitiveActions();
        $this->checkDownloads();

        if ($this->option('static')) {
            $this->summary['encryption'] = 'skipped (static mode)';
        } else {
            $this->checkEncryptedColumns();
        }

        $this->report();

        if ($this->findings !== [] && ! $this->option('no-alert') && ! $this->option('static')) {
            $this->raise($alerter);
        } elseif ($this->findings === [] && ! $this->option('no-alert') && ! $this->option('static')) {
            $alerter->clear('security:audit', 'Route gates, re-auth coverage and encrypted columns are intact.');
        }

        return $this->findings === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkGlobalStack(): void
    {
        $web = array_map('strval', Route::getMiddlewareGroups()['web'] ?? []);

        $required = [
            ResolveCurrentCompany::class => 'company scope (fail-closed tenancy)',
            TrackUserSessions::class => 'the revocable session register',
            LimitConfirmationAttempts::class => 'the password-confirmation throttle',
            EnsureMultiFactorAuthentication::class => 'mandatory TOTP',
        ];

        $missing = 0;

        foreach ($required as $class => $label) {
            if (! in_array($class, $web, true)) {
                $missing++;
                $this->finding('stack', 'web middleware group', $label.' is no longer appended to every request');
            }
        }

        $this->summary['stack'] = $missing === 0
            ? count($required).' global doors present'
            : $missing.' global doors missing';
    }

    private function checkRoutes(): void
    {
        $guarded = 0;
        $public = 0;
        $kiosk = 0;
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($this->isFrameworkRoute($route)) {
                continue;
            }

            $checked++;

            /** @var list<string> $middleware */
            $middleware = array_values(array_map('strval', $route->gatherMiddleware()));
            $name = $route->getName();
            $key = $name ?? $route->uri();
            $label = ($name ?? $route->uri()).' ['.$route->uri().']';
            $abilities = $this->abilities($middleware);

            if (in_array('kiosk', $middleware, true)) {
                $kiosk++;

                if ($this->hasAuth($middleware)) {
                    $this->finding('routes', $label, 'is both auth and kiosk; a driver has no account, so this route can never be used');
                }

                if ($this->throttles($middleware) === false) {
                    $this->finding('routes', $label, 'kiosk route has no throttle — rates the attempt, not a failed comparison');
                }

                continue;
            }

            if (! $this->hasAuth($middleware)) {
                if (isset(self::PUBLIC_ROUTES[$key])) {
                    $public++;

                    continue;
                }

                $this->finding('routes', $label, 'is reachable without auth and is not on the public allowlist');

                continue;
            }

            if ($abilities === []) {
                if (isset(self::OWN_ACCOUNT_ROUTES[$key]) || isset(self::AUTH_ONLY_ROUTES[$key])) {
                    $guarded++;

                    continue;
                }

                $this->finding('routes', $label, 'is authenticated but asks no `can:` permission and has no documented exception');

                continue;
            }

            $guarded++;

            foreach ($abilities as $ability) {
                if (! PermissionCatalogue::knows($ability)) {
                    $this->finding('routes', $label, "asks unknown permission '{$ability}' — the design has no such row");

                    continue;
                }

                if (PermissionCatalogue::requiresReauth($ability) && ! $this->hasReauth($middleware)) {
                    $this->finding('routes', $label, "asks ⚑ '{$ability}' without `reauth` — the design says the password is asked again");
                }
            }

            if ($key === 'documents.download') {
                if (! in_array('signed', $middleware, true)) {
                    $this->finding('routes', $label, 'serves private document bytes without a signed URL');
                }
            }
        }

        $this->summary['routes'] = sprintf(
            '%d checked, %d guarded, %d public by design, %d kiosk',
            $checked,
            $guarded,
            $public,
            $kiosk,
        );
    }

    private function checkSensitiveActions(): void
    {
        $references = $this->scanSensitiveReferences();
        $map = SensitiveActionMap::entries();
        $checked = 0;
        $unbuilt = 0;

        foreach (PermissionCatalogue::reauthNames() as $permission) {
            $entry = $map[$permission] ?? null;

            if ($entry === null) {
                $this->finding('reauth', $permission, 'is ⚑ but has no entry in SensitiveActionMap');

                continue;
            }

            foreach (array_merge($entry['sites'], $entry['reauth']) as $path) {
                if (! File::exists(base_path($path))) {
                    $this->finding('reauth', $permission, 'names a site that does not exist: '.$path);
                }
            }

            foreach ($references[$permission] ?? [] as $path) {
                if (! in_array($path, $entry['sites'], true) && ! in_array($path, $entry['reauth'], true)) {
                    $this->finding('reauth', $permission, 'is authorised in an unregistered site: '.$path);
                }
            }

            if (($references[$permission] ?? []) !== [] && $entry['reauth'] === []) {
                $this->finding('reauth', $permission, 'has authorising call sites but no re-auth site');
            }

            foreach ($entry['reauth'] as $path) {
                if (! File::exists(base_path($path))) {
                    continue;
                }

                if (! $this->containsReauthMarker((string) File::get(base_path($path)))) {
                    $this->finding('reauth', $permission, 'names a re-auth site with no re-auth marker: '.$path);
                }
            }

            if (($references[$permission] ?? []) === [] && $entry['sites'] === []) {
                $unbuilt++;
                $this->note('reauth', $permission, 'is granted but has no authorising door in this build');
            }

            $checked++;
        }

        $this->summary['reauth'] = sprintf('%d ⚑ permissions accounted for, %d without a door yet', $checked, $unbuilt);
    }

    private function checkDownloads(): void
    {
        $serves = config('filesystems.disks.'.DocumentStore::Disk.'.serve');

        if ($serves !== false) {
            $this->finding('downloads', DocumentStore::Disk, 'may be served by the framework, offering a second door past the signed download route');
        }

        $controller = 'app/Http/Controllers/Documents/DownloadDocumentController.php';

        if (! File::exists(base_path($controller)) || ! str_contains((string) File::get(base_path($controller)), "Gate::denies('download'")) {
            $this->finding('downloads', $controller, 'no longer asks the document policy; a signature proves provenance, not permission');
        }

        $this->summary['downloads'] = 'private disk not served; download asks policy + signature';
    }

    private function checkEncryptedColumns(): void
    {
        try {
            $rows = DB::select(
                'select table_name as t, column_name as c from information_schema.columns where table_schema = database()'
            );
        } catch (\Throwable $e) {
            $this->finding('encryption', 'information_schema', 'could not be read: '.$e->getMessage());

            return;
        }

        /** @var array<string, list<string>> $tables */
        $tables = [];

        foreach ($rows as $row) {
            $tables[(string) $row->t][] = (string) $row->c;
        }

        $pairs = 0;
        $keyRing = array_map('strval', array_keys((array) config('fleet.crypto.keys', [])));

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                if (! str_ends_with($column, '_encrypted')) {
                    continue;
                }

                $pairs++;
                $base = substr($column, 0, -strlen('_encrypted'));

                if (! in_array($base.'_masked', $columns, true)) {
                    if (isset(self::NO_MASK_COLUMNS[$table.'.'.$column])) {
                        $this->note('encryption', $table.'.'.$column, self::NO_MASK_COLUMNS[$table.'.'.$column]);
                    } else {
                        $this->finding('encryption', $table.'.'.$column, 'has no '.$base.'_masked sibling — the trio is incomplete');
                    }
                }

                if (! in_array('key_version', $columns, true)) {
                    $this->finding('encryption', $table.'.'.$column, 'has no key_version column, so a rotation cannot tell which key made it');

                    continue;
                }

                $missingVersion = DB::table($table)->whereNotNull($column)->whereNull('key_version')->count();

                if ($missingVersion > 0) {
                    $this->finding('encryption', $table.'.'.$column, $missingVersion.' row(s) carry ciphertext and no key version');
                }

                $orphanVersion = DB::table($table)->whereNull($column)->whereNotNull('key_version')->count();

                if ($orphanVersion > 0) {
                    $this->finding('encryption', $table.'.'.$column, $orphanVersion.' row(s) name a key version but hold no ciphertext');
                }

                /** @var list<string> $versions */
                $versions = DB::table($table)->whereNotNull($column)->distinct()->pluck('key_version')->map(fn ($v) => (string) $v)->all();

                foreach ($versions as $version) {
                    if (! in_array($version, $keyRing, true)) {
                        $this->finding('encryption', $table.'.'.$column, "holds key version '{$version}', which fleet.crypto.keys cannot decrypt");
                    }
                }
            }
        }

        if (config('fleet.crypto.keys.v1') === null || config('fleet.crypto.keys.v1') === '') {
            $this->finding('encryption', 'fleet.crypto.keys.v1', 'is not set — nothing can be encrypted or decrypted');
        }

        foreach ((array) config('fleet.crypto.keys', []) as $version => $key) {
            if (is_string($key) && $key !== '' && $key === (string) config('app.key')) {
                $this->finding('encryption', 'fleet.crypto.keys.'.$version, 'reuses APP_KEY — requires a separate DB_CRYPT_KEY');
            }
        }

        $this->summary['encryption'] = $pairs.' encrypted column pairs, key ring '.implode(',', $keyRing);
    }

    /**
     * @return array<string, list<string>> permission => repository-relative files that address it
     */
    private function scanSensitiveReferences(): array
    {
        $references = array_fill_keys(PermissionCatalogue::reauthNames(), []);

        $excluded = [
            'app/Console/Commands/SecurityAudit.php',
            'app/Domain/Kernel/Identity/PermissionCatalogue.php',
            'app/Domain/Kernel/Identity/RoleKey.php',
            'app/Domain/Kernel/Identity/SensitiveActionMap.php',
        ];

        foreach ([app_path(), resource_path(), base_path('routes')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $path = $this->relative($file->getPathname());

                if (in_array($path, $excluded, true)) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                foreach (array_keys($references) as $permission) {
                    if ($this->authorises($contents, $permission)) {
                        $references[$permission][] = $path;
                    }
                }
            }
        }

        foreach ($references as $permission => $paths) {
            $references[$permission] = array_values(array_unique($paths));
        }

        return $references;
    }

    private function authorises(string $contents, string $permission): bool
    {
        $quoted = preg_quote($permission, '/');

        foreach ([
            '/(?:Gate::(?:allows|authorize|denies)|->can)\(\s*[\'"]'.$quoted.'[\'"]/',
            '/@can\(\s*[\'"]'.$quoted.'[\'"]/',
            '/\bcan:'.$quoted.'\b/',
        ] as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsReauthMarker(string $contents): bool
    {
        foreach (['password_confirmed_at', 'RequirePassword', 'reauth:', 'confirmRecentPassword('] as $marker) {
            if (str_contains($contents, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function abilities(array $middleware): array
    {
        $abilities = [];

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'can:')) {
                $ability = explode(',', substr($entry, 4))[0];

                if ($ability !== '') {
                    $abilities[] = $ability;
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    /** @param list<string> $middleware */
    private function hasAuth(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'auth' || str_starts_with($entry, 'auth:')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $middleware */
    private function hasReauth(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'reauth')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $middleware */
    private function throttles(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'throttle:')) {
                return true;
            }
        }

        return false;
    }

    private function isFrameworkRoute(RoutingRoute $route): bool
    {
        $name = $route->getName();
        $uri = $route->uri();

        if ($name !== null && (str_starts_with($name, 'livewire.') || $name === 'default-livewire.update')) {
            return true;
        }

        return str_starts_with($uri, 'livewire-') || str_starts_with($uri, 'storage/');
    }

    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function finding(string $check, string $subject, string $finding): void
    {
        $this->findings[] = ['check' => $check, 'subject' => $subject, 'finding' => $finding];
    }

    private function note(string $check, string $subject, string $note): void
    {
        $this->notes[] = ['check' => $check, 'subject' => $subject, 'note' => $note];
    }

    private function report(): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $this->findings === [],
                'summary' => $this->summary,
                'findings' => $this->findings,
                'notes' => $this->notes,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->newLine();
        $this->line('<options=bold>security:audit</> — gates and encrypted columns');

        foreach (['stack', 'routes', 'reauth', 'downloads', 'encryption'] as $section) {
            $this->line(sprintf('  %-12s %s', $section, $this->summary[$section] ?? 'not run'));
        }

        if ($this->notes !== []) {
            $this->newLine();
            foreach ($this->notes as $note) {
                $this->line(sprintf('  <comment>NOTE</comment> %s — %s: %s', $note['subject'], $note['check'], $note['note']));
            }
        }

        if ($this->findings === []) {
            $this->newLine();
            $this->info('  All checks passed.');

            return;
        }

        $this->newLine();
        foreach ($this->findings as $finding) {
            $this->line(sprintf('  <fg=red>FAIL</> %s — %s: %s', $finding['subject'], $finding['check'], $finding['finding']));
        }

        $this->newLine();
        $this->error(sprintf('  %d finding(s). A finding is a gate, not a suggestion.', count($this->findings)));
    }

    private function raise(SelfCheckAlerter $alerter): void
    {
        /** @var list<int> $companies */
        $companies = Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($companies === []) {
            return;
        }

        $details = array_map(
            static fn (array $finding): string => $finding['check'].' '.$finding['subject'].' — '.$finding['finding'],
            $this->findings,
        );

        foreach ($companies as $company) {
            $alerter->record('security:audit', 'Security posture needs attention', [$company => $details]);
        }
    }
}
