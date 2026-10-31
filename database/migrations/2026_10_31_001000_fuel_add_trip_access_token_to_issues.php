<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.6 — the driver's phone names itself on the litre it issued.
     *
     * A kiosk issue has no `recorded_by` (a driver has no account, ADR-0006), so without this column
     * the only answer to "which phone wrote this?" would be an operational event's JSON. The POD
     * carries `captured_by_token_id` for the same reason; this is the fuel side of that sentence.
     *
     * The CHECK makes the pairing a fact rather than a convention: `source = 'kiosk'` if and only if
     * a token is named, so a hand-written row cannot attribute a driver's litre to nobody, and an
     * office issue cannot borrow a token's authority after the fact.
     */
    public function up(): void
    {
        Schema::table('fuel_issues', function (Blueprint $table): void {
            $table->char('trip_access_token_id', 26)->nullable()->after('reversal_of_id');
        });

        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_trip_access_token_fk` foreign key (`trip_access_token_id`) references `trip_access_tokens` (`id`)');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_kiosk_names_its_token` check ((source = \'kiosk\') = (trip_access_token_id is not null))');
    }

    public function down(): void
    {
        DB::statement('alter table `fuel_issues` drop constraint `fuel_issues_kiosk_names_its_token`');
        DB::statement('alter table `fuel_issues` drop constraint `fuel_issues_trip_access_token_fk`');

        Schema::table('fuel_issues', function (Blueprint $table): void {
            $table->dropColumn('trip_access_token_id');
        });
    }
};
