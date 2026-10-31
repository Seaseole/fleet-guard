<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 / §6.8 — the supplier's own document for work the fleet had done for it.
     *
     * A bill is the document, not the obligation. Phase 3 already wrote the ledger's debt for the
     * cases it could value at the shelf or the pump (`Dr 1180/1190 · Cr 2110`, §20.26/§20.29); a
     * garage repair never reached the ledger at all, and its expense debit arrives here with the
     * bill (§6.8's `BillRecorded`). A bill that names no linked document posts its own lines; the
     * settlement of either kind is the payment slice, not this row.
     *
     * The lifecycle is §10's smaller-machine shape: `draft → recorded → void`. `partially_paid`
     * and `paid` will be **derived** from the settlement rows the way an invoice's settlement is
     * (§10.4's three axes), so they are deliberately absent from the CHECK until a payment door
     * exists that could write them.
     *
     * The number is an operational reference, allocated at creation like `WO-` and `TRP-` (§7):
     * a bill is the supplier's fiscal document, not ours, so our own reference needs "never
     * reused" rather than "gap-free at issue". The supplier's document number is stored beside it
     * as evidence, not as identity.
     *
     * §5.2's money block is applied verbatim, but this slice's Action refuses a foreign-currency
     * bill the same way issuing refuses one: the rate a bill is recorded at is the FX slice's
     * decision, and an invented rate is a ledger entry that disagrees with the document.
     */
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('number', 40);
            $table->string('status', 12)->default('draft');

            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('supplier_doc_no', 60)->nullable();

            $table->date('bill_on');
            $table->date('due_on');

            // §5.2 verbatim — gross, VAT included; the lines carry their own net and tax.
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

            $table->string('note', 500)->nullable();
            $table->string('source_uuid', 80)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number'], 'bills_company_number_unique');
            $table->unique(['company_id', 'source_uuid'], 'bills_company_source_uuid_unique');
            $table->index(['company_id', 'status', 'due_on'], 'bills_company_status_due_index');
            $table->index(['company_id', 'supplier_id', 'status'], 'bills_company_supplier_status_index');
        });

        DB::statement('alter table `bills` add constraint `bills_status_allowed` check (status in (\'draft\', \'recorded\', \'void\'))');
        DB::statement('alter table `bills` add constraint `bills_recorded_needs_a_date` check (status = \'draft\' or recorded_at is not null)');
        DB::statement('alter table `bills` add constraint `bills_void_needs_a_reason` check (status <> \'void\' or (void_reason is not null and char_length(void_reason) >= 10))');
        DB::statement('alter table `bills` add constraint `bills_dated_sensibly` check (due_on >= bill_on)');
        DB::statement('alter table `bills` add constraint `bills_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `bills` add constraint `bills_tax_within_amount` check (tax_amount < amount and tax_base < base_amount)');
        DB::statement('alter table `bills` add constraint `bills_rate_positive` check (fx_rate > 0)');
        DB::statement('alter table `bills` add constraint `bills_source_allowed` check (fx_rate_source in (\'manual\', \'rbg_daily\', \'bpb\', \'contract\', \'customer_agreed\', \'bank_statement\', \'opening_balance\', \'manual_override\'))');
        DB::statement('alter table `bills` add constraint `bills_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
