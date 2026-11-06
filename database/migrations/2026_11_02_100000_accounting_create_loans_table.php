<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The financing entities, first half: the LOAN as the ERD spells it — lender as supplier,
     * purpose, the optional truck the money bought, principal with the block, the annual rate,
     * the repayment frequency, the first due date and the bank the instalments leave from.
     *
     * The design is the machine: `draft → submitted → approved → disbursed → servicing → closed` with
     * `servicing ⇄ arrears`, `arrears → defaulted → written_off | recovered`, `servicing →
     * restructured`, and `approved → cancelled` **only before disbursement** — a loan that had
     * money move is corrected with postings, not a status edit, which is why the CHECK refuses a
     * cancelled row that carries a disbursement.
     *
     * The financing edge is stored here (`truck_id`, a names-only reference in the style, not an
     * FK across module tables); `trucks.loan_id` in the ERD is the same fact read from the other
     * side, and that screen can query this column without a second source.
     *
     * Base currency only (the decision 5, same wall as the reconciliation): a USD
     * facility's rate and its realised FX pair arrive with the FX slice, and the CHECK is what
     * makes loosening it an intentional migration rather than a silent behaviour change.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('reference', 40);
            $table->string('status', 16)->default('draft');
            $table->string('purpose', 20);

            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->unsignedBigInteger('truck_id')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');

            // Verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('principal');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_principal');

            // The annual rate as a percentage; instalment rows freeze what each period charges —
            // The split is read from stored rows, never recomputed at report time.
            $table->decimal('interest_rate', 9, 4);
            $table->string('frequency', 12);
            $table->date('first_payment_on');
            $table->unsignedSmallInteger('repayment_count');

            $table->date('disbursed_on')->nullable();
            $table->char('disbursement_entry_id', 26)->nullable();
            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'loans_company_reference_unique');
            $table->index(['company_id', 'status'], 'loans_company_status_index');
        });

        DB::statement('alter table `loans` add constraint `loans_status_allowed` check (status in (\'draft\', \'submitted\', \'approved\', \'cancelled\', \'disbursed\', \'servicing\', \'arrears\', \'restructured\', \'closed\', \'defaulted\', \'written_off\', \'recovered\'))');
        DB::statement('alter table `loans` add constraint `loans_purpose_allowed` check (purpose in (\'vehicle\', \'equipment\', \'working_capital\'))');
        DB::statement('alter table `loans` add constraint `loans_frequency_allowed` check (frequency in (\'monthly\', \'fortnightly\'))');
        DB::statement('alter table `loans` add constraint `loans_principal_positive` check (principal > 0 and base_principal > 0 and repayment_count > 0)');
        DB::statement('alter table `loans` add constraint `loans_rate_nonnegative` check (interest_rate >= 0)');
        DB::statement('alter table `loans` add constraint `loans_base_currency_only` check (currency_code = base_currency_code)');
        DB::statement('alter table `loans` add constraint `loans_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `loans` add constraint `loans_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        // The one-way door as data: cancelled means *no money moved*. A row that disbursed
        // cannot claim cancellation; it is corrected by postings.
        DB::statement('alter table `loans` add constraint `loans_cancelled_never_disbursed` check (status != \'cancelled\' or disbursement_entry_id is null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
