<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T2: the authenticated user is the source of the company context, so users carry it.
     * Deliberately NOT NULL — a user with no company would resolve to "no context", which
     * T3 requires to fail closed on every business query rather than silently widen it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->default(1)->after('id')->constrained('companies');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
