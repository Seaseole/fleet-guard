<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money the customer sent, before anyone decided which invoice it was for.
     *
     * The receipt and its allocations are **two documents, not one** (the `PaymentReceived` vs
     * `PaymentAllocated`): a payment that arrives against a customer's account must be bankable today
     * and applied when the invoice appears, and a payment applied to nothing is still real money in the
     * bank. That is why `unapplied_amount` is a column rather than an absent child row — the ledger's
     * credit to `2150 Unapplied customer cash` has to balance against something on this row.
     *
     * `number` is the gap-free `receipt` sequence and is allocated **at creation**, not at issue: a
     * receipt is a bank fact, there is no draft state for money that has already arrived, and the
     * "number at issue" rule exists to stop *drafts* consuming fiscal numbers. The store registration in
     * `config/fleet.php` declares no `status_column` for exactly that reason.
     *
     * The FX block again, because a corridor customer pays in ZAR and the ledger reads Pula. The
     * rate the payment was booked at is what realised FX differences are measured against, so it
     * is stored here and frozen.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40);
            $table->string('status', 12)->default('recorded');

            $table->foreignId('customer_id')->constrained('customers');

            $table->date('received_on');
            $table->string('method', 12);
            $table->string('reference', 80)->nullable();

            // Verbatim.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_amount');

            // The projection the allocation rows maintain: what is still sitting on the account
            // unclaimed by any invoice. `invoices:recompute-balances`'s sibling tie-out for receipts.
            $table->unsignedBigInteger('unapplied_amount')->default(0);
            $table->unsignedBigInteger('unapplied_base')->default(0);

            $table->char('invoice_id', 26)->nullable();
            $table->string('source_uuid', 80)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->string('void_reason', 300)->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'payments_company_number_unique');
            $table->unique(['company_id', 'source_uuid'], 'payments_company_source_uuid_unique');
            $table->index(['company_id', 'customer_id', 'received_on'], 'payments_company_customer_date_index');
            $table->index(['company_id', 'status'], 'payments_company_status_index');
        });

        DB::statement('alter table `payments` add constraint `payments_invoice_fk` foreign key (`invoice_id`) references `invoices` (`id`)');

        DB::statement('alter table `payments` add constraint `payments_number_never_null` check (number is not null and char_length(number) > 0)');
        DB::statement('alter table `payments` add constraint `payments_status_allowed` check (status in (\'recorded\', \'void\'))');
        DB::statement('alter table `payments` add constraint `payments_method_allowed` check (method in (\'bank\', \'cash\', \'card\', \'mobile_money\'))');
        DB::statement('alter table `payments` add constraint `payments_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `payments` add constraint `payments_unapplied_within_amount` check (unapplied_amount <= amount and unapplied_base <= base_amount)');
        DB::statement('alter table `payments` add constraint `payments_void_needs_a_reason` check (status <> \'void\' or (void_reason is not null and char_length(void_reason) >= 10))');
        DB::statement('alter table `payments` add constraint `payments_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `payments` add constraint `payments_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `payments` add constraint `payments_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
