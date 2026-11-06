<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One application of a supplier payment to one bill.
     *
     * The allocation rows are the truth of whether a bill is paid; `bills.balance_amount` is their
     * projection (the rule, copied to the payable side for the same reason: ageing and the AP
     * total must be a query, not a walk over every payment on the screen).
     *
     * `settled` marks the application that closed the bill, the same flag that lets the tie-out tell
     * a zeroed balance from a partly paid one without re-walking the FIFO order (the decision 3).
     * A reversed row is evidence that counts for nothing — the mirror, not the balance, answers a
     * wrong application, and `reversed` exists so 5c's correction path has the column the customer
     * side already learned to need.
     */
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('supplier_payment_id', 26);
            $table->char('bill_id', 26);

            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->char('base_currency_code', 3)->default('BWP');
            $table->unsignedBigInteger('base_amount');
            $table->boolean('settled')->default(false);
            $table->boolean('reversed')->default(false);
            $table->date('applied_on');
            $table->unsignedBigInteger('applied_by')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'bill_id'], 'supplier_payment_allocations_bill_index');
            $table->index(['company_id', 'supplier_payment_id'], 'supplier_payment_allocations_payment_index');
        });

        DB::statement('alter table `supplier_payment_allocations` add constraint `spa_payment_fk` foreign key (`supplier_payment_id`) references `supplier_payments` (`id`) on delete cascade');
        DB::statement('alter table `supplier_payment_allocations` add constraint `spa_bill_fk` foreign key (`bill_id`) references `bills` (`id`)');

        DB::statement('alter table `supplier_payment_allocations` add constraint `spa_amount_positive` check (amount > 0 and base_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
