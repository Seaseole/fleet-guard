<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock book behind a bulk tank: every receipt, issue and physical count, with the figures
     * the tank held immediately afterwards.
     *
     * The after-snapshots are stored rather than derived, for the same reason `fuel_issues.cost_base`
     * is: a later receipt changes the average, and a movement read at report time would answer with
     * today's average for litres that left at yesterday's. The row *is* what the tank held then.
     *
     * `litres_delta_x100` and `value_delta_base` are signed — a dip can be a loss or a gain — and the
     * CHECK keeps their signs agreeing: stock never gains litres while losing value, or the reverse.
     * `source_type`/`source_id` name the purchase or issue a movement came from; a dip has none, and
     * the unique key still allows any number of dips because MySQL's unique index ignores NULLs.
     */
    public function up(): void
    {
        Schema::create('bulk_tank_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('bulk_tank_id')->constrained('bulk_tanks');

            $table->string('kind', 12);
            $table->bigInteger('litres_delta_x100');
            $table->bigInteger('value_delta_base');

            $table->unsignedBigInteger('stock_litres_x100_after');
            $table->unsignedBigInteger('stock_value_base_after');
            $table->unsignedBigInteger('average_cost_per_litre_minor_after');

            $table->date('occurred_on');
            $table->string('source_type', 40)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->string('note', 160)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'bulk_tank_id', 'occurred_on'], 'bulk_tank_movements_tank_date_index');
            $table->unique(['company_id', 'source_type', 'source_id'], 'bulk_tank_movements_source_unique');
        });

        DB::statement('alter table `bulk_tank_movements` add constraint `bulk_tank_movements_kind_allowed` check (kind in (\'receipt\', \'issue\', \'dip\'))');

        // A dip that agrees with the book is a row with two zeroes — that is the count being
        // confirmed, and it is worth keeping. A receipt or an issue that moved nothing is a bug.
        DB::statement('alter table `bulk_tank_movements` add constraint `bulk_tank_movements_moved_something` check (litres_delta_x100 <> 0 or value_delta_base <> 0 or kind = \'dip\')');
        DB::statement('alter table `bulk_tank_movements` add constraint `bulk_tank_movements_signs_agree` check ((litres_delta_x100 >= 0 and value_delta_base >= 0) or (litres_delta_x100 <= 0 and value_delta_base <= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_tank_movements');
    }
};
