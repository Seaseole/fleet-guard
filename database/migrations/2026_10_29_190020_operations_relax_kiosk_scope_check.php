<?php

declare(strict_types=1);

use App\Domain\Operations\TripAccessScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * §9.6 — `pod` is a complete scope list, and the first constraint said otherwise.
     *
     * `trip_access_tokens_scopes_not_blank` asked for `char_length(trim(scopes)) between 4 and 64`,
     * written on the assumption that a token always carries at least two scopes. The first verification
     * run issued the smallest honest token — the POD screen alone — and the check refused the insert:
     * a constraint that forbids the most common case is a rule about the author's imagination, not about
     * the data.
     *
     * The bound is corrected to "not blank" (1) up to the column's 64, which is what the name always
     * said. The scope vocabulary itself is validated in {@see TripAccessScope},
     * where an unknown value is a domain refusal rather than a database error.
     */
    public function up(): void
    {
        DB::statement('alter table `trip_access_tokens` drop check `trip_access_tokens_scopes_not_blank`');
        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_scopes_not_blank` check (char_length(trim(scopes)) between 1 and 64)');
    }

    public function down(): void
    {
        DB::statement('alter table `trip_access_tokens` drop check `trip_access_tokens_scopes_not_blank`');
        DB::statement('alter table `trip_access_tokens` add constraint `trip_access_tokens_scopes_not_blank` check (char_length(trim(scopes)) between 4 and 64)');
    }
};
