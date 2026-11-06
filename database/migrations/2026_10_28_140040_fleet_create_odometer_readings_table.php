<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ODOMETER_READING — every measurement, kept as a measurement.
     *
     * A reading is evidence, so it is never edited to match the truck; the truck's
     * `odometer_km` follows the highest plausible reading. That is why `plausibility` and `source`
     * are columns on the reading rather than a single trusted number: a pump attendant's kiosk entry
     * that runs backwards is a `suspect` row to investigate, not a silent correction.
     *
     * `recorded_by` is nullable because a kiosk or an import can write a reading without a user
     * session (the non-user principals).
     */
    public function up(): void
    {
        Schema::create('odometer_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->foreignId('truck_id')->constrained('trucks');
            $table->char('trip_id', 26)->nullable();

            $table->unsignedBigInteger('odometer_km');
            $table->decimal('engine_hours_10_2', 10, 2)->nullable();
            $table->date('read_on');

            $table->string('source', 20)->default('manual');
            $table->string('plausibility', 12)->default('ok');

            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'truck_id', 'read_on'], 'odometer_readings_truck_read_index');
        });

        DB::statement('alter table `odometer_readings` add constraint `odometer_readings_source_allowed` check (source in (\'trip_departure\', \'trip_arrival\', \'fuel_issue\', \'manual\', \'kiosk\'))');
        DB::statement('alter table `odometer_readings` add constraint `odometer_readings_plausibility_allowed` check (plausibility in (\'ok\', \'suspect\', \'disputed\'))');
        DB::statement('alter table `odometer_readings` add constraint `odometer_readings_km_not_negative` check (odometer_km >= 0)');
        DB::statement('alter table `odometer_readings` add constraint `odometer_readings_hours_not_negative` check (engine_hours_10_2 is null or engine_hours_10_2 >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_readings');
    }
};
