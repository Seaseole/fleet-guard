<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The device/session list, and the row that makes the absolute session bound exist at all.
     *
     * A registry rather than a read of the framework's `sessions` table, because `SESSION_DRIVER` is
     * Valkey in production and a screen that greps the session store would work on this laptop and
     * quietly show nothing there. The row is what the request middleware compares itself against, so
     * revocation takes effect on the device's next request on either backend.
     *
     * `id` is the **hash** of the session id, not the id. A session id is a bearer credential: storing
     * the live one in a table that an Owner can read on a screen would turn a read access into a
     * sign-as-them access, and the screen only ever needs to distinguish rows.
     */
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // `created_at` is the sign-in moment and never moves, which is what makes the absolute cap
            // computable; `expires_at` is the idle deadline and is pushed forward by activity — but never
            // past created_at + the absolute limit. One comparison in the middleware decides both.
            $table->timestamp('created_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');

            // A tombstone, not a delete: if revocation removed the row, "revoked" and "signed in before
            // this table existed" would be the same query result, and the second one has to be adopted.
            $table->timestamp('revoked_at')->nullable();

            // The promise is that a second factor was actually satisfied for this device. Recorded at
            // sign-in (the full predicate) and then latched one-way by activity, because the invited user
            // who signs in before enrolling a factor should not be left claiming their own laptop has no
            // factor after they have enrolled one.
            $table->boolean('two_factor_ok')->default(false);

            $table->index(['company_id', 'user_id', 'last_seen_at'], 'user_sessions_user_index');
            $table->index(['company_id', 'expires_at'], 'user_sessions_expiry_index');
        });

        DB::statement('alter table `user_sessions` add constraint `user_sessions_seen_after_sign_in` check (`last_seen_at` >= `created_at`)');
        DB::statement('alter table `user_sessions` add constraint `user_sessions_expiry_after_sign_in` check (`expires_at` >= `created_at`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
