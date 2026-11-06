<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUEL_VARIANCE — the consumption snapshot taken when a trip completes.
     *
     * The figures the arithmetic used are stored with the result, for the reason: consumption
     * 100 km is computed, never stored as truth, and this row is the *analysis snapshot* the design
     * allows — litres, the distance the trip actually covered and the tonnes it carried, so a figure
     * challenged six months later can be recomputed rather than trusted.
     *
     * One row per trip: a re-completed trip re-measures the same span. `state` is the small machine of
     * A variance is acknowledged or disputed by the person who looked at it, and closed by a
     * seat holding `fuel.waive_variance`; every state past `open` must carry the note that says why.
     */
    public function up(): void
    {
        Schema::create('fuel_variances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26);

            $table->unsignedInteger('distance_km');
            $table->unsignedBigInteger('litres_x100');
            $table->decimal('litres_per_100_10_2', 6, 2);
            $table->decimal('expected_per_100_10_2', 6, 2);
            $table->decimal('variance_pct_10_2', 7, 2);
            $table->decimal('tonnes_carried_10_2', 8, 2);

            $table->string('state', 12)->default('open');
            $table->string('acknowledgement_note', 160)->nullable();
            $table->dateTime('detected_at');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->dateTime('decided_at')->nullable();
            $table->string('previous_state', 12)->nullable();
            $table->dateTime('state_changed_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'trip_id'], 'fuel_variances_company_trip_unique');
            $table->index(['company_id', 'state', 'detected_at'], 'fuel_variances_company_state_index');
        });

        DB::statement('alter table `fuel_variances` add constraint `fuel_variances_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');
        DB::statement('alter table `fuel_variances` add constraint `fuel_variances_state_allowed` check (state in (\'open\', \'acknowledged\', \'disputed\', \'resolved\'))');
        DB::statement('alter table `fuel_variances` add constraint `fuel_variances_previous_state_allowed` check (previous_state is null or previous_state in (\'open\', \'acknowledged\', \'disputed\', \'resolved\'))');
        DB::statement('alter table `fuel_variances` add constraint `fuel_variances_span_positive` check (distance_km > 0 and litres_x100 > 0 and expected_per_100_10_2 > 0)');
        DB::statement('alter table `fuel_variances` add constraint `fuel_variances_decided_is_explained` check (state = \'open\' or acknowledgement_note is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_variances');
    }
};
