<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 PART — the stock the stores actually holds (§15.2 phase 3, slice 3e).
     *
     * Phase 1 deliberately shipped the catalogue without these three columns, because §17 Q5 asks
     * whether stores is a staffed perpetual inventory or an issue register. Slice 3e answers it the
     * way the phase's definition of done requires — "parts stock and the ledger agree after an issue"
     * — and the answer is a valued stock book: `stock_value_base` is what the shelf is worth, and
     * `average_cost_minor` is the same moving average a bulk tank keeps, cached for display.
     *
     * Quantities are hundredths of a unit (`_x100`), exactly as a work-order line's quantity is, so a
     * half-drum of oil issues without either side rounding behind the other's back.
     *
     * The CHECK is the emptying rule: a part with none on the shelf is worth nothing, so rounding
     * residue can never survive an issue that took the last unit.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('stock_quantity_x100')->default(0)->after('reorder_level');
            $table->unsignedBigInteger('stock_value_base')->default(0)->after('stock_quantity_x100');
            $table->unsignedBigInteger('average_cost_minor')->default(0)->after('stock_value_base');
        });

        DB::statement('alter table `parts` add constraint `parts_empty_stock_is_worth_nothing` check (stock_quantity_x100 > 0 or (stock_value_base = 0 and average_cost_minor = 0))');
    }

    public function down(): void
    {
        DB::statement('alter table `parts` drop check `parts_empty_stock_is_worth_nothing`');

        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn(['stock_quantity_x100', 'stock_value_base', 'average_cost_minor']);
        });
    }
};
