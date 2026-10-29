<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.1 — staff accounts are invited, never self-registered. Registration is off in
     * config/fortify.php, so this table plus the accept Action is the only door into a user row.
     *
     * The row stores a **hash** of the token, never the token: an `invitations` dump must not be a
     * list of live enrolment links. Single-use is enforced twice — the `accepted_at` guard below,
     * and the accept Action re-reading the row with a row lock inside its transaction, because a
     * double-clicked link and a retry after a dropped connection arrive as two requests.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            // Matched case-insensitively against the address the link was accepted from (§9.1:
            // "accepted against a verified email address").
            $table->string('email', 191);
            $table->string('role_key', 40);

            $table->string('token_hash', 64);
            $table->foreignId('invited_by')->constrained('users');

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('token_hash', 'invitations_token_hash_unique');
            $table->index(['company_id', 'email'], 'invitations_company_email_index');
            $table->index(['company_id', 'accepted_at'], 'invitations_company_accepted_index');
        });

        // Laravel's Blueprint has no CHECK support (§20.4), so the two invariants that must survive
        // a bug in the Action are enforced by the engine.
        DB::statement('alter table `invitations` add constraint `invitations_accepted_requires_user` check (accepted_at is null or accepted_user_id is not null)');
        DB::statement('alter table `invitations` add constraint `invitations_user_requires_accepted` check (accepted_at is not null or accepted_user_id is null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
