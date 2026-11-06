<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * / Money going out to a supplier: `PaymentRecorded`'s mirror image.
     *
     * The receipt's rule holds here too (the decision 1): **the payment is made, then it is
     * applied.** `RecordSupplierPayment` posts `Dr 2110 Accounts payable / Cr 1120 Bank` — a real
     * reduction of the liability, never a direct hit on one invoice's row — and application walks
     * the supplier's open bills in `bill_on` order through `supplier_payment_allocations`. Money
     * that no bill has claimed stays as `unapplied_amount`: a prepayment is somebody's supplier
     * cash, not an adjustment to a document.
     *
     * The number is operational (`PAY-{SEQ:5}`, continuous, allocated at creation): a payment is our
     * bank fact, not a fiscal document the counterparty numbers.
     *
     * Base currency only in this slice (the decision 5): the FX slice carries a ZAR payment's
     * rate and its realised gain/loss pair (`7510`/`7511`), and inventing one here would be a ledger
     * entry that disagrees with the bank statement.
     */
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40);
            $table->string('status', 12)->default('recorded');

            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->date('paid_on');
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

            $table->unsignedBigInteger('unapplied_amount')->default(0);
            $table->unsignedBigInteger('unapplied_base')->default(0);

            $table->string('note', 500)->nullable();
            $table->string('source_uuid', 80)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'supplier_payments_company_number_unique');
            $table->unique(['company_id', 'source_uuid'], 'supplier_payments_company_source_uuid_unique');
            $table->index(['company_id', 'supplier_id', 'paid_on'], 'supplier_payments_company_supplier_paid_index');
        });

        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_status_allowed` check (status in (\'recorded\', \'void\'))');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_unapplied_within_amount` check (unapplied_amount <= amount and unapplied_base <= base_amount)');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_method_allowed` check (method in (\'bank\', \'cash\'))');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `supplier_payments` add constraint `supplier_payments_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
