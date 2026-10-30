<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 SERVICE_SCHEDULE — "this truck is serviced every N km / hours / days, and it was last
     * done at X" (§15.2 phase 3).
     *
     * The row stores the *rule* and the *last service*; the due state is derived, not stored. §10.8
     * made that argument for document validity — a stored `expired` boolean is a second definition
     * of expiry that drifts from the first — and the same holds here: whether a schedule is due is a
     * function of the truck's odometer, the truck's engine hours, today's date and this row, and
     * every one of those changes without this row being written. A nightly job that stamped `state`
     * onto the row would make the register disagree with the odometer card above it the moment a trip
     * ended.
     *
     * `interval_value` and the two `*_at_value` columns carry kilometres for a `km` schedule and
     * engine hours **×100** for an `hours` one, so an hours interval is still an integer (§5.5's
     * scale applied to a physical quantity). A `date` schedule uses `interval_value` as days and
     * `last_done_on`/`next_due_on` for the dates. A `condition` schedule has no interval at all: it is
     * done when a person says the condition exists, and the register shows it without a due point.
     *
     * The CHECKs are what stop the two shapes from being mixed: a `km` schedule cannot be missing its
     * next odometer, a `date` one cannot carry numeric targets, and `next_due` may never sit behind
     * `last_done` — a schedule whose next service is in the past *relative to its own last service*
     * is a typo, not an overdue truck.
     */
    public function up(): void
    {
        Schema::create('service_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('truck_id')->constrained('trucks');

            $table->string('name', 80);
            $table->string('kind', 10);

            $table->unsignedInteger('interval_value')->nullable();
            $table->unsignedBigInteger('last_done_at_value')->nullable();
            $table->date('last_done_on')->nullable();
            $table->unsignedBigInteger('next_due_at_value')->nullable();
            $table->date('next_due_on')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'truck_id', 'name'], 'service_schedules_company_truck_name_unique');
            $table->index(['company_id', 'active', 'next_due_on'], 'service_schedules_company_active_date_index');
            $table->index(['company_id', 'truck_id', 'active'], 'service_schedules_company_truck_active_index');
        });

        DB::statement('alter table `service_schedules` add constraint `service_schedules_kind_allowed` check (kind in (\'km\', \'hours\', \'date\', \'condition\'))');

        // An interval belongs to every kind except `condition`, which is judged by a person.
        DB::statement('alter table `service_schedules` add constraint `service_schedules_interval_shape` check ((kind = \'condition\' and interval_value is null) or (kind <> \'condition\' and interval_value is not null and interval_value > 0))');

        // Each kind names its due point in its own unit, and no kind is allowed to be the other's shape.
        DB::statement('alter table `service_schedules` add constraint `service_schedules_due_point_present` check (
            (kind in (\'km\', \'hours\') and next_due_at_value is not null and next_due_on is null)
            or (kind = \'date\' and next_due_on is not null and next_due_at_value is null)
            or (kind = \'condition\' and next_due_on is null and next_due_at_value is null)
        )');

        DB::statement('alter table `service_schedules` add constraint `service_schedules_due_after_last` check (
            (last_done_at_value is null or next_due_at_value is null or next_due_at_value > last_done_at_value)
            and (last_done_on is null or next_due_on is null or next_due_on > last_done_on)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_schedules');
    }
};
