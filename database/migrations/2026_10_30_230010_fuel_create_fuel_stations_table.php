<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 FUEL_STATION — a filling station the fleet buys from, as master data.
     *
     * A station is a supplier with a pump, so it carries `supplier_id` (the payable party) and the
     * facts a purchase record needs to be believable: which town and country the pump is in. The
     * supplier is optional because a cutover sheet may name "Engen Gaborone" before anybody has
     * raised the supplier row it will be billed by; the station is still a usable place to buy fuel.
     *
     * Deactivating rather than deleting is the rule, the same as every other master row: a purchase
     * written against a station must stay readable after the fleet stops using it.
     */
    public function up(): void
    {
        Schema::create('fuel_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');

            $table->string('name', 120);
            $table->string('town', 80)->nullable();
            $table->char('country_code', 2)->default('BW');
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'fuel_stations_company_name_unique');
        });

        DB::statement('alter table `fuel_stations` add constraint `fuel_stations_country_upper` check (country_code = upper(country_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_stations');
    }
};
