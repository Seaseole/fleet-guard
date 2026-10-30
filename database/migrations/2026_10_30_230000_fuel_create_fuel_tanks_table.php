<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 FUEL_TANK — the physical tank a truck carries fuel in.
     *
     * The truck rows already carry `main_tank_litres`/`aux_tank_litres` from Phase 1 as the stated
     * capacity; this table is the per-tank record an issue fills, because an issue that cannot name
     * a tank cannot be reconciled against a capacity. The composite unique
     * `(company_id, truck_id, kind)` is the spec's `composite_uk`: two main tanks on one truck is a
     * data-entry error, not a fleet.
     */
    public function up(): void
    {
        Schema::create('fuel_tanks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('truck_id')->constrained('trucks');

            $table->string('kind', 10);
            $table->unsignedSmallInteger('capacity_litres');
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'truck_id', 'kind'], 'fuel_tanks_company_truck_kind_unique');
        });

        DB::statement('alter table `fuel_tanks` add constraint `fuel_tanks_kind_allowed` check (kind in (\'main\', \'auxiliary\'))');
        DB::statement('alter table `fuel_tanks` add constraint `fuel_tanks_capacity_positive` check (capacity_litres > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_tanks');
    }
};
