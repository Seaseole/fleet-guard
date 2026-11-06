<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The amortisation as rows (LOAN_INSTALLMENT): seq, due date, the frozen principal/
     * interest split, paid counters, status and the journal entry that paid it. The rule the
     * whole design repeats — "split read from stored schedule rows, never recomputed at report
     * time" — so the 13-week forecast and the arrears list are ordinary queries against dates
     * and figures that were decided once, at origination or restructuring.
     *
     * `restructured` exists for the "a prepayment **appends** revised schedule rows and never
     * edits a paid row": a superseded plan keeps its rows visible, and the loan's live picture is
     * the unpaid rows of its current plan. The id is a plain bigint per the ERD — these rows are
     * pure children, never quoted across modules.
     */
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->char('loan_id', 26);

            $table->unsignedSmallInteger('seq');
            $table->date('due_on');
            $table->unsignedBigInteger('principal_due');
            $table->unsignedBigInteger('interest_due');
            $table->unsignedBigInteger('total_due');
            $table->unsignedBigInteger('paid_principal')->default(0);
            $table->unsignedBigInteger('paid_interest')->default(0);

            $table->string('status', 16)->default('scheduled');
            $table->timestamp('paid_at')->nullable();
            $table->char('entry_id', 26)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'due_on', 'status'], 'loan_installments_company_due_status_index');
        });

        DB::statement('alter table `loan_installments` add constraint `loan_installments_loan_fk` foreign key (`loan_id`) references `loans` (`id`) on delete cascade');
        DB::statement('alter table `loan_installments` add constraint `loan_installments_status_allowed` check (status in (\'scheduled\', \'partial\', \'paid\', \'overdue\', \'restructured\'))');
        DB::statement('alter table `loan_installments` add constraint `loan_installments_total_is_the_split` check (total_due = principal_due + interest_due)');
        DB::statement('alter table `loan_installments` add constraint `loan_installments_paid_within_due` check (paid_principal <= principal_due and paid_interest <= interest_due)');

        DB::statement('alter table `loan_installments` add constraint `loan_installments_company_loan_seq_unique` unique (`company_id`, `loan_id`, `seq`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
