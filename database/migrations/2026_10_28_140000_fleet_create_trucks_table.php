<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TRUCK — the asset the whole operational model is keyed on.
     *
     * Two deliberate departures from the sketch, both recorded in the phase log:
     *
     * - `capacity_tonnes_10_2` is a decimal, matching the trailer and every other physical quantity
     *   (`engine_hours_10_2`, fuel baselines). The sketch's `bigint carrying_capacity_tonnes` would
     *   have needed an unwritten "x100" convention for a value nobody thinks of as money, and D6's
     *   integer rule exists for money.
     * - There is no `loan_id` or `insurance_document_id` column. Loans arrive in Phase 5 and would
     *   be a dangling key today; insurance attaches as a document whose subject is this row,
     *   which also gives the register the expiry and the scan in one place.
     *
     * `status` is the state column with `previous_state`/`state_changed_at` beside it. The sale
     * is a state move (`→ sold`) that also clears `active`; the Action does both, so a sold truck
     * cannot keep appearing in the dispatch picker.
     */
    public function up(): void
    {
        Schema::create('trucks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('registration_no', 24);
            $table->string('fleet_no', 24)->nullable();
            $table->string('vin', 32)->nullable();

            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();

            $table->string('status', 20)->default('available');
            $table->string('previous_state', 20)->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->decimal('capacity_tonnes_10_2', 8, 2)->nullable();
            $table->unsignedBigInteger('odometer_km')->default(0);
            $table->decimal('engine_hours_10_2', 10, 2)->nullable();

            $table->unsignedBigInteger('purchase_amount_base')->default(0);
            $table->date('purchased_on')->nullable();

            $table->string('tyre_size', 40)->nullable();
            $table->unsignedSmallInteger('service_interval_km')->nullable();
            $table->unsignedSmallInteger('service_interval_hours')->nullable();
            $table->unsignedSmallInteger('service_interval_months')->nullable();

            $table->string('fuel_type', 16)->default('diesel');
            $table->unsignedSmallInteger('main_tank_litres')->nullable();
            $table->unsignedSmallInteger('aux_tank_litres')->nullable();
            $table->decimal('expected_consumption_per_100', 6, 2)->nullable();

            $table->json('compliance_flags')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'registration_no'], 'trucks_company_registration_unique');
            $table->unique(['company_id', 'fleet_no'], 'trucks_company_fleet_no_unique');
            $table->unique(['company_id', 'vin'], 'trucks_company_vin_unique');
            $table->index(['company_id', 'status'], 'trucks_company_status_index');
            $table->index(['company_id', 'active'], 'trucks_company_active_index');
        });

        DB::statement('alter table `trucks` add constraint `trucks_status_allowed` check (status in (\'available\', \'on_trip\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `trucks` add constraint `trucks_previous_state_allowed` check (previous_state is null or previous_state in (\'available\', \'on_trip\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `trucks` add constraint `trucks_odometer_not_negative` check (odometer_km >= 0)');
        DB::statement('alter table `trucks` add constraint `trucks_engine_hours_not_negative` check (engine_hours_10_2 is null or engine_hours_10_2 >= 0)');
        DB::statement('alter table `trucks` add constraint `trucks_capacity_not_negative` check (capacity_tonnes_10_2 is null or capacity_tonnes_10_2 >= 0)');
        DB::statement('alter table `trucks` add constraint `trucks_purchase_not_negative` check (purchase_amount_base >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trucks');
    }
};
