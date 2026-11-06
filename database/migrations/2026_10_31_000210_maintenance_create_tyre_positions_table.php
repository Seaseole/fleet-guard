<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TYRE_POSITION — one fitment: this tyre on this wheel of this truck, from one odometer
     * reading to another.
     *
     * The row is a period, not a pointer. When a tyre comes off, `removed_at` and
     * `removed_odometer_km` are written and the row stays: how many kilometres a casing ran on the
     * steer axle is the number a tyre policy is judged by, and a row that moved around would forget
     * it. `cost_per_km_x10000` is filled at removal from the purchase cost and the distance run.
     *
     * Two things must be true at once while a fitment is open, and MySQL has no partial indexes, so
     * each is a **generated column that is null once the tyre is removed** plus a unique index over
     * it: a wheel has one tyre, and a tyre is on one wheel. NULLs are distinct under a unique index,
     * which is exactly the "only while open" semantics — the same trick the one-current-document
     * index uses.
     *
     * `retread_count` is copied onto the fitment rather than read from the tyre, because the number
     * that matters is how many retreads the casing had when it was fitted to this wheel.
     */
    public function up(): void
    {
        Schema::create('tyre_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('tyre_id')->constrained('tyres');
            $table->foreignId('truck_id')->constrained('trucks');

            $table->string('position', 4);
            $table->dateTime('fitted_at');
            $table->unsignedBigInteger('fitted_odometer_km');

            $table->dateTime('removed_at')->nullable();
            $table->unsignedBigInteger('removed_odometer_km')->nullable();
            $table->unsignedTinyInteger('retread_count')->default(0);
            // Rand ×10,000 so a cost per kilometre of 0.0125 is 125 (the scale).
            $table->unsignedBigInteger('cost_per_km_x10000')->nullable();

            $table->timestamps();

            $table->string('open_position', 4)->nullable()
                ->storedAs('case when removed_at is null then position else null end');
            $table->unsignedBigInteger('open_tyre_id')->nullable()
                ->storedAs('case when removed_at is null then tyre_id else null end');

            $table->unique(['company_id', 'truck_id', 'open_position'], 'tyre_positions_open_slot_unique');
            $table->unique(['company_id', 'open_tyre_id'], 'tyre_positions_open_tyre_unique');
            $table->index(['company_id', 'tyre_id'], 'tyre_positions_company_tyre_index');
            $table->index(['company_id', 'truck_id', 'removed_at'], 'tyre_positions_company_truck_history_index');
        });

        DB::statement("alter table `tyre_positions` add constraint `tyre_positions_slot_allowed` check (position in ('FL', 'FR', 'RL1', 'RL2', 'RR1', 'RR2'))");

        // A fitment ends or it does not; half a removal is not a state the tyre book can answer for.
        DB::statement('alter table `tyre_positions` add constraint `tyre_positions_removal_is_complete` check (
            (removed_at is null and removed_odometer_km is null and cost_per_km_x10000 is null)
            or (removed_at is not null and removed_odometer_km is not null)
        )');

        DB::statement('alter table `tyre_positions` add constraint `tyre_positions_odo_never_goes_back` check (
            removed_odometer_km is null or removed_odometer_km >= fitted_odometer_km
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('tyre_positions');
    }
};
