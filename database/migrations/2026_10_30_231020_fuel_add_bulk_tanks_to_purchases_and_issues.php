<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a delivery went: onto the slips a trip draws from, or into the yard tank (§15.2 phase 3).
     *
     * A purchase with `bulk_tank_id` is a **delivery into stock**, and the CHECK says so in the only
     * way the database can: such a row must be posted, because the diesel became an asset
     * (`Dr 1180 Bulk fuel stock / Cr 2110 Supplier payable`) the moment it entered the tank. A slip
     * bought at a pump stays unposted until Phase 5's bill, exactly as slice 3a recorded — the two
     * destinations genuinely differ, and the flag is where that difference becomes visible.
     *
     * A bulk issue names the tank it emptied and never a purchase: the stock is the tank, and naming
     * the delivery as well would let the same litres be counted twice.
     */
    public function up(): void
    {
        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->foreignId('bulk_tank_id')->nullable()->after('fuel_card_id')->constrained('bulk_tanks');
        });

        Schema::table('fuel_issues', function (Blueprint $table): void {
            $table->foreignId('bulk_tank_id')->nullable()->after('purchase_id')->constrained('bulk_tanks');
        });

        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_bulk_delivery_posted` check (bulk_tank_id is null or posted_to_ledger = true)');
        DB::statement('alter table `fuel_issues` add constraint `fuel_issues_bulk_tank_matches_source` check ((source = \'bulk_tank\' and bulk_tank_id is not null) or (source <> \'bulk_tank\' and bulk_tank_id is null))');
    }

    public function down(): void
    {
        DB::statement('alter table `fuel_issues` drop check `fuel_issues_bulk_tank_matches_source`');
        DB::statement('alter table `fuel_purchases` drop check `fuel_purchases_bulk_delivery_posted`');

        Schema::table('fuel_issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bulk_tank_id');
        });

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bulk_tank_id');
        });
    }
};
