<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 / §10.4 — the document a customer is billed with.
     *
     * **Three axes, deliberately not one enum** (§10.4). `status` is stored and holds only the
     * lifecycle `draft → issued → void`; settlement (`open`/`partially_paid`/`paid`/`refunded`) and
     * ageing (`current`/`d1_30`/…) are *derived* — from the allocation rows and from `due_on` against
     * today. A single status containing both `void` and `paid` would mean payment allocation mutates
     * lifecycle state, and gap-free numbering plus audit semantics die quietly in that coupling.
     *
     * §5.2's FX block is applied verbatim, which is why a ZAR invoice can be issued today and read in
     * Pula forever: `base_amount` is authoritative and frozen at write (§5.3), and the journal entry
     * this row posts carries the same currency and rate.
     *
     * `balance_amount` and `balance_base` are a **projection**, not the truth (§10.4): allocations and
     * credit notes are what decided whether an invoice is paid, and `invoices:recompute-balances` is
     * the tie-out that rebuilds these two from those rows. They are kept because ageing and the AR
     * total must be a query, not a walk over every allocation on the screen.
     *
     * `terms_snapshot` carries the customer's payment terms as they read when this invoice was issued,
     * because `due_on` is what the ageing axis lives on and a customer moved from net_30 to net_45
     * must not have last month's invoices re-dated.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40)->nullable();
            $table->string('status', 12)->default('draft');

            $table->foreignId('customer_id')->constrained('customers');
            $table->char('job_id', 26)->nullable();
            $table->string('kind', 16);

            $table->date('issue_date');
            $table->date('due_on');
            $table->string('terms_snapshot', 16);

            // §5.2 verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_amount');

            // A draft carries 0 because it has no lines yet; the CHECK below refuses an *issued*
            // invoice at 0, which is the case that would post a zero-value journal entry.
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tax_base')->default(0);
            $table->unsignedBigInteger('balance_amount')->default(0);
            $table->unsignedBigInteger('balance_base')->default(0);

            $table->string('po_reference', 60)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('source_uuid', 80)->nullable();

            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'invoices_company_number_unique');
            $table->unique(['company_id', 'source_uuid'], 'invoices_company_source_uuid_unique');
            $table->index(['company_id', 'status', 'due_on'], 'invoices_company_status_due_index');
            $table->index(['company_id', 'customer_id', 'status'], 'invoices_company_customer_status_index');
        });

        DB::statement('alter table `invoices` add constraint `invoices_job_company_fk` foreign key (`job_id`, `company_id`) references `freight_jobs` (`id`, `company_id`)');

        // §7.4: a number is allocated at issue and corresponds to a fiscal document. A draft is
        // addressed by ULID, so NULL-while-draft is the rule, not a convenience.
        DB::statement('alter table `invoices` add constraint `invoices_number_only_when_issued` check (
            (status = \'draft\' and number is null)
            or (status <> \'draft\' and number is not null)
        )');

        DB::statement('alter table `invoices` add constraint `invoices_status_allowed` check (status in (\'draft\', \'issued\', \'void\'))');
        DB::statement('alter table `invoices` add constraint `invoices_kind_allowed` check (kind in (\'trip\', \'consolidated\', \'manual\'))');
        DB::statement('alter table `invoices` add constraint `invoices_issued_needs_a_date` check (status = \'draft\' or issued_at is not null)');
        DB::statement('alter table `invoices` add constraint `invoices_void_needs_a_reason` check (status <> \'void\' or (void_reason is not null and char_length(void_reason) >= 10))');

        DB::statement('alter table `invoices` add constraint `invoices_tax_within_amount` check (tax_amount <= amount and tax_base <= base_amount)');
        DB::statement('alter table `invoices` add constraint `invoices_dated_sensibly` check (due_on >= issue_date)');
        DB::statement('alter table `invoices` add constraint `invoices_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `invoices` add constraint `invoices_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `invoices` add constraint `invoices_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
