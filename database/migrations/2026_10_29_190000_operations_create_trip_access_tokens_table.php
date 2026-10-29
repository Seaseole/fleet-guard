<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 TRIP_ACCESS_TOKEN and §9.6 — the driver's field-capture door.
     *
     * A driver has no user account (§9.2's "two non-user principals", ADR-0006). What they hold is a
     * string that names one trip, and the string is a secret: the table stores **only its hash**, so a
     * read of this table — through a leak, a backup, or a support query — hands over nothing that opens
     * the kiosk. The 6-digit PIN is hashed for the same reason.
     *
     * The binding is to the trip *and* the driver: §9.6's sentence "a token cannot read another trip"
     * is a row relationship, not a filter in the screen, and the composite FK is what makes a token
     * pointing at a trip in a second company unrepresentable rather than merely discouraged.
     *
     * `expires_at` is trip planned end + 24 h by default. A trip that runs long is a fact of this
     * industry, and a token that dies at midnight would send the driver to the office phone — so the
     * horizon is the trip's, not the day's, and it is a column rather than a computation so a revocation
     * and an expiry stay two separate answers to "why is this one closed?".
     *
     * `last_used_at` / `last_ip` exist for the question §9.6 asks about a shared phone: not "what did
     * the driver do" but "was this the driver". They are written on redemption, never on issue.
     */
    public function up(): void
    {
        Schema::create('trip_access_tokens', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('trip_id', 26);
            $table->foreignId('driver_id')->constrained('drivers');

            $table->string('token_hash', 64);
            $table->string('pin_hash', 64);
            $table->string('scopes', 64);

            $table->boolean('revoked')->default(false);
            $table->datetime('expires_at');
            $table->datetime('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('token_hash', 'trip_access_tokens_token_hash_unique');
            $table->unique(['id', 'company_id'], 'trip_access_tokens_id_company_unique');
            $table->index(['company_id', 'trip_id'], 'trip_access_tokens_company_trip_index');
            $table->index(['driver_id', 'expires_at'], 'trip_access_tokens_driver_expiry_index');
        });

        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`) on delete cascade');

        // A row with an empty secret or an empty scope list is a door with nothing behind it: it can never
        // be redeemed, and it reads as a live token to every screen that counts them.
        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_secrets_stored` check (char_length(token_hash) = 64 and char_length(pin_hash) = 64)');
        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_scopes_not_blank` check (char_length(trim(scopes)) between 4 and 64)');

        // The expiry is what makes a lost phone a temporary problem. An issue that writes a date already
        // in the past is that protection failing silently.
        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_expires_after_issue` check (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_access_tokens');
    }
};
