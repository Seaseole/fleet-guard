<?php

declare(strict_types=1);

use App\Domain\Kernel\Cutover\Support\OpeningCoverage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.5's last two opening files — the advances a driver still owes, and the finance book
 * the trial balance's `2210` line belongs to.
 *
 * One CHECK widens, and it widens once for both datasets because they arrive together: the intake
 * cannot learn a dataset the engine has no handler for, and a dataset the engine cannot validate must
 * not be storable (the rule since Phase 1).
 *
 * Note what did *not* change. `loans` keeps its status CHECK and `loan_installments` its rows: an
 * opening loan is created through the same door a new one uses, so the plan is the same shape — flat
 * principal, declining interest, the remainder on the last row — built from the principal
 * that is still owing over the instalments that are still to come.
 *
 * One column does arrive, and it is the intake's own: `loans.source_uuid`, the same nullable natural
 * key `invoices`, `bills`, `payments`, `credit_notes`, `supplier_payments`, `fuel_purchases` and
 * `fuel_issues` carry. An opening facility is numbered from today's sequence — the register
 * is starting, not being back-filled — so the legacy loan number has to live somewhere the retry can
 * find it. Without it a re-applied file would raise a second facility for the same truck.
 *
 * The advance file posts to `1160`, which is why {@see OpeningCoverage}
 * now treats that account the way it treats `1150`, `2110` and `1180`: one account, one opener. The
 * loan file posts **nothing**, so it names no account — the liability is the trial balance's line and
 * always was; what comes forward here is the schedule that says *when* it is owed.
 */
return new class extends Migration
{
    private const WITH_INTAKE = "('opening_trial_balance', 'opening_receivables', 'opening_payables', 'opening_odometer_readings', 'opening_tank_stock', 'opening_driver_advances', 'opening_loans', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts')";

    private const WITHOUT_INTAKE = "('opening_trial_balance', 'opening_receivables', 'opening_payables', 'opening_odometer_readings', 'opening_tank_stock', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts')";

    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('source_uuid', 80)->nullable()->after('notes');
            $table->unique(['company_id', 'source_uuid'], 'loans_company_source_uuid_unique');
        });

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in '.self::WITH_INTAKE.')');
    }

    public function down(): void
    {
        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement('alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in '.self::WITHOUT_INTAKE.')');

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropUnique('loans_company_source_uuid_unique');
            $table->dropColumn('source_uuid');
        });
    }
};
