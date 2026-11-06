<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BULK_TANK — the yard tank diesel is delivered into before a truck takes it.
     *
     * Three figures are stored, and only two of them are independent. `stock_value_base` is the
     * money the stock is worth, `stock_litres_x100` is the quantity (the two decimals), and
     * `average_cost_per_litre_minor` is their ratio **rounded for display** — the average a person
     * reads, not the number the issue cost is derived from. Deriving a cost from a rounded average
     * and then storing that cost would leave litres and value disagreeing by a cent per issue, so the
     * value is authoritative and the average is a cache the movement writer refreshes every time.
     *
     * The last CHECK is the invariant that makes the tank a tank: zero litres means zero value and
     * zero average. A drain takes the exact remaining value (never a rounded share of it), so the
     * residue cannot quietly accumulate in a column the screen never prints.
     */
    public function up(): void
    {
        Schema::create('bulk_tanks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity_litres');
            $table->unsignedBigInteger('stock_litres_x100')->default(0);
            $table->unsignedBigInteger('stock_value_base')->default(0);
            $table->unsignedBigInteger('average_cost_per_litre_minor')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'bulk_tanks_company_name_unique');
        });

        DB::statement('alter table `bulk_tanks` add constraint `bulk_tanks_capacity_positive` check (capacity_litres > 0)');
        DB::statement('alter table `bulk_tanks` add constraint `bulk_tanks_empty_is_zero` check (stock_litres_x100 > 0 or (stock_value_base = 0 and average_cost_per_litre_minor = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_tanks');
    }
};
