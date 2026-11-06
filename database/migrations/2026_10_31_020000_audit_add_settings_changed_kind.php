<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The closed list gains `settings_changed`: Marks `settings.manage` ⚑, and a changed
 * approval threshold or kiosk horizon is policy — the question "who lowered the approval limit
 * last Tuesday" has to be answerable from the security trail.
 *
 * The enum in `SecurityEventKind` is the PHP half of the same list; adding a kind is the
 * deliberate two-file edit the enum's docblock describes.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KINDS = [
        'mfa_enabled', 'mfa_disabled', 'password_changed', 'reauth_ok', 'reauth_fail',
        'session_revoked', 'invite_accepted', 'kiosk_token_issued', 'kiosk_token_revoked',
        'document_uploaded', 'document_downloaded', 'document_version_deleted', 'document_voided',
        'settings_changed',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::KINDS);
    }

    public function down(): void
    {
        $this->replaceCheck(array_values(array_diff(self::KINDS, ['settings_changed'])));
    }

    /**
     * SQLite (the test connection) cannot alter constraints at all, and the original migration
     * does not add one there either — so the guard keeps both sides consistent rather than
     * failing a migration the suite could not have run anyway.
     *
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
