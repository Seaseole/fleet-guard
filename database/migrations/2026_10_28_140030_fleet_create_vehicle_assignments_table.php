<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VEHICLE_ASSIGNMENT — who drove what, with which trailer, from when to when.
     *
     * The brief's "history is mandatory" is why this is an append-only table and not three columns
     * on `trucks`: ending an assignment sets `to_at`, it never deletes or overwrites. The pair
     * (truck, from_at) and (driver, from_at) is what Phase 2's overlap check reads, which is the
     * same rule that stops one driver being dispatched on two corridors at once.
     *
     * `trip_id` is a ULID string without a foreign key: trips arrive in Phase 2, and a column that
     * points at a table which does not exist yet must be nullable and unconstrained rather than a
     * migration that has to be rewritten.
     */
    public function up(): void
    {
        Schema::create('vehicle_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->foreignId('truck_id')->constrained('trucks');
            $table->foreignId('trailer_id')->nullable()->constrained('trailers');
            $table->foreignId('driver_id')->nullable()->constrained('drivers');

            $table->char('trip_id', 26)->nullable();

            $table->datetime('from_at');
            $table->datetime('to_at')->nullable();

            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->string('reason', 255)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'truck_id', 'from_at'], 'vehicle_assignments_truck_from_index');
            $table->index(['company_id', 'driver_id', 'from_at'], 'vehicle_assignments_driver_from_index');
            $table->index(['company_id', 'trailer_id'], 'vehicle_assignments_trailer_index');
        });

        DB::statement('alter table `vehicle_assignments` add constraint `vehicle_assignments_end_after_start` check (to_at is null or to_at >= from_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_assignments');
    }
};
