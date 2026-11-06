<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (Phase 5 slice 5b) — the receipt's own record of the supplier paper that funded it.
     *
     * The same seam as `fuel_purchases.bill_id`: the receipt posted `Dr 1190 / Cr 2110` at
     * the movement, so the bill documents it through `Maintenance\Actions\MarkStockReceiptBilled`
     * and never re-expenses it. A null is "awaiting the bill"; the parts register says so today and
     * this column is what lets it say so as data.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->char('bill_id', 26)->nullable()->after('recorded_by');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['company_id', 'bill_id'], 'stock_movements_company_bill_index');
        });

        DB::statement('alter table `stock_movements` add constraint `stock_movements_bill_fk` foreign key (`bill_id`) references `bills` (`id`)');
    }

    public function down(): void
    {
        DB::statement('alter table `stock_movements` drop constraint `stock_movements_bill_fk`');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_company_bill_index');
            $table->dropColumn('bill_id');
        });
    }
};
