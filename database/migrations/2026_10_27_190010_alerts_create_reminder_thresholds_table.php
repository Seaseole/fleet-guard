<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REMINDER_THRESHOLD — "thresholds are rows, not constants".
     *
     * The column is called `family`, not `type` as sketches it, and the rename is the point: the
     * document's own vocabulary puts two different words in one slot. `alerts.type` answers *what kind
     * of alert* (`doc_expiry`), while the threshold's value answers *what kind of paper or thing*
     * (`licence`, `insurance`, `service`). One column name for both is how a reviewer ends up joining
     * `doc_expiry` to a threshold row named `doc_expiry` that nothing wrote, and reading that as
     * "reminders are configured". They are not configured; the join is empty.
     *
     * A family with **no rows** means no reminders for it — deliberately, because the rows are the
     * contract (the "editable by the Owner, not constants" only means something if deleting a row
     * deletes the reminder). `reminders:ensure-thresholds` gives every company the default set the
     * first time it is run, so the absence is always an owner's choice rather than an oversight.
     */
    public function up(): void
    {
        Schema::create('reminder_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('family', 32);
            $table->smallInteger('days_before');

            // "Recipients — role keys". Role keys rather than user ids, because the promise
            // is that a person's access follows their role: when the Accountant changes, the licence
            // reminders follow the role, not the inbox of whoever held it in March.
            $table->json('recipients');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'family', 'days_before'], 'reminder_thresholds_company_family_days_unique');
            $table->index(['company_id', 'family', 'active'], 'reminder_thresholds_company_family_index');
        });

        // The six illustrative values plus every document type that the design says carries an expiry
        // (contract, customs, work_order, maintenance) — the register holds all ten kinds in one
        // machine, so the threshold vocabulary has to be able to name each one that expires.
        DB::statement("alter table `reminder_thresholds` add constraint `reminder_thresholds_family_allowed` check (family in ('licence', 'permit', 'insurance', 'contract', 'customs', 'work_order', 'maintenance', 'service', 'loan', 'invoice'))");
        DB::statement('alter table `reminder_thresholds` add constraint `reminder_thresholds_days_positive` check (`days_before` > 0 and `days_before` <= 365)');

        // A threshold that notifies nobody is a configuration mistake that looks like a working
        // reminder. The engine would create the alert row, the register would show it, and the owner
        // would never hear about it — the failure mode the cooldown sentence is written against.
        //
        // Two attempts were measured before this one was right, and both are worth keeping down:
        // `json_type(recipients) = 'array'` refused *every* row, including a correct one, because
        // JSON_TYPE answers `ARRAY` in a binary collation and the lower-case literal never matches. And
        // `json_extract(recipients, '$[0]') is not null` — which reads as "it has a first element" —
        // accepts a bare string: MySQL answers `$[0]` of the scalar `"operations_manager"` by returning
        // the scalar itself, so the row that exists to be refused was the one that passed.
        //
        // So the shape is asserted on the type itself, in upper case on both sides.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $recipientsAreAList = $sqlite
            ? 'json_array_length(`recipients`) >= 1'
            : "upper(json_type(`recipients`)) = 'ARRAY' and json_length(`recipients`) >= 1";

        DB::statement("alter table `reminder_thresholds` add constraint `reminder_thresholds_recipients_are_a_list` check ({$recipientsAreAList})");
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_thresholds');
    }
};
