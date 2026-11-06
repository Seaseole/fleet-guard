<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document that corrects an invoice without touching it.
     *
     * A credit note is a **separate document with its own numbering scope**, and it never rewinds the
     * invoice's lifecycle: an issued invoice stays issued, and its derived settlement state simply
     * moves toward `refunded` as this credit is applied. That separation is what keeps gap-free numbering
     * honest — a correction that silently un-issued an invoice would leave a fiscal number pointing at
     * nothing.
     *
     * Four lifecycle states, and the third is the one people get wrong: `applied` means the credit has been
     * allocated against the invoice, not merely approved. A partial application therefore keeps `issued`
     * with a remaining balance, which is why `balance_amount` lives here too rather than the status carrying
     * the whole answer.
     *
     * `void` is deliberately absent. Constrains an invoice void once a payment exists; a credit note is
     * the answer to "the invoice was wrong", so the only retreat for one that has not been issued is
     * `draft → cancelled`, and the CHECK says so.
     *
     * The FX block, verbatim: a credit in ZAR against an invoice in ZAR must reduce the invoice's
     * currency-native balance by the figure the customer sees, not by a Pula amount re-derived at today's rate.
     */
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40)->nullable();
            $table->string('status', 12)->default('draft');

            $table->foreignId('customer_id')->constrained('customers');
            $table->char('invoice_id', 26);
            $table->char('credit_for', 26)->nullable();

            $table->date('issue_date');
            $table->string('reason', 30);

            // Verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_amount');

            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tax_base')->default(0);
            $table->unsignedBigInteger('balance_amount')->default(0);
            $table->unsignedBigInteger('balance_base')->default(0);

            $table->text('narrative')->nullable();
            $table->string('source_uuid', 80)->nullable();

            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'credit_notes_company_number_unique');
            $table->unique(['company_id', 'source_uuid'], 'credit_notes_company_source_uuid_unique');
            $table->index(['company_id', 'invoice_id'], 'credit_notes_company_invoice_index');
            $table->index(['company_id', 'customer_id', 'status'], 'credit_notes_company_customer_status_index');
        });

        DB::statement('alter table `credit_notes` add constraint `credit_notes_invoice_fk` foreign key (`invoice_id`) references `invoices` (`id`)');

        DB::statement('alter table `credit_notes` add constraint `credit_notes_number_only_when_issued` check (
            (status = \'draft\' and number is null)
            or (status <> \'draft\' and number is not null)
        )');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_status_allowed` check (status in (\'draft\', \'issued\', \'applied\', \'settled\', \'cancelled\'))');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_reason_allowed` check (reason in (\'over_billed\', \'pricing_error\', \'goods_damaged\', \'service_not_rendered\', \'void_leg\', \'other\'))');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_issued_needs_a_date` check (status = \'draft\' or status = \'cancelled\' or issued_at is not null)');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_cancelled_from_draft` check (
            status <> \'cancelled\' or (number is null and issued_at is null)
        )');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_cancellation_needs_a_reason` check (
            status <> \'cancelled\' or (cancellation_reason is not null and char_length(cancellation_reason) >= 10)
        )');
        // The partial-application rule stated as data: an `issued` note still has a balance to apply,
        // and once the whole balance is allocated the note is `applied` (or `settled`). A row that says
        // issued with nothing left to apply is the drift this CHECK exists to refuse.
        DB::statement('alter table `credit_notes` add constraint `credit_notes_balance_matches_status` check (
            status in (\'draft\', \'cancelled\', \'applied\', \'settled\')
            or (status = \'issued\' and balance_amount > 0)
        )');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_tax_within_amount` check (tax_amount <= amount and tax_base <= base_amount)');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `credit_notes` add constraint `credit_notes_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
