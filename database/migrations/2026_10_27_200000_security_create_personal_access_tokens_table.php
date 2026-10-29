<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanctum's own table, published and then renamed into this project's ordering. The column set is
     * left exactly as the package writes it: `tokenable` morphs to `users`, whose `company_id` (§8) is
     * what puts a token in a tenant, so a company column here would be a second answer to the same
     * question and the first thing to drift.
     *
     * The trip-scoped kiosk tokens of §9.6 do **not** live in this table. They belong to a trip, not to
     * a user, and a driver has no account and no factor to hold a personal access token.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
