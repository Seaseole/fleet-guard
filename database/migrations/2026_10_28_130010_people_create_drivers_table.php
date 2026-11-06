<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DRIVER — the employee as a driver, with the two papers that decide whether they may be
     * dispatched: the licence and the PRDP permit.
     *
     * Both expiry dates live **here**, on the driver row, and not on the scan of the card. A scan
     * is evidence of what was on file; these columns are the fact dispatch reads, so the
     * "planned → assigned checks licence and permit on the trip's start date" is one column read
     * rather than a walk through documents to find the newest one that happens to carry an expiry.
     * The scan still attaches as a document — with no expiry of its own, so the register cannot
     * report the same lapse twice under two different dates.
     *
     * `status` is the state column: transitions are `active|standby|suspended|left`, and the
     * reason for suspension or leaving is mandatory in the Action and lands in `operational_events`.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            // One driver row per employee. A driver is a person on the books, not a second place
            // personal data can live: name, national ID and pay stay on `employees`.
            $table->foreignId('employee_id')->constrained('employees');
            $table->unique(['company_id', 'employee_id'], 'drivers_company_employee_unique');

            $table->string('licence_no_masked', 32)->nullable();
            $table->text('licence_no_encrypted')->nullable();
            $table->string('key_version', 32)->nullable();

            // SADC licence codes vary (`B`, `C1`, `EB`, `EC`); the column is free text rather than
            // a CHECK list because a value list here would be a border-by-border fact, not an
            // application rule.
            $table->string('licence_code', 10)->nullable();

            $table->date('licence_expires_on')->nullable();
            $table->date('permit_expires_on')->nullable();

            $table->unsignedBigInteger('default_allowance_rate_base')->default(0);
            $table->decimal('fuel_efficiency_baseline_10_2', 8, 2)->nullable();

            $table->string('status', 16)->default('active');
            $table->string('previous_state', 16)->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->boolean('kiosk_enabled')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'status'], 'drivers_company_status_index');
            $table->index(['company_id', 'licence_expires_on'], 'drivers_company_licence_index');
            $table->index(['company_id', 'permit_expires_on'], 'drivers_company_permit_index');
        });

        DB::statement('alter table `drivers` add constraint `drivers_status_allowed` check (status in (\'active\', \'standby\', \'suspended\', \'left\'))');
        DB::statement('alter table `drivers` add constraint `drivers_previous_state_allowed` check (previous_state is null or previous_state in (\'active\', \'standby\', \'suspended\', \'left\'))');

        DB::statement('alter table `drivers` add constraint `drivers_licence_no_is_a_pair` check ((licence_no_masked is null and licence_no_encrypted is null) or (licence_no_masked is not null and licence_no_encrypted is not null))');

        // A decimal on a fuel baseline is sanctioned by D6: it is a consumption rate, not money.
        DB::statement('alter table `drivers` add constraint `drivers_baseline_not_negative` check (fuel_efficiency_baseline_10_2 is null or fuel_efficiency_baseline_10_2 >= 0)');
        DB::statement('alter table `drivers` add constraint `drivers_allowance_not_negative` check (default_allowance_rate_base >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
