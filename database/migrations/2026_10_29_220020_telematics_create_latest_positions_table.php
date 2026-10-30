<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §14.3's `latest_positions` projection — where the fleet is, without touching the history.
     *
     * "Where are my trucks" is the question the live map asks on every poll, and answering it from
     * `gps_positions` would scan 60 k rows a day and grow worse every day. One row per truck, moved
     * by the ingest transaction, keeps the read O(fleet).
     *
     * The projection is **not** the source of truth and is safe to lose: it can be rebuilt from the
     * newest fix per truck. `position_id` is nullable and `set null` on the raw row's deletion so
     * Phase 7's retention prune cannot leave a projection pointing at a fix that is gone — the
     * projection stays readable, it just stops claiming to cite a row.
     */
    public function up(): void
    {
        Schema::create('latest_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26)->nullable();
            $table->unsignedBigInteger('position_id')->nullable();

            $table->string('provider', 32);
            $table->decimal('lat_10_7', 10, 7);
            $table->decimal('lng_10_7', 10, 7);
            $table->unsignedSmallInteger('speed_kph')->nullable();
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();

            $table->datetime('fixed_at');
            $table->datetime('updated_at');

            $table->unique(['company_id', 'truck_id'], 'latest_positions_truck_unique');
            $table->index(['company_id', 'trip_id'], 'latest_positions_trip_index');
        });

        DB::statement('alter table `latest_positions` add constraint `latest_positions_position_fk` foreign key (`position_id`) references `gps_positions` (`id`) on delete set null');
        DB::statement('alter table `latest_positions` add constraint `latest_positions_trip_fk` foreign key (`trip_id`) references `trips` (`id`) on delete set null');

        DB::statement('alter table `latest_positions` add constraint `latest_positions_lat_range` check (`lat_10_7` between -90 and 90)');
        DB::statement('alter table `latest_positions` add constraint `latest_positions_lng_range` check (`lng_10_7` between -180 and 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('latest_positions');
    }
};
