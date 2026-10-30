<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 FUEL_ISSUE — fuel leaving a purchase (or, later, a bulk tank) into a truck, on a trip.
     *
     * Two figures are stored rather than derived, for two different reasons. `litres_x100` is the
     * spec's own scale (two decimal places, integer) so partial litres at a pump are exact.
     * `cost_base` is the derived cost **frozen at the issue**: the purchase's price per litre
     * converted at the purchase's own rate, or (Phase 3b) the bulk tank's moving average. Deriving it
     * at read time would answer with today's rate for a cost the ledger posted at the old one.
     *
     * `purchase_id` is nullable on purpose — null means "from a bulk tank" — and the CHECK decides
     * which sources require it. `trip_cost_id` names the ledger-backed cost row this issue raised, so
     * the trip's fuel number and the issue's litres are one transaction seen from two sides.
     *
     * §10.9's machine: `recorded → posted → reversed`. A correction is a reversal plus a new issue,
     * never an edit — the state columns are the trait's, and `reversed` needs a reason at the
     * `operational_events` CHECK. `reversal_of_id` links the pair so the register shows both.
     */
    public function up(): void
    {
        Schema::create('fuel_issues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('truck_id')->constrained('trucks');
            $table->foreignId('fuel_tank_id')->constrained('fuel_tanks');
            $table->char('trip_id', 26)->nullable();

            $table->unsignedInteger('litres_x100');
            $table->unsignedBigInteger('odometer_km');
            $table->char('purchase_id', 26)->nullable();

            $table->unsignedBigInteger('cost_base');
            $table->char('base_currency_code', 3);

            $table->string('source', 16)->default('pump_card');
            $table->string('status', 12)->default('recorded');
            $table->string('previous_state', 32)->nullable();
            $table->datetime('state_changed_at')->nullable();
            $table->string('exception_reason', 160)->nullable();

            // §6.9: the trip cost row this issue posted, and for an issue that corrects another, the
            // row corrected. Nullable in both directions because a bulk-tank issue has no purchase
            // and a first issue has no predecessor.
            $table->char('trip_cost_id', 26)->nullable();
            $table->char('reversal_of_id', 26)->nullable();

            $table->string('source_uuid', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'truck_id', 'created_at'], 'fuel_issues_company_truck_index');
            $table->index(['company_id', 'trip_id'], 'fuel_issues_company_trip_index');
            $table->unique(['company_id', 'source_uuid'], 'fuel_issues_company_source_unique');
        });

        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');

        // One-column FKs for the ULID parents, the same shape `trip_costs` and `border_costs` use:
        // a ULID already identifies its company, and none of these tables carries a composite key to
        // point at. Company agreement is the posting Action's job, which reads the trip first.
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_purchase_fk` foreign key (`purchase_id`) references `fuel_purchases` (`id`)');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_trip_cost_fk` foreign key (`trip_cost_id`) references `trip_costs` (`id`)');

        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_litres_positive` check (litres_x100 > 0)');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_cost_not_negative` check (cost_base >= 0)');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_source_allowed` check (source in (\'pump_card\', \'bulk_tank\', \'advance\', \'kiosk\'))');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_status_allowed` check (status in (\'recorded\', \'posted\', \'reversed\'))');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_previous_state_allowed` check (previous_state is null or previous_state in (\'recorded\', \'posted\', \'reversed\'))');

        // A pump-card or advance issue must name the purchase it draws from; a bulk-tank issue must
        // not (the stock is the bulk tank, and naming a purchase would double-count it).
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_purchase_matches_source` check ((source = \'bulk_tank\' and purchase_id is null) or (source <> \'bulk_tank\' and purchase_id is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_issues');
    }
};
