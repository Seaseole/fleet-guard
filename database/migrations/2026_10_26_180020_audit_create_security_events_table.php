<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.4 SECURITY_EVENT — the access and identity trail, separate from `audits` on purpose.
     *
     * `audits` (§9.5, owen-it/laravel-auditing) records **what changed on a row** with before and
     * after values. This table records **who touched something without changing it**: a signed
     * download, a re-auth attempt, a kiosk token issued. §14.4's "every download and export is
     * audited with actor, IP and document id" is a fact of that kind, and putting it in `audits`
     * would either invent a fake row change or make the ledger-critical trail unreadable.
     *
     * Append-only like the ledger. Nothing here is edited or deleted by an application path —
     * retention is an operational decision (§14.5), not a DELETE statement.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            // Nullable: an invite accepted by a user who is being created in the same action, or a
            // kiosk redemption whose principal is a token rather than a user, has no user id to give.
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('kind', 48);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('meta')->nullable();
            $table->datetime('happened_at');

            $table->index(['company_id', 'kind', 'happened_at'], 'security_events_company_kind_time_index');
            $table->index(['company_id', 'user_id', 'happened_at'], 'security_events_company_user_index');
        });

        // The list is closed by migration rather than by config, and extends §4.4's illustrative
        // set with the §14.4 document kinds: an event kind nobody enumerated is an event nobody
        // built an alert for, and silently accepted kinds are how a trail becomes noise.
        DB::statement('alter table `security_events` add constraint `security_events_kind_known` check (kind in (\'mfa_enabled\', \'mfa_disabled\', \'password_changed\', \'reauth_ok\', \'reauth_fail\', \'session_revoked\', \'invite_accepted\', \'kiosk_token_issued\', \'kiosk_token_revoked\', \'document_uploaded\', \'document_downloaded\', \'document_version_deleted\', \'document_voided\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
