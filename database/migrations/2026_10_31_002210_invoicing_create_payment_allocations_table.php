<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 — the act of saying which invoice a payment was for.
     *
     * An allocation is its own row (and, per §6.8, its own ledger seam) because the design refuses to
     * "adjust" an invoice when a customer overpays: the money is real, the invoice is not, so the residue
     * sits in `2150 Unapplied customer cash` until a later allocation finds it. `payment_id` is therefore
     * not a one-to-one attribute of an invoice — one receipt can settle three invoices and one invoice can
     * be settled by four receipts.
     *
     * **The residue rule lives in the Action, and the table is what makes it provable.** §5.5 allocates in
     * FIFO order of invoice age and lets the *last* allocation absorb the ±1 minor-unit residue so a paid
     * invoice's balance reaches exactly zero. That is only checkable if each row carries its own
     * currency-native amount beside its base amount, which is why both are stored rather than deriving the
     * currency figure from `base ÷ rate` at read time (§5.3: a historical amount re-derived from a current
     * rate is a rewritten history).
     *
     * `settled` marks the allocation that closed the invoice — the one the residue was absorbed into — so
     * the tie-out command can tell a zeroed balance from a partly paid one without re-running the FIFO walk.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->char('payment_id', 26);
            $table->char('invoice_id', 26);

            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_amount');

            // The difference between this allocation's base and the same figure at the invoice's own rate.
            // Stored, not recomputed at report time, because §5.5's realised gain or loss is a fact about
            // the day the money moved.
            $table->boolean('has_fx_difference')->default(false);
            $table->unsignedBigInteger('fx_difference_base')->default(0);
            $table->string('fx_difference_side', 8)->nullable();

            $table->date('allocated_on');
            $table->boolean('settled')->default(false);
            $table->boolean('reversed')->default(false);
            $table->string('reversal_reason', 300)->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->foreignId('allocated_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'payment_id'], 'payment_allocations_company_payment_index');
            $table->index(['company_id', 'invoice_id', 'allocated_on'], 'payment_allocations_company_invoice_index');
            $table->index(['company_id', 'reversed'], 'payment_allocations_company_reversed_index');
        });

        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_payment_fk` foreign key (`payment_id`) references `payments` (`id`) on delete cascade');
        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_invoice_fk` foreign key (`invoice_id`) references `invoices` (`id`)');

        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_amount_positive` check (amount > 0 and base_amount > 0)');
        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_side_allowed` check (
            (has_fx_difference = 0 and fx_difference_base = 0 and fx_difference_side is null)
            or (has_fx_difference = 1 and fx_difference_base > 0 and fx_difference_side in (\'gain\', \'loss\'))
        )');
        // A reversal never deletes: the row stays as the evidence the money was applied, and a reversed
        // allocation counts as nothing to the projection while still naming its own reason.
        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_reversal_needs_a_reason` check (
            reversed = 0 or (reversal_reason is not null and char_length(reversal_reason) >= 10)
        )');
        DB::statement('alter table `payment_allocations` add constraint `payment_allocations_currency_upper` check (currency_code = upper(currency_code) and base_currency_code = upper(base_currency_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
