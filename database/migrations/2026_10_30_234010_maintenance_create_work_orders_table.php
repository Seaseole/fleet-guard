<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WORK_ORDER — one piece of workshop work against a truck, with the approval threshold that
     * makes the workshop answerable for what it spends.
     *
     * **The threshold is copied onto the row.** `approval_required_amount_base` holds the limit that
     * was in force when the order was raised, or zero when the estimate was already under it. Copying
     * it is the same reasoning as `fuel_issues.cost_base`: raising the company's limit next month must
     * not turn last month's Owner approval into a formality, and it must not make an unapproved order
     * look approved. The CHECK then states the control as a database fact — a work order that an
     * owner had to approve cannot reach `in_progress` without naming who did.
     *
     * `*_cost_base` are the frozen totals. They are recomputed from the lines while the order is open
     * and never after `closed`; the screen's number and the row's number are the same three
     * additions, and the CHECK keeps the parts adding up to the whole.
     *
     * **Posting is not a state.** Nothing here posts: parts post when stock leaves (phase 3e),
     * labour when a timesheet is approved, external work when the supplier bill is recorded (phase 5).
     * `closed` freezes editability, and that is all it does.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->string('wo_no', 20);

            $table->foreignId('truck_id')->constrained('trucks');
            $table->foreignId('trailer_id')->nullable()->constrained('trailers');
            $table->char('trip_id', 26)->nullable();
            $table->foreignId('service_schedule_id')->nullable()->constrained('service_schedules');

            $table->string('kind', 12);
            $table->string('status', 20)->default('draft');
            $table->string('title', 140);
            $table->string('description', 500)->nullable();

            $table->decimal('estimated_hours_10_2', 6, 2)->nullable();

            $table->char('base_currency_code', 3);
            $table->unsignedBigInteger('labour_cost_base')->default(0);
            $table->unsignedBigInteger('parts_cost_base')->default(0);
            $table->unsignedBigInteger('external_cost_base')->default(0);
            $table->unsignedBigInteger('total_cost_base')->default(0);

            $table->unsignedBigInteger('approval_required_amount_base')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();

            $table->foreignId('mechanic_employee_id')->nullable()->constrained('employees');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');

            $table->boolean('capitalised')->default(false);
            $table->unsignedBigInteger('capitalised_amount_base')->default(0);

            $table->string('hold_reason', 30)->nullable();
            $table->string('hold_note', 160)->nullable();

            $table->date('opened_on');
            $table->date('planned_for')->nullable();
            $table->unsignedInteger('completion_odometer_km')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->string('previous_state', 20)->nullable();
            $table->dateTime('state_changed_at')->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->unique(['company_id', 'wo_no'], 'work_orders_company_wo_no_unique');
            $table->index(['company_id', 'status', 'opened_on'], 'work_orders_company_status_index');
            $table->index(['company_id', 'truck_id', 'opened_on'], 'work_orders_company_truck_index');
            $table->index(['company_id', 'trip_id'], 'work_orders_company_trip_index');
        });

        DB::statement('alter table `work_orders` add constraint `work_orders_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`)');

        $statuses = "'draft', 'awaiting_approval', 'approved', 'planned', 'in_progress', 'on_hold', 'completed', 'inspected', 'closed', 'rejected', 'cancelled'";

        DB::statement("alter table `work_orders` add constraint `work_orders_status_allowed` check (status in ({$statuses}))");
        DB::statement("alter table `work_orders` add constraint `work_orders_previous_state_allowed` check (previous_state is null or previous_state in ({$statuses}))");

        DB::statement('alter table `work_orders` add constraint `work_orders_kind_allowed` check (kind in (\'service\', \'repair\', \'breakdown\', \'tyre\', \'inspection\', \'capital\'))');

        DB::statement('alter table `work_orders` add constraint `work_orders_hold_reason_allowed` check (hold_reason is null or hold_reason in (\'awaiting_parts\', \'external_garage\', \'awaiting_owner_approval\', \'vehicle_needed_on_trip\'))');

        // A hold has a reason and a status that is not on hold has none: a stale reason on a running
        // job is the screen saying "waiting for parts" about a job with a mechanic under it.
        DB::statement('alter table `work_orders` add constraint `work_orders_hold_is_explained` check ((status = \'on_hold\' and hold_reason is not null) or (status <> \'on_hold\' and hold_reason is null))');

        DB::statement('alter table `work_orders` add constraint `work_orders_totals_add_up` check (total_cost_base = labour_cost_base + parts_cost_base + external_cost_base)');

        // The single control: an order that needed an owner's approval cannot be worked without
        // one. Under-threshold orders carry a zero and need no approver.
        DB::statement('alter table `work_orders` add constraint `work_orders_started_is_approved` check (
            status not in (\'in_progress\', \'on_hold\', \'completed\', \'inspected\', \'closed\')
            or approval_required_amount_base = 0
            or approved_by is not null
        )');

        // Completing "requires odometer at fitment", so every state past it names the reading.
        DB::statement('alter table `work_orders` add constraint `work_orders_completed_has_odometer` check (
            status not in (\'completed\', \'inspected\', \'closed\') or completion_odometer_km is not null
        )');

        // Closing is what freezes the costs, and a frozen order that is not closed is a lie about
        // which month the money belongs to (trips make the same argument with `costs_frozen`).
        DB::statement('alter table `work_orders` add constraint `work_orders_closed_is_dated` check (status <> \'closed\' or closed_at is not null)');

        // The capitalise-vs-expense decision is made at close and cannot exceed what was spent.
        DB::statement('alter table `work_orders` add constraint `work_orders_capitalised_is_closed` check (
            capitalised = 0 or (status = \'closed\' and capitalised_amount_base > 0 and capitalised_amount_base <= total_cost_base)
        )');
        DB::statement('alter table `work_orders` add constraint `work_orders_capitalised_amount_only_when_capitalised` check (capitalised = 1 or capitalised_amount_base = 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
