<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promised the security trail would carry its own rows for exports: "Every audit row
 * records who, when, IP, user agent" and the event list names `export`. Phase 8 is the phase
 * that finally has an export to audit — one kind for the request that queued a file, one for
 * the read that streamed it out.
 *
 * The enum in `SecurityEventKind` is the PHP half of the same list; this is the deliberate
 * two-file edit its docblock describes.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KINDS = [
        'mfa_enabled', 'mfa_disabled', 'password_changed', 'reauth_ok', 'reauth_fail',
        'session_revoked', 'invite_accepted', 'kiosk_token_issued', 'kiosk_token_revoked',
        'document_uploaded', 'document_downloaded', 'document_version_deleted', 'document_voided',
        'settings_changed', 'export_queued', 'export_downloaded',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::KINDS);
    }

    public function down(): void
    {
        $this->replaceCheck(array_values(array_diff(self::KINDS, ['export_queued', 'export_downloaded'])));
    }

    /**
     * @param  list<string>  $kinds
     */
    private function replaceCheck(array $kinds): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('alter table `security_events` drop check `security_events_kind_known`');

        $quoted = implode(', ', array_map(static fn (string $kind): string => "'{$kind}'", $kinds));

        DB::statement("alter table `security_events` add constraint `security_events_kind_known` check (kind in ({$quoted}))");
    }
};
