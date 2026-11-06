<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TRIP — the hub of the whole system.
     *
     * The truck, trailer, driver and route are all named here rather than inferred from the job,
     * because one job runs as several legs with different equipment. The route is copied from
     * the job on purpose: corridor reporting must survive a later edit of the master.
     *
     * `status` carries the sixteen states with `previous_state` and `state_changed_at` beside them,
     * and every move also writes an `operational_events` row — which is why there are no
     * `delivered_at` / `closed_at` columns. A timestamp per state would be a second, drift-prone copy
     * of a trail the machine already owns; the four columns kept here are the four a *report* reads
     * without walking events (planned, departed, arrived, completed).
     *
     * The rollups (`revenue_base`, `fuel_cost_base`, …) arrive with the slice that writes them, so the
     * table does not carry nine zeros that `ledger:verify` would have to distrust.
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('trip_no', 32);
            $table->char('job_id', 26);
            $table->foreignId('route_id')->constrained('routes');

            $table->foreignId('truck_id')->nullable()->constrained('trucks');
            $table->foreignId('trailer_id')->nullable()->constrained('trailers');
            $table->foreignId('driver_id')->nullable()->constrained('drivers');
            $table->foreignId('supercargo_employee_id')->nullable()->constrained('employees');

            $table->string('status', 24)->default('draft');
            $table->string('previous_state', 24)->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->timestamp('planned_departure_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedBigInteger('odometer_start_km')->nullable();
            $table->unsignedBigInteger('odometer_end_km')->nullable();
            $table->decimal('engine_hours_start_10_2', 10, 2)->nullable();
            $table->decimal('distance_km_10_2', 10, 2)->nullable();

            $table->json('exception_flags')->nullable();
            $table->boolean('costs_frozen')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'trip_no'], 'trips_company_trip_no_unique');
            $table->index(['company_id', 'status'], 'trips_company_status_index');
            $table->index(['company_id', 'job_id'], 'trips_company_job_index');
            $table->index(['company_id', 'truck_id', 'planned_departure_at'], 'trips_company_truck_departure_index');
            $table->index(['company_id', 'driver_id', 'status'], 'trips_company_driver_status_index');
        });

        // The ULID key is typed to match the rule that FK columns are type-matched. It is a
        // **composite** key on purpose: the pair (job_id, company_id) must exist in
        // freight_jobs, so a trip can never name another company's job even if an id leaks across.
        DB::statement('alter table `trips` add constraint `trips_job_company_fk` foreign key (`job_id`, `company_id`) references `freight_jobs` (`id`, `company_id`)');

        // The `draft → planned` is where a truck and a driver get named. Allowing nulls only in
        // the draft state — and refusing them everywhere else, at the engine — is what lets a
        // dispatcher open a trip for Thursday before Thursday's crew exists, without ever letting a
        // *dispatchable* row have no one on it.
        DB::statement('alter table `trips` add constraint `trips_plan_is_named` check (status = \'draft\' or (truck_id is not null and driver_id is not null))');

        DB::statement('alter table `trips` add constraint `trips_status_allowed` check (status in (\'draft\', \'planned\', \'assigned\', \'loaded\', \'departed\', \'in_transit\', \'at_border\', \'arrived\', \'pod_pending\', \'delivered\', \'completed\', \'closed\', \'reopened\', \'on_hold\', \'cancelled\', \'aborted\'))');
        DB::statement('alter table `trips` add constraint `trips_previous_state_allowed` check (previous_state is null or previous_state in (\'draft\', \'planned\', \'assigned\', \'loaded\', \'departed\', \'in_transit\', \'at_border\', \'arrived\', \'pod_pending\', \'delivered\', \'completed\', \'closed\', \'reopened\', \'on_hold\', \'cancelled\', \'aborted\'))');

        // The departure facts: a trip cannot claim to have left without an odometer, and the
        // closing reading cannot be below the opening one (the monotonic rule, stated again here
        // because a trip odometer pair is the arithmetic every fuel variance is measured against).
        DB::statement('alter table `trips` add constraint `trips_departed_has_odometer` check (departed_at is null or odometer_start_km is not null)');
        DB::statement('alter table `trips` add constraint `trips_odometer_ordered` check (odometer_end_km is null or odometer_start_km is null or odometer_end_km >= odometer_start_km)');
        DB::statement('alter table `trips` add constraint `trips_engine_hours_not_negative` check (engine_hours_start_10_2 is null or engine_hours_start_10_2 >= 0)');
        DB::statement('alter table `trips` add constraint `trips_distance_not_negative` check (distance_km_10_2 is null or distance_km_10_2 >= 0)');

        // The four timestamps are facts that already happened, so they may not disagree with each
        // other. `planned_departure_at` is deliberately **not** in the comparison: a truck that leaves
        // two days early, or a day late, is the normal condition of a corridor, and a CHECK that
        // refused it would be the schema lying about what dispatch does. The plan is a promise;
        // measures the crew against the *start date*, which is a gate in `DispatchTrip`, not a row
        // constraint.
        DB::statement('alter table `trips` add constraint `trips_clock_order` check ((arrived_at is null or departed_at is null or arrived_at >= departed_at) and (completed_at is null or arrived_at is null or completed_at >= arrived_at))');

        // Closing is what freezes cost editing, so a frozen trip that is not closed is a lie
        // about which month the numbers belong to.
        DB::statement('alter table `trips` add constraint `trips_frozen_is_closed` check (costs_frozen = 0 or status = \'closed\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
