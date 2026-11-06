<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FUEL_PURCHASE gains the fuel it bought.
     *
     * The type could not be read through the truck: a station slip names no truck, and a delivery
     * into the yard tank names no truck either, so the office screen had nowhere to state it and
     * assumed diesel for every price it prefilled. Existing rows were all recorded under that
     * assumption, so `diesel` is both the column's default and the backfill; from here the form
     * states the product and a CHECK keeps the vocabulary to the three the fleet buys, matching
     * `trucks.fuel_type`.
     */
    public function up(): void
    {
        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->string('fuel_type', 16)->default('diesel')->after('bulk_tank_id');
        });

        DB::statement('alter table `fuel_purchases` add constraint `fuel_purchases_fuel_type_allowed` check (fuel_type in (\'diesel\', \'petrol\', \'paraffin\'))');
    }

    public function down(): void
    {
        DB::statement('alter table `fuel_purchases` drop check `fuel_purchases_fuel_type_allowed`');

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->dropColumn('fuel_type');
        });
    }
};
