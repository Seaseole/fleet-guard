<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * §4.3's `TRUCK.fuel_type` gains a vocabulary: diesel, petrol, paraffin.
     *
     * The column was a free string with a `diesel` default, so "Diesel" and "DIESEL " could sit
     * beside it and a report grouped by the value would split one product into three. Existing rows
     * are lowered *before* the CHECK is added: the constraint states the register's vocabulary, and
     * anything outside it fails the ALTER rather than being silently rewritten into a product the
     * fleet never chose.
     *
     * `length` is untouched — `string(16)` still leaves room for a longer word if the list ever
     * grows; the CHECK, not the width, is what decides.
     */
    public function up(): void
    {
        DB::table('trucks')->update(['fuel_type' => DB::raw('lower(trim(fuel_type))')]);

        DB::statement('alter table `trucks` add constraint `trucks_fuel_type_allowed` check (fuel_type in (\'diesel\', \'petrol\', \'paraffin\'))');
    }

    public function down(): void
    {
        DB::statement('alter table `trucks` drop check `trucks_fuel_type_allowed`');
    }
};
