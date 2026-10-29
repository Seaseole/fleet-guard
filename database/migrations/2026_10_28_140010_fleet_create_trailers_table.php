<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 TRAILER — an asset in its own right, assigned beside a truck rather than being a
     * property of one. A trailer outlives the tractor it was bought with, and §10.2's dispatch gate
     * measures load against both capacities, so the pair is stored as two assignments.
     */
    public function up(): void
    {
        Schema::create('trailers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('registration_no', 24);
            $table->string('fleet_no', 24)->nullable();
            $table->string('vin', 32)->nullable();

            $table->string('type', 20)->default('flatbed');
            $table->decimal('capacity_tonnes_10_2', 8, 2)->nullable();
            $table->string('axle_config', 40)->nullable();

            $table->string('status', 20)->default('available');
            $table->string('previous_state', 20)->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->unsignedBigInteger('odometer_km')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'registration_no'], 'trailers_company_registration_unique');
            $table->unique(['company_id', 'fleet_no'], 'trailers_company_fleet_no_unique');
            $table->unique(['company_id', 'vin'], 'trailers_company_vin_unique');
            $table->index(['company_id', 'status'], 'trailers_company_status_index');
        });

        DB::statement('alter table `trailers` add constraint `trailers_type_allowed` check (type in (\'flatbed\', \'tipper\', \'curtainsider\', \'tanker\', \'lowboy\'))');
        DB::statement('alter table `trailers` add constraint `trailers_status_allowed` check (status in (\'available\', \'on_trip\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `trailers` add constraint `trailers_previous_state_allowed` check (previous_state is null or previous_state in (\'available\', \'on_trip\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `trailers` add constraint `trailers_capacity_not_negative` check (capacity_tonnes_10_2 is null or capacity_tonnes_10_2 >= 0)');
        DB::statement('alter table `trailers` add constraint `trailers_odometer_not_negative` check (odometer_km >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trailers');
    }
};
