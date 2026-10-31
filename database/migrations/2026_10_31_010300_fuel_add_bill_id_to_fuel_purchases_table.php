<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §20.36 (Phase 5 slice 5b) — the delivery's own record of the paper that documented it.
     *
     * A bulk delivery posts `Cr 2110` at the movement (§20.26); the supplier's bill for the same
     * diesel must attach to that obligation, not create a second one. The stamp lives on Fuel's row
     * and is written only through `Fuel\Actions\MarkDeliveryBilled` — Accounting never reaches into
     * another module's table (§3.1 D5); it asks, and the door answers.
     *
     * NULL means "this delivery's bill has not arrived yet", which is the same truth the register's
     * `awaiting the bill` badge has been telling since slice 3a; the column makes it a query.
     */
    public function up(): void
    {
        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->char('bill_id', 26)->nullable()->after('posted_to_ledger');
        });

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->index(['company_id', 'bill_id'], 'fuel_purchases_company_bill_index');
        });

        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_bill_fk` foreign key (`bill_id`) references `bills` (`id`)');
    }

    public function down(): void
    {
        DB::statement('alter table `fuel_purchases` drop constraint `fuel_purchases_bill_fk`');

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->dropIndex('fuel_purchases_company_bill_index');
            $table->dropColumn('bill_id');
        });
    }
};
