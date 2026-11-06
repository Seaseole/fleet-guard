<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ROUTE — the corridor a job prices against, e.g. GBE-WDH.
     *
     * `code` is required and unique per company because the design makes it the reference ("GBE-WDH-JNB"):
     * it is what dispatch says out loud and what a rate card is filed under. The endpoints are
     * stored as names *and* ISO country codes; the codes are what a cross-border summary groups by
     * in Phase 6, and deriving them from free-text place names later is not a reliable operation.
     *
     * `distance_km` is a decimal, not money and not an integer: a corridor distance is a measured
     * number (1 074.5 km) that toll, fuel-per-km and rate-per-km calculations all consume, and
     * D6's integer rule exists for currency, not for distances.
     *
     * Country codes are checked against the corridor set in `config/fleet.php` by the DTO, not by
     * a CHECK here: the set is a business fact that grows when the company opens a new lane, and
     * a CHECK list would make that a migration.
     */
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 24);
            $table->string('origin', 80);
            $table->string('destination', 80);
            $table->char('origin_country_code', 2);
            $table->char('destination_country_code', 2);
            $table->decimal('distance_km', 8, 2);

            $table->string('notes', 255)->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'code'], 'routes_company_code_unique');
            $table->index(['company_id', 'active'], 'routes_company_active_index');
            $table->index(['company_id', 'origin_country_code', 'destination_country_code'], 'routes_company_corridor_index');
        });

        // A zero-kilometre corridor is a data-entry mistake, not a local delivery: local work is a
        // job without a route, and a rate per km against 0 km would silently price at zero.
        DB::statement('alter table `routes` add constraint `routes_distance_positive` check (distance_km > 0)');
        DB::statement('alter table `routes` add constraint `routes_origin_country_iso` check (char_length(origin_country_code) = 2)');
        DB::statement('alter table `routes` add constraint `routes_destination_country_iso` check (char_length(destination_country_code) = 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
