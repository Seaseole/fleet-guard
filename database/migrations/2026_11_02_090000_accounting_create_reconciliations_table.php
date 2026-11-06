<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5's reconciliation UI, slice 5d: a reconciliation is the accountant's *control
     * record*, not a posting. It names one linked register account at one statement date, holds
     * the bank's own closing figure, lists the reconciling items between it and the book, and is
     * approved only when the two agree to the minor unit. Correcting a wrong approval is void +
     * start again (ADR-0001's shape for anything that was ever signed), never edit-in-place.
     *
     * `book_base_amount` is a snapshot, not a projection to be trusted forever: it is frozen from
     * the ledger door at start and re-frozen at approval, because the approval is the moment a
     * person said "these are the figures" — and a later posting into the same day must not
     * rewrite what was signed. `documents:number-integrity` and `ledger:verify` still own drift.
     *
     * Base currency only in this slice (the decision 5, same rule as PAY-): a USD statement's
     * rate arrives with the FX slice, and the CHECK below is what makes that migration an
     * intentional loosening rather than a silent behavior change.
     */
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40);
            $table->string('status', 12)->default('open');

            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->date('statement_on');

            // Verbatim.
            $table->char('currency_code', 3);
            $table->bigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3)->default('BWP');
            $table->bigInteger('base_amount');

            // The ledger's own figure for the linked account at the statement date, frozen twice:
            // signed, because a register in overdraft is a fact the sheet must show negative.
            $table->bigInteger('book_base_amount');

            $table->string('note', 500)->nullable();

            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'reconciliations_company_number_unique');
            $table->index(['company_id', 'bank_account_id', 'statement_on'], 'reconciliations_company_account_statement_index');
        });

        // One live run per account per statement date; a voided run frees the day for the retry
        // (the same generated-column trick as `bills.live_linked_source_id`, /).
        DB::statement('alter table `reconciliations` add column `live_statement_on` date generated always as (case when `status` <> \'void\' then `statement_on` else null end) stored');
        DB::statement('alter table `reconciliations` add constraint `reconciliations_live_day_unique` unique (`company_id`, `bank_account_id`, `live_statement_on`)');

        DB::statement('alter table `reconciliations` add constraint `reconciliations_status_allowed` check (status in (\'open\', \'approved\', \'void\'))');
        DB::statement('alter table `reconciliations` add constraint `reconciliations_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `reconciliations` add constraint `reconciliations_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `reconciliations` add constraint `reconciliations_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
        DB::statement('alter table `reconciliations` add constraint `reconciliations_base_currency_only` check (currency_code = base_currency_code)');
        // A signature needs its signer: approval without the person and the instant is a column set
        // that lies about who said the numbers agree.
        DB::statement('alter table `reconciliations` add constraint `reconciliations_approved_needs_signer` check (status != \'approved\' or (approved_by is not null and approved_at is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
